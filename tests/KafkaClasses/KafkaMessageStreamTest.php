<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\KafkaClasses;

use Anktx\Kafka\Client\ConsumeResult\ConsumeResult;
use Anktx\Kafka\Client\ConsumeResult\KafkaBrokersDown;
use Anktx\Kafka\Client\ConsumeResult\KafkaConsumeTimeout;
use Anktx\Kafka\Client\ConsumeResult\KafkaPartitionEof;
use Anktx\Kafka\Client\Exception\Kafka\KafkaConsumerException;
use Anktx\Kafka\Client\Exception\Logic\ClientClosedException;
use Anktx\Kafka\Client\Exception\Logic\InvalidConfigException;
use Anktx\Kafka\Client\Exception\Logic\NotSubscribedException;
use Anktx\Kafka\Client\KafkaConsumer;
use Anktx\Kafka\Client\KafkaMessage\KafkaConsumerMessage;
use Anktx\Kafka\Client\KafkaMessageStream;
use Anktx\Kafka\Client\StreamObserver\StreamObserver;
use Anktx\Kafka\Client\Tests\Support\KafkaConsumers;
use Anktx\Kafka\Client\Tests\Support\RdKafkaMessages;
use Anktx\Kafka\Client\Tests\Support\SpyStreamObserver;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use RdKafka\Exception;

/**
 * Юнит-тесты для {@see KafkaMessageStream::stream()}. KafkaConsumer — final,
 * замокать его нельзя, поэтому поток тестируется end-to-end на mock'е
 * RdKafka\KafkaConsumer — ровно та цепочка stream() → consume(), что раньше
 * была закрыта только integration-тестами.
 *
 * Фиксируется контракт: с молчаливым наблюдателем по умолчанию таймауты,
 * потеря брокеров и EOF не выдаются наружу (poll продолжается), сообщения
 * yield'ятся с последовательными int-ключами, таймаут опроса
 * пробрасывается в consume(), исключения консьюмера пробрасываются из
 * генератора при первой итерации, закрытый консьюмер отвергается
 * ClientClosedException до единого вызова RdKafka. Кастомный наблюдатель
 * получает каждый результат соответствующим хуком до yield, а его
 * исключение прерывает генератор.
 */
final class KafkaMessageStreamTest extends TestCase
{
    #[AllowMockObjectsWithoutExpectations]
    public function testConstructorRejectsNegativePollTimeout(): void
    {
        try {
            new KafkaMessageStream(KafkaConsumers::build($this->createMock(\RdKafka\KafkaConsumer::class)), -1);
            self::fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            self::assertSame('Config parameter "pollTimeoutMs" must not be negative, -1 given', $e->getMessage());
        }
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testConstructorAllowsZeroPollTimeout(): void
    {
        // Граница валидации: 0 — легитимный неблокирующий опрос consume(0).
        $stream = new KafkaMessageStream(
            KafkaConsumers::build($this->createMock(\RdKafka\KafkaConsumer::class)),
            0,
        );

        self::assertInstanceOf(KafkaMessageStream::class, $stream);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDispatchThrowsOnUnknownConsumeResultImplementation(): void
    {
        // Дрейф-защита: результат consume() вне известного union — типизированный
        // отказ вместо \UnhandledMatchError из match без default. Через публичный
        // API недостижимо (KafkaConsumer::consume() возвращает только union), —
        // поэтому диспетчер вызывается напрямую.
        $unexpected = new class implements ConsumeResult {};

        $stream = new KafkaMessageStream(
            KafkaConsumers::build($this->createMock(\RdKafka\KafkaConsumer::class)),
        );

        try {
            (new \ReflectionMethod(KafkaMessageStream::class, 'dispatchToObserver'))->invoke($stream, $unexpected);
            self::fail('Expected KafkaConsumerException');
        } catch (KafkaConsumerException $e) {
            self::assertSame(
                'Unexpected ConsumeResult implementation: ' . $unexpected::class,
                $e->getMessage(),
            );
        }
    }

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
                RdKafkaMessages::fromValues([
                    'err' => \RD_KAFKA_RESP_ERR__TIMED_OUT,
                    'partition' => 0,
                    'offset' => 0,
                ]),
                RdKafkaMessages::fromValues([
                    'err' => \RD_KAFKA_RESP_ERR__ALL_BROKERS_DOWN,
                    'partition' => -1,
                    'offset' => -1,
                ]),
                RdKafkaMessages::fromValues([
                    'err' => \RD_KAFKA_RESP_ERR__PARTITION_EOF,
                    'topic_name' => 'test-topic',
                    'partition' => 1,
                    'offset' => 7,
                ]),
                RdKafkaMessages::fromValues([
                    'err' => \RD_KAFKA_RESP_ERR_NO_ERROR,
                    'topic_name' => 'test-topic',
                    'partition' => 2,
                    'offset' => 10,
                    'payload' => 'first',
                    'key' => null,
                    'headers' => [],
                    'timestamp' => 111,
                ]),
                RdKafkaMessages::fromValues([
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

        $stream = new KafkaMessageStream(KafkaConsumers::build($rdKafka), 500);
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
                RdKafkaMessages::fromValues([
                    'err' => \RD_KAFKA_RESP_ERR__PARTITION_EOF,
                    'topic_name' => 'test-topic',
                    'partition' => 1,
                    'offset' => 7,
                ]),
                RdKafkaMessages::fromValues([
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

        $generator = (new KafkaMessageStream(KafkaConsumers::build($rdKafka), 250))->stream();

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
                RdKafkaMessages::fromValues([
                    'err' => \RD_KAFKA_RESP_ERR__PARTITION_EOF,
                    'topic_name' => 'test-topic',
                    'partition' => 1,
                    'offset' => 7,
                ]),
                RdKafkaMessages::fromValues([
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

        $generator = (new KafkaMessageStream(KafkaConsumers::build($rdKafka)))->stream();

        self::assertSame('default', $generator->current()->body);
    }

    public function testStreamDispatchesEveryResultToObserverHooks(): void
    {
        // Тот же микс результатов, что и в тесте фильтрации: каждый уходит
        // в свой хук наблюдателя, сообщения — теми же экземплярами, что
        // выданы генератором. Порядок хуков — до yield.
        $rdKafka = $this->createMock(\RdKafka\KafkaConsumer::class);
        $rdKafka->method('getSubscription')->willReturn(['test-topic']);
        $rdKafka->expects($this->exactly(5))
            ->method('consume')
            ->willReturnOnConsecutiveCalls(
                RdKafkaMessages::fromValues([
                    'err' => \RD_KAFKA_RESP_ERR__TIMED_OUT,
                    'partition' => 0,
                    'offset' => 0,
                ]),
                RdKafkaMessages::fromValues([
                    'err' => \RD_KAFKA_RESP_ERR__ALL_BROKERS_DOWN,
                    'partition' => -1,
                    'offset' => -1,
                ]),
                RdKafkaMessages::fromValues([
                    'err' => \RD_KAFKA_RESP_ERR__PARTITION_EOF,
                    'topic_name' => 'test-topic',
                    'partition' => 1,
                    'offset' => 7,
                ]),
                RdKafkaMessages::fromValues([
                    'err' => \RD_KAFKA_RESP_ERR_NO_ERROR,
                    'topic_name' => 'test-topic',
                    'partition' => 2,
                    'offset' => 10,
                    'payload' => 'first',
                    'key' => null,
                    'headers' => [],
                    'timestamp' => 111,
                ]),
                RdKafkaMessages::fromValues([
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

        $spy = new SpyStreamObserver();
        $generator = (new KafkaMessageStream(KafkaConsumers::build($rdKafka), 500, $spy))->stream();

        $first = $generator->current();
        $generator->next();
        $second = $generator->current();

        self::assertCount(2, $spy->messages);
        self::assertSame($first, $spy->messages[0]);
        self::assertSame($second, $spy->messages[1]);
        self::assertCount(1, $spy->timeouts);
        self::assertInstanceOf(KafkaConsumeTimeout::class, $spy->timeouts[0]);
        self::assertCount(1, $spy->brokersDown);
        self::assertInstanceOf(KafkaBrokersDown::class, $spy->brokersDown[0]);
        self::assertCount(1, $spy->eofs);
        self::assertInstanceOf(KafkaPartitionEof::class, $spy->eofs[0]);
    }

    public function testStreamIsInterruptedByObserverException(): void
    {
        // Исключение из хука прерывает генератор: второй результат
        // (сообщение) уже не потребляется, итератор мёртв.
        $rdKafka = $this->createMock(\RdKafka\KafkaConsumer::class);
        $rdKafka->method('getSubscription')->willReturn(['test-topic']);
        $rdKafka->expects($this->once())
            ->method('consume')
            ->willReturn(RdKafkaMessages::fromValues([
                'err' => \RD_KAFKA_RESP_ERR__ALL_BROKERS_DOWN,
                'partition' => -1,
                'offset' => -1,
            ]))
        ;

        $observer = new class implements StreamObserver {
            public function onMessage(KafkaConsumerMessage $message): void {}

            public function onTimeout(KafkaConsumeTimeout $timeout): void {}

            public function onEof(KafkaPartitionEof $eof): void {}

            public function onBrokersDown(KafkaBrokersDown $brokersDown): void
            {
                throw new \RuntimeException('observer decided to stop');
            }
        };

        $generator = (new KafkaMessageStream(KafkaConsumers::build($rdKafka), 100, $observer))->stream();

        try {
            $generator->current();
            self::fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            self::assertSame('observer decided to stop', $e->getMessage());
        }

        self::assertFalse($generator->valid());
    }

    public function testStreamThrowsNotSubscribedWithoutSubscription(): void
    {
        $rdKafka = $this->createMock(\RdKafka\KafkaConsumer::class);
        $rdKafka->method('getSubscription')->willReturn([]);
        $rdKafka->expects($this->never())->method('consume');

        $generator = (new KafkaMessageStream(KafkaConsumers::build($rdKafka)))->stream();

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

        $generator = (new KafkaMessageStream(KafkaConsumers::build($rdKafka), 100))->stream();

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

        $consumer = KafkaConsumers::build($rdKafka);
        $consumer->close();

        $generator = (new KafkaMessageStream($consumer))->stream();

        try {
            $generator->current();
            self::fail('Expected ClientClosedException');
        } catch (ClientClosedException) {
        }
    }
}
