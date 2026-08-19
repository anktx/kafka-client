<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\KafkaClasses;

use Anktx\Kafka\Client\ConsumeResult\KafkaConsumeTimeout;
use Anktx\Kafka\Client\ConsumeResult\KafkaPartitionEof;
use Anktx\Kafka\Client\Exception\Kafka\KafkaConsumerException;
use Anktx\Kafka\Client\Exception\Logic\NotSubscribedException;
use Anktx\Kafka\Client\KafkaConsumer;
use Anktx\Kafka\Client\KafkaMessage\KafkaConsumerMessage;
use Anktx\Kafka\Client\Tests\Support\InMemoryLogger;
use Anktx\Kafka\Client\TopicSubscription\TopicSubscriptionList;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RdKafka\Exception;
use RdKafka\Message;

/**
 * Юнит-тесты для {@see KafkaConsumer::consume()} на mock'е RdKafka\KafkaConsumer.
 *
 * Покрывают все ветки match: NO_ERROR, PARTITION_EOF, TIMED_OUT,
 * ALL_BROKERS_DOWN (как таймаут) и default (бросает исключение).
 * Регрессионный сценарий самовосстановления: consume() продолжает работать
 * после временной потери связи с брокером без перезапуска процесса.
 */
final class KafkaConsumerConsumeTest extends TestCase
{
    public function testConsumeReturnsMessageForNoError(): void
    {
        $rdKafka = $this->createMock(\RdKafka\KafkaConsumer::class);
        $rdKafka->method('getSubscription')->willReturn(['test-topic']);
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

        $consumer = $this->buildConsumer($rdKafka);
        $consumer->subscribe(TopicSubscriptionList::create('test-topic'));

        $result = $consumer->consume(100);

        self::assertInstanceOf(KafkaConsumerMessage::class, $result);
        self::assertSame('test-topic', $result->topic);
        self::assertSame('hello', $result->body);
        self::assertSame(3, $result->partition);
        self::assertSame(42, $result->offset);
        self::assertSame('k', $result->key);
        self::assertSame(['h' => 'v'], $result->headers);
        self::assertSame(1234, $result->timestampMs);
    }

    #[DataProvider('provideConsumeNormalizesUnknownTimestampToNullCases')]
    #[AllowMockObjectsWithoutExpectations]
    public function testConsumeNormalizesUnknownTimestampToNull(?int $brokerTimestamp, ?string $payload): void
    {
        $rdKafka = $this->createMock(\RdKafka\KafkaConsumer::class);
        $rdKafka->method('getSubscription')->willReturn(['test-topic']);
        $rdKafka->method('consume')->willReturn(self::message([
            'err' => \RD_KAFKA_RESP_ERR_NO_ERROR,
            'topic_name' => 'test-topic',
            'partition' => 3,
            'offset' => 42,
            'payload' => $payload,
            'headers' => [],
            'timestamp' => $brokerTimestamp,
        ]));

        $consumer = $this->buildConsumer($rdKafka);
        $consumer->subscribe(TopicSubscriptionList::create('test-topic'));

        $result = $consumer->consume(100);

        self::assertInstanceOf(KafkaConsumerMessage::class, $result);
        self::assertNull($result->timestampMs);
    }

    /**
     * @return iterable<string, array{null|int, null|string}>
     */
    public static function provideConsumeNormalizesUnknownTimestampToNullCases(): iterable
    {
        yield 'sentinel -1: broker did not provide timestamp' => [-1, 'hello'];

        yield 'null: ext-rdkafka leaves timestamp unset for null payload' => [null, null];
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testConsumeReturnsPartitionEof(): void
    {
        $rdKafka = $this->createMock(\RdKafka\KafkaConsumer::class);
        $rdKafka->method('getSubscription')->willReturn(['test-topic']);
        $rdKafka->method('consume')->willReturn(self::message([
            'err' => \RD_KAFKA_RESP_ERR__PARTITION_EOF,
            'topic_name' => 'test-topic',
            'partition' => 1,
            'offset' => 7,
        ]));

        $consumer = $this->buildConsumer($rdKafka);
        $consumer->subscribe(TopicSubscriptionList::create('test-topic'));

        $result = $consumer->consume(100);

        self::assertInstanceOf(KafkaPartitionEof::class, $result);
        self::assertSame('test-topic', $result->topic);
        self::assertSame(1, $result->partition);
        self::assertSame(7, $result->offset);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testConsumeReturnsTimeout(): void
    {
        $rdKafka = $this->createMock(\RdKafka\KafkaConsumer::class);
        $rdKafka->method('getSubscription')->willReturn(['test-topic']);
        $rdKafka->method('consume')->willReturn(self::message([
            'err' => \RD_KAFKA_RESP_ERR__TIMED_OUT,
            'partition' => 0,
            'offset' => 0,
        ]));

        $consumer = $this->buildConsumer($rdKafka);
        $consumer->subscribe(TopicSubscriptionList::create('test-topic'));

        $result = $consumer->consume(100);

        self::assertInstanceOf(KafkaConsumeTimeout::class, $result);
    }

    public function testConsumeReturnsTimeoutForAllBrokersDownAndDoesNotThrow(): void
    {
        // При полной потере связи librdkafka возвращает RD_KAFKA_RESP_ERR__ALL_BROKERS_DOWN.
        // Раньше это доходило до default arm match и бросало KafkaConsumerException.
        // Теперь обрабатывается как таймаут, позволяя циклу потребления продолжаться
        // и давая librdkafka прокачивать rebalance-протокол (JoinGroup/SyncGroup).
        $rdKafka = $this->createMock(\RdKafka\KafkaConsumer::class);
        $rdKafka->method('getSubscription')->willReturn(['test-topic']);
        $rdKafka->expects($this->once())->method('consume')->willReturn(self::message([
            'err' => \RD_KAFKA_RESP_ERR__ALL_BROKERS_DOWN,
            'partition' => -1,
            'offset' => -1,
        ]));

        $consumer = $this->buildConsumer($rdKafka);
        $consumer->subscribe(TopicSubscriptionList::create('test-topic'));

        $result = $consumer->consume(100);

        self::assertInstanceOf(KafkaConsumeTimeout::class, $result);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testConsumeSelfHealsAfterBrokerRecovery(): void
    {
        // Ключевой сценарий самовосстановления:
        // 1. Брокер недоступен → consume() возвращает ALL_BROKERS_DOWN (как таймаут)
        // 2. Брокер восстановился → consume() возвращает сообщение
        // Процесс продолжает работать без перезапуска.
        $allBrokersDownMessage = self::message([
            'err' => \RD_KAFKA_RESP_ERR__ALL_BROKERS_DOWN,
            'partition' => -1,
            'offset' => -1,
        ]);
        $recoveredMessage = self::message([
            'err' => \RD_KAFKA_RESP_ERR_NO_ERROR,
            'topic_name' => 'test-topic',
            'partition' => 2,
            'offset' => 10,
            'payload' => 'recovered',
            'key' => null,
            'headers' => [],
            'timestamp' => 9999,
        ]);

        $rdKafka = $this->createMock(\RdKafka\KafkaConsumer::class);
        $rdKafka->method('getSubscription')->willReturn(['test-topic']);
        $rdKafka->method('consume')
            ->willReturnOnConsecutiveCalls($allBrokersDownMessage, $recoveredMessage)
        ;

        $consumer = $this->buildConsumer($rdKafka);
        $consumer->subscribe(TopicSubscriptionList::create('test-topic'));

        // Первая итерация: брокер недоступен — consume() работает, не бросает.
        $result1 = $consumer->consume(100);
        self::assertInstanceOf(KafkaConsumeTimeout::class, $result1);

        // Вторая итерация: брокер восстановился — сообщение получено.
        $result2 = $consumer->consume(100);
        self::assertInstanceOf(KafkaConsumerMessage::class, $result2);
        self::assertSame('recovered', $result2->body);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testConsumeThrowsOnUnknownErrCode(): void
    {
        $logger = new InMemoryLogger();

        $rdKafka = $this->createMock(\RdKafka\KafkaConsumer::class);
        $rdKafka->method('getSubscription')->willReturn(['test-topic']);
        $rdKafka->method('consume')->willReturn(self::message([
            'err' => \RD_KAFKA_RESP_ERR__BAD_MSG,
        ]));

        $consumer = $this->buildConsumer($rdKafka, logger: $logger);
        $consumer->subscribe(TopicSubscriptionList::create('test-topic'));

        try {
            $consumer->consume(100);
            self::fail('Expected KafkaConsumerException');
        } catch (KafkaConsumerException $e) {
            // errstr() для RD_KAFKA_RESP_ERR__BAD_MSG возвращает 'Local: Bad message format'.
            self::assertStringContainsString('Bad message format', $e->getMessage());
            self::assertSame(\RD_KAFKA_RESP_ERR__BAD_MSG, $e->getCode());
        }

        $errorRecords = $logger->findByMessage('Consume failed with unrecognized error');
        self::assertCount(1, $errorRecords);
        self::assertSame(\RD_KAFKA_RESP_ERR__BAD_MSG, $errorRecords[0]['context']['error_code']);
        self::assertStringContainsString('Bad message format', $errorRecords[0]['context']['error']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testConsumePropagatesRdKafkaExceptionAndLogsContext(): void
    {
        $logger = new InMemoryLogger();

        $rdKafka = $this->createMock(\RdKafka\KafkaConsumer::class);
        $rdKafka->method('getSubscription')->willReturn(['test-topic']);
        $rdKafka->method('consume')
            ->willThrowException(new Exception('transport failure'))
        ;

        $consumer = $this->buildConsumer($rdKafka, logger: $logger);
        $consumer->subscribe(TopicSubscriptionList::create('test-topic'));

        try {
            $consumer->consume(100);
            self::fail('Expected KafkaConsumerException');
        } catch (KafkaConsumerException $e) {
            self::assertSame('transport failure', $e->getMessage());
        }

        $errorRecords = $logger->findByMessage('Failed to consume message');
        self::assertCount(1, $errorRecords);
        self::assertSame(100, $errorRecords[0]['context']['timeout_ms']);
        self::assertSame('transport failure', $errorRecords[0]['context']['error']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testConsumeWithoutSubscriptionThrows(): void
    {
        $this->expectException(NotSubscribedException::class);

        $this->buildConsumer($this->createMock(\RdKafka\KafkaConsumer::class))->consume(100);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testConsumeWrapsGetSubscriptionFailure(): void
    {
        // getSubscription() вызывается до try-блока consume() и раньше
        // не был защищён: сбой ext-rdkafka утекал как сырое RdKafka\Exception.
        $logger = new InMemoryLogger();

        $rdKafka = $this->createMock(\RdKafka\KafkaConsumer::class);
        $rdKafka->method('getSubscription')
            ->willThrowException(new Exception('subscription state unavailable'))
        ;

        $consumer = $this->buildConsumer($rdKafka, logger: $logger);

        try {
            $consumer->consume(100);
            self::fail('Expected KafkaConsumerException');
        } catch (KafkaConsumerException $e) {
            self::assertSame('subscription state unavailable', $e->getMessage());
        }

        $errorRecords = $logger->findByMessage('Failed to get subscription state');
        self::assertCount(1, $errorRecords);
        self::assertSame('subscription state unavailable', $errorRecords[0]['context']['error']);
    }

    public function testCommitCommitsNextOffset(): void
    {
        // commit() фиксирует offset + 1 — следующий за обработанным сообщением.
        // RdKafka\TopicPartition — C-объект без PHP-свойств, == для него всегда
        // true, поэтому значения проверяются геттерами внутри колбэка.
        $rdKafka = $this->createMock(\RdKafka\KafkaConsumer::class);
        $rdKafka->expects($this->once())->method('commit')->willReturnCallback(
            static function (array $offsets): void {
                self::assertCount(1, $offsets);
                self::assertSame('test-topic', $offsets[0]->getTopic());
                self::assertSame(3, $offsets[0]->getPartition());
                self::assertSame(43, $offsets[0]->getOffset());
            },
        );

        $consumer = $this->buildConsumer($rdKafka);
        $consumer->commit(new KafkaConsumerMessage(
            topic: 'test-topic',
            body: 'hello',
            partition: 3,
            offset: 42,
        ));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testCommitRethrowsRdKafkaExceptionAsKafkaConsumerException(): void
    {
        $logger = new InMemoryLogger();

        $rdKafka = $this->createMock(\RdKafka\KafkaConsumer::class);
        $rdKafka->method('commit')
            ->willThrowException(new Exception('commit failed'))
        ;

        $consumer = $this->buildConsumer($rdKafka, logger: $logger);

        try {
            $consumer->commit(new KafkaConsumerMessage(
                topic: 'test-topic',
                body: 'hello',
                partition: 3,
                offset: 42,
            ));
            self::fail('Expected KafkaConsumerException');
        } catch (KafkaConsumerException $e) {
            self::assertSame('commit failed', $e->getMessage());
        }

        $errorRecords = $logger->findByMessage('Failed to commit message');
        self::assertCount(1, $errorRecords);
        self::assertSame('test-topic', $errorRecords[0]['context']['topic']);
        self::assertSame(3, $errorRecords[0]['context']['partition']);
        self::assertSame(42, $errorRecords[0]['context']['offset']);
        self::assertSame('commit failed', $errorRecords[0]['context']['error']);
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
