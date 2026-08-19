<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\KafkaClasses;

use Anktx\Kafka\Client\Exception\Kafka\KafkaConsumerException;
use Anktx\Kafka\Client\Exception\Logic\ClientClosedException;
use Anktx\Kafka\Client\Exception\Logic\NotSubscribedException;
use Anktx\Kafka\Client\KafkaConsumer;
use Anktx\Kafka\Client\KafkaMessage\KafkaConsumerMessage;
use Anktx\Kafka\Client\KafkaMessageStream;
use Anktx\Kafka\Client\Tests\Support\InMemoryLogger;
use PHPUnit\Framework\TestCase;
use RdKafka\Exception;
use RdKafka\Message;

/**
 * Юнит-тесты для {@see KafkaMessageStream::stream()}. KafkaConsumer — final,
 * замокать его нельзя, поэтому поток тестируется end-to-end на mock'е
 * RdKafka\KafkaConsumer — ровно та цепочка stream() → consumeMatch() →
 * consume(), что раньше была закрыта только integration-тестами.
 *
 * Фиксируется контракт: таймауты, потеря брокеров и EOF не выдаются
 * наружу (poll продолжается), сообщения yield'ятся с последовательными
 * int-ключами, таймаут опроса пробрасывается в consume(), исключения
 * консьюмера пробрасываются из генератора при первой итерации, закрытый
 * консьюмер отвергается ClientClosedException до единого вызова RdKafka.
 */
final class KafkaMessageStreamTest extends TestCase
{
    public function testStreamYieldsOnlyMessagesSkippingTimeoutBrokersDownAndEof(): void
    {
        $rdKafka = $this->createMock(\RdKafka\KafkaConsumer::class);
        $rdKafka->method('getSubscription')->willReturn(['test-topic']);
        // 3 служебных результата (timeout, ALL_BROKERS_DOWN, EOF) между
        // сообщениями: poll продолжается, и на два выданных сообщения
        // приходится 5 consume(). Потеря брокеров фильтруется как таймаут.
        $rdKafka->expects($this->exactly(5))
            ->method('consume')
            ->willReturnOnConsecutiveCalls(
                self::message([
                    'err' => \RD_KAFKA_RESP_ERR__TIMED_OUT,
                    'partition' => 0,
                    'offset' => 0,
                ]),
                self::message([
                    'err' => \RD_KAFKA_RESP_ERR__ALL_BROKERS_DOWN,
                    'partition' => -1,
                    'offset' => -1,
                ]),
                self::message([
                    'err' => \RD_KAFKA_RESP_ERR__PARTITION_EOF,
                    'topic_name' => 'test-topic',
                    'partition' => 1,
                    'offset' => 7,
                ]),
                self::message([
                    'err' => \RD_KAFKA_RESP_ERR_NO_ERROR,
                    'topic_name' => 'test-topic',
                    'partition' => 2,
                    'offset' => 10,
                    'payload' => 'first',
                    'key' => null,
                    'headers' => [],
                    'timestamp' => 111,
                ]),
                self::message([
                    'err' => \RD_KAFKA_RESP_ERR_NO_ERROR,
                    'topic_name' => 'test-topic',
                    'partition' => 2,
                    'offset' => 11,
                    'payload' => 'second',
                    'key' => null,
                    'headers' => [],
                    'timestamp' => 222,
                ]),
            )
        ;

        $stream = new KafkaMessageStream($this->buildConsumer($rdKafka), 500);
        $generator = $stream->stream();

        self::assertInstanceOf(\Generator::class, $generator);

        $first = $generator->current();
        self::assertInstanceOf(KafkaConsumerMessage::class, $first);
        self::assertSame('first', $first->body);
        self::assertSame(0, $generator->key());

        $generator->next();

        $second = $generator->current();
        self::assertInstanceOf(KafkaConsumerMessage::class, $second);
        self::assertSame('second', $second->body);
        self::assertSame(1, $generator->key());
    }

    public function testStreamUsesConfiguredPollTimeout(): void
    {
        $rdKafka = $this->createMock(\RdKafka\KafkaConsumer::class);
        $rdKafka->method('getSubscription')->willReturn(['test-topic']);
        $rdKafka->expects($this->exactly(2))
            ->method('consume')
            ->with(250)
            ->willReturnOnConsecutiveCalls(
                self::message([
                    'err' => \RD_KAFKA_RESP_ERR__PARTITION_EOF,
                    'topic_name' => 'test-topic',
                    'partition' => 1,
                    'offset' => 7,
                ]),
                self::message([
                    'err' => \RD_KAFKA_RESP_ERR_NO_ERROR,
                    'topic_name' => 'test-topic',
                    'partition' => 2,
                    'offset' => 10,
                    'payload' => 'delivered',
                    'key' => null,
                    'headers' => [],
                    'timestamp' => 333,
                ]),
            )
        ;

        $generator = (new KafkaMessageStream($this->buildConsumer($rdKafka), 250))->stream();

        self::assertSame('delivered', $generator->current()->body);
    }

    public function testStreamUsesDefaultPollTimeoutWhenOmitted(): void
    {
        $rdKafka = $this->createMock(\RdKafka\KafkaConsumer::class);
        $rdKafka->method('getSubscription')->willReturn(['test-topic']);
        // KafkaMessageStream::DEFAULT_POLL_TIMEOUT_MS = 1000: литерал
        // пинсует дефолт конструктора.
        $rdKafka->expects($this->exactly(2))
            ->method('consume')
            ->with(1000)
            ->willReturnOnConsecutiveCalls(
                self::message([
                    'err' => \RD_KAFKA_RESP_ERR__PARTITION_EOF,
                    'topic_name' => 'test-topic',
                    'partition' => 1,
                    'offset' => 7,
                ]),
                self::message([
                    'err' => \RD_KAFKA_RESP_ERR_NO_ERROR,
                    'topic_name' => 'test-topic',
                    'partition' => 2,
                    'offset' => 10,
                    'payload' => 'default',
                    'key' => null,
                    'headers' => [],
                    'timestamp' => 444,
                ]),
            )
        ;

        $generator = (new KafkaMessageStream($this->buildConsumer($rdKafka)))->stream();

        self::assertSame('default', $generator->current()->body);
    }

    public function testStreamThrowsNotSubscribedWithoutSubscription(): void
    {
        $rdKafka = $this->createMock(\RdKafka\KafkaConsumer::class);
        $rdKafka->method('getSubscription')->willReturn([]);
        $rdKafka->expects($this->never())->method('consume');

        $generator = (new KafkaMessageStream($this->buildConsumer($rdKafka)))->stream();

        try {
            $generator->current();
            self::fail('Expected NotSubscribedException');
        } catch (NotSubscribedException) {
        }
    }

    public function testStreamPropagatesConsumeErrorsFromGenerator(): void
    {
        $rdKafka = $this->createMock(\RdKafka\KafkaConsumer::class);
        $rdKafka->method('getSubscription')->willReturn(['test-topic']);
        $rdKafka->expects($this->once())
            ->method('consume')
            ->willThrowException(new Exception('transport failure'))
        ;

        $generator = (new KafkaMessageStream($this->buildConsumer($rdKafka), 100))->stream();

        try {
            $generator->current();
            self::fail('Expected KafkaConsumerException');
        } catch (KafkaConsumerException $e) {
            self::assertSame('transport failure', $e->getMessage());
        }
    }

    public function testStreamThrowsClientClosedFromClosedConsumer(): void
    {
        // stream() декларирует ClientClosedException: guard закрытого
        // состояния срабатывает до единого вызова RdKafka.
        $rdKafka = $this->createMock(\RdKafka\KafkaConsumer::class);
        $rdKafka->expects($this->once())->method('close');
        $rdKafka->expects($this->never())->method('getSubscription');
        $rdKafka->expects($this->never())->method('consume');

        $consumer = $this->buildConsumer($rdKafka);
        $consumer->close();

        $generator = (new KafkaMessageStream($consumer))->stream();

        try {
            $generator->current();
            self::fail('Expected ClientClosedException');
        } catch (ClientClosedException) {
        }
    }

    /**
     * @param array<string, mixed> $values
     */
    private static function message(array $values): Message
    {
        $message = new Message();
        foreach ($values as $name => $value) {
            // @phpstan-ignore property.dynamicName
            $message->{$name} = $value;
        }

        return $message;
    }

    /**
     * Собирает KafkaConsumer без вызова конструктора (чтобы избежать реального
     * подключения к брокерам) и инжектит mock RdKafka\KafkaConsumer в приватные
     * свойства.
     */
    private function buildConsumer(
        \RdKafka\KafkaConsumer $rdKafka,
        ?InMemoryLogger $logger = null,
    ): KafkaConsumer {
        $consumer = (new \ReflectionClass(KafkaConsumer::class))->newInstanceWithoutConstructor();

        (new \ReflectionProperty(KafkaConsumer::class, 'consumer'))->setValue($consumer, $rdKafka);
        (new \ReflectionProperty(KafkaConsumer::class, 'logger'))->setValue($consumer, $logger ?? new InMemoryLogger());

        return $consumer;
    }
}
