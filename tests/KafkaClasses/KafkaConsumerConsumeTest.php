<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\KafkaClasses;

use Anktx\Kafka\Client\Connection\BrokerHealthState;
use Anktx\Kafka\Client\ConsumeResult\KafkaConsumeTimeout;
use Anktx\Kafka\Client\ConsumeResult\KafkaPartitionEof;
use Anktx\Kafka\Client\Exception\Kafka\KafkaConsumerException;
use Anktx\Kafka\Client\Exception\Logic\NotSubscribedException;
use Anktx\Kafka\Client\KafkaConsumer;
use Anktx\Kafka\Client\KafkaMessage\KafkaConsumerMessage;
use Anktx\Kafka\Client\Tests\Support\InMemoryLogger;
use Anktx\Kafka\Client\TopicSubscription\TopicSubscriptionList;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use RdKafka\Exception as RdKafkaException;
use RdKafka\KafkaConsumer as RdKafkaConsumer;
use RdKafka\Message as RdKafkaMessage;

/**
 * Юнит-тесты для {@see KafkaConsumer::consume()} на mock'е RdKafka\KafkaConsumer.
 *
 * Регрессионное покрытие для баг-фикса subscribe(): без assign() после subscribe()
 * флаг isSubscribed выставляется корректно и consume() сразу готов читать сообщения
 * всех категорий — NO_ERROR, PARTITION_EOF, TIMED_OUT и неизвестные err-коды.
 */
final class KafkaConsumerConsumeTest extends TestCase
{
    public function testConsumeReturnsMessageForNoErrorAndMarksBrokerAvailable(): void
    {
        $brokerHealth = new BrokerHealthState();
        $brokerHealth->markUnavailable(microtime(true));

        $rdKafka = $this->createMock(RdKafkaConsumer::class);
        $rdKafka->expects($this->once())->method('consume')->willReturn(self::message([
            'err' => \RD_KAFKA_RESP_ERR_NO_ERROR,
            'topic_name' => 'test-topic',
            'partition' => 3,
            'offset' => 42,
            'payload' => 'hello',
            'key' => 'k',
            'headers' => ['h' => 'v'],
            'timestamp' => 1234,
        ]));

        $consumer = $this->buildConsumer($rdKafka, brokerHealth: $brokerHealth);
        $consumer->subscribe(TopicSubscriptionList::create('test-topic'));

        $result = $consumer->consume(100);

        self::assertInstanceOf(KafkaConsumerMessage::class, $result);
        self::assertSame('test-topic', $result->topic);
        self::assertSame('hello', $result->body);
        self::assertSame(3, $result->partition);
        self::assertSame(42, $result->offset);
        // Сообщение подтверждает восстановление соединения.
        self::assertFalse($brokerHealth->isUnavailable());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testConsumeReturnsPartitionEofAndMarksBrokerAvailable(): void
    {
        $brokerHealth = new BrokerHealthState();
        $brokerHealth->markUnavailable(microtime(true));

        $rdKafka = $this->createMock(RdKafkaConsumer::class);
        $rdKafka->method('consume')->willReturn(self::message([
            'err' => \RD_KAFKA_RESP_ERR__PARTITION_EOF,
            'topic_name' => 'test-topic',
            'partition' => 1,
            'offset' => 7,
        ]));

        $consumer = $this->buildConsumer($rdKafka, brokerHealth: $brokerHealth);
        $consumer->subscribe(TopicSubscriptionList::create('test-topic'));

        $result = $consumer->consume(100);

        self::assertInstanceOf(KafkaPartitionEof::class, $result);
        self::assertSame('test-topic', $result->topic);
        self::assertSame(1, $result->partition);
        self::assertSame(7, $result->offset);
        // EOF тоже подтверждает, что обмен с брокером работает.
        self::assertFalse($brokerHealth->isUnavailable());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testConsumeReturnsTimeoutAndDoesNotMarkBrokerAvailable(): void
    {
        // markUnavailable не вызывается в этом тесте — по умолчанию brokerHealth
        // доступен. Проверяем, что таймаут не сбрасывает его в "доступен", но
        // и не маркирует недоступным. Главное — что markAvailable не вызывается
        // (на мутации if (!$result instanceof Timeout) ↔ if ($result instanceof Timeout)).
        $brokerHealth = new BrokerHealthState();
        $brokerHealth->markUnavailable(microtime(true));

        $rdKafka = $this->createMock(RdKafkaConsumer::class);
        $rdKafka->method('consume')->willReturn(self::message([
            'err' => \RD_KAFKA_RESP_ERR__TIMED_OUT,
            'partition' => 0,
            'offset' => 0,
        ]));

        $consumer = $this->buildConsumer($rdKafka, brokerHealth: $brokerHealth);
        $consumer->subscribe(TopicSubscriptionList::create('test-topic'));

        $result = $consumer->consume(100);

        self::assertInstanceOf(KafkaConsumeTimeout::class, $result);
        // На мутации markAvailable был бы вызван и сбросил unavailable.
        // На корректном коде unavailable сохраняется.
        self::assertTrue($brokerHealth->isUnavailable());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testConsumeThrowsOnUnknownErrCodeAndDoesNotMarkBrokerAvailable(): void
    {
        $brokerHealth = new BrokerHealthState();
        $brokerHealth->markUnavailable(microtime(true));

        $rdKafka = $this->createMock(RdKafkaConsumer::class);
        $rdKafka->method('consume')->willReturn(self::message([
            'err' => \RD_KAFKA_RESP_ERR__BAD_MSG,
        ]));

        $consumer = $this->buildConsumer($rdKafka, brokerHealth: $brokerHealth);
        $consumer->subscribe(TopicSubscriptionList::create('test-topic'));

        $this->expectException(KafkaConsumerException::class);
        // errstr() для RD_KAFKA_RESP_ERR__BAD_MSG возвращает 'Local: Bad message format'.
        $this->expectExceptionMessage('Bad message format');

        try {
            $consumer->consume(100);
        } finally {
            // На default arm markAvailable не должен вызываться.
            self::assertTrue($brokerHealth->isUnavailable());
        }
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testConsumePropagatesRdKafkaException(): void
    {
        $rdKafka = $this->createMock(RdKafkaConsumer::class);
        $rdKafka->method('consume')
            ->willThrowException(new RdKafkaException('transport failure'))
        ;

        $consumer = $this->buildConsumer($rdKafka);
        $consumer->subscribe(TopicSubscriptionList::create('test-topic'));

        $this->expectException(KafkaConsumerException::class);
        $this->expectExceptionMessage('transport failure');

        $consumer->consume(100);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testConsumeWithoutSubscriptionThrows(): void
    {
        $this->expectException(NotSubscribedException::class);

        $this->buildConsumer($this->createMock(RdKafkaConsumer::class))->consume(100);
    }

    /**
     * @param array<string, mixed> $values
     */
    private static function message(array $values): RdKafkaMessage
    {
        $message = new RdKafkaMessage();
        foreach ($values as $name => $value) {
            $message->{$name} = $value;
        }

        return $message;
    }

    private function buildConsumer(
        RdKafkaConsumer $rdKafka,
        ?BrokerHealthState $brokerHealth = null,
    ): KafkaConsumer {
        $consumer = (new \ReflectionClass(KafkaConsumer::class))->newInstanceWithoutConstructor();

        (new \ReflectionProperty(KafkaConsumer::class, 'consumer'))->setValue($consumer, $rdKafka);
        (new \ReflectionProperty(KafkaConsumer::class, 'logger'))->setValue($consumer, new InMemoryLogger());
        (new \ReflectionProperty(KafkaConsumer::class, 'brokerHealth'))
            ->setValue($consumer, $brokerHealth ?? new BrokerHealthState())
        ;
        (new \ReflectionProperty(KafkaConsumer::class, 'unavailableThresholdSec'))
            ->setValue($consumer, 30)
        ;

        return $consumer;
    }
}
