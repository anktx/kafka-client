<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\KafkaClasses;

use Anktx\Kafka\Client\ConsumeResult\KafkaConsumeTimeout;
use Anktx\Kafka\Client\ConsumeResult\KafkaPartitionEof;
use Anktx\Kafka\Client\Exception\Kafka\KafkaConsumerException;
use Anktx\Kafka\Client\Exception\Logic\InvalidMessageException;
use Anktx\Kafka\Client\Exception\Logic\NotSubscribedException;
use Anktx\Kafka\Client\KafkaConsumer;
use Anktx\Kafka\Client\KafkaMessage\KafkaConsumerMessage;
use Anktx\Kafka\Client\Tests\Support\InMemoryLogger;
use Anktx\Kafka\Client\TopicSubscription\TopicSubscriptionList;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use RdKafka\Exception;
use RdKafka\Message;
use RdKafka\TopicPartition;

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
    public function testCommitWithoutOffsetThrowsInvalidMessageException(): void
    {
        // Сообщение без offset нельзя закоммитить: commit() фиксировал бы
        // фиктивное смещение (null + 1).
        $logger = new InMemoryLogger();
        $consumer = $this->buildConsumer($this->createMock(\RdKafka\KafkaConsumer::class), logger: $logger);

        $message = new KafkaConsumerMessage(topic: 'test-topic', body: 'hello');

        try {
            $consumer->commit($message);
            self::fail('Expected InvalidMessageException');
        } catch (InvalidMessageException $e) {
            self::assertSame(
                'Message from topic "test-topic" (partition -1) has no offset and cannot be committed',
                $e->getMessage(),
            );
        }

        $errorRecords = $logger->findByMessage('Attempted to commit a message without offset');
        self::assertCount(1, $errorRecords);
        self::assertSame('test-topic', $errorRecords[0]['context']['topic']);
    }

    public function testCommitCommitsNextOffset(): void
    {
        // commit() фиксирует offset + 1 — следующий за обработанным сообщением.
        $rdKafka = $this->createMock(\RdKafka\KafkaConsumer::class);
        $rdKafka->expects($this->once())->method('commit')->with([
            new TopicPartition('test-topic', 3, 43),
        ]);

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
        $rdKafka = $this->createMock(\RdKafka\KafkaConsumer::class);
        $rdKafka->method('commit')
            ->willThrowException(new Exception('commit failed'))
        ;

        $consumer = $this->buildConsumer($rdKafka);

        $this->expectException(KafkaConsumerException::class);
        $this->expectExceptionMessage('commit failed');

        $consumer->commit(new KafkaConsumerMessage(
            topic: 'test-topic',
            body: 'hello',
            partition: 3,
            offset: 42,
        ));
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
