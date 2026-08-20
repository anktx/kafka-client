<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\KafkaClasses;

use Anktx\Kafka\Client\ConsumeResult\KafkaBrokersDown;
use Anktx\Kafka\Client\ConsumeResult\KafkaConsumeTimeout;
use Anktx\Kafka\Client\ConsumeResult\KafkaPartitionEof;
use Anktx\Kafka\Client\Exception\Kafka\KafkaConsumerException;
use Anktx\Kafka\Client\Exception\Logic\InvalidConfigException;
use Anktx\Kafka\Client\Exception\Logic\InvalidTopicException;
use Anktx\Kafka\Client\Exception\Logic\NotSubscribedException;
use Anktx\Kafka\Client\KafkaConsumer;
use Anktx\Kafka\Client\KafkaMessage\KafkaConsumerMessage;
use Anktx\Kafka\Client\Tests\Support\InMemoryLogger;
use Anktx\Kafka\Client\Tests\Support\KafkaConsumers;
use Anktx\Kafka\Client\Tests\Support\RdKafkaMessages;
use Anktx\Kafka\Client\Topic\Topic;
use Anktx\Kafka\Client\Topic\TopicList;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RdKafka\Exception;

/**
 * Юнит-тесты для {@see KafkaConsumer::consume()} на mock'е RdKafka\KafkaConsumer.
 *
 * Покрывают все ветки match: NO_ERROR, PARTITION_EOF, TIMED_OUT,
 * ALL_BROKERS_DOWN (отдельный результат KafkaBrokersDown) и default
 * (бросает исключение). Регрессионный сценарий самовосстановления:
 * consume() продолжает работать после временной потери связи с брокером
 * без перезапуска процесса.
 */
final class KafkaConsumerConsumeTest extends TestCase
{
    public function testConsumeReturnsMessageForNoError(): void
    {
        $rdKafka = $this->createMock(\RdKafka\KafkaConsumer::class);
        $rdKafka->method('getSubscription')->willReturn(['test-topic']);
        $rdKafka->expects($this->once())->method('consume')->willReturn(RdKafkaMessages::fromValues([
            'err' => \RD_KAFKA_RESP_ERR_NO_ERROR,
            'topic_name' => 'test-topic',
            'partition' => 3,
            'offset' => 42,
            'payload' => 'hello',
            'key' => 'k',
            'headers' => ['h' => 'v', 'n' => 42],
            'timestamp' => 1234,
        ]));

        $consumer = KafkaConsumers::build($rdKafka);
        $consumer->subscribe(TopicList::create(new Topic('test-topic')));

        $result = $consumer->consume(100);

        self::assertInstanceOf(KafkaConsumerMessage::class, $result);
        self::assertSame('test-topic', $result->topic->name);
        self::assertSame('hello', $result->body);
        self::assertSame(3, $result->partition);
        self::assertSame(42, $result->offset);
        self::assertSame('k', $result->key);
        self::assertSame(['h' => 'v', 'n' => 42], $result->headers);
        self::assertSame(1234, $result->timestampMs);
    }

    public function testConsumeRejectsNegativeTimeout(): void
    {
        $rdKafka = $this->createMock(\RdKafka\KafkaConsumer::class);
        $rdKafka->expects($this->never())->method('getSubscription');
        $rdKafka->expects($this->never())->method('consume');

        $consumer = KafkaConsumers::build($rdKafka);
        $consumer->subscribe(TopicList::create(new Topic('test-topic')));

        try {
            $consumer->consume(-1);
            self::fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            self::assertSame('Config parameter "timeoutMs" must not be negative, -1 given', $e->getMessage());
        }
    }

    public function testConsumeAllowsZeroNonBlockingTimeout(): void
    {
        // Граница валидации: 0 — легитимный неблокирующий опрос очереди.
        $rdKafka = $this->createMock(\RdKafka\KafkaConsumer::class);
        $rdKafka->method('getSubscription')->willReturn(['test-topic']);
        $rdKafka->expects($this->once())->method('consume')->with(0)->willReturn(RdKafkaMessages::fromValues([
            'err' => \RD_KAFKA_RESP_ERR__TIMED_OUT,
            'partition' => 0,
            'offset' => 0,
        ]));

        $consumer = KafkaConsumers::build($rdKafka);
        $consumer->subscribe(TopicList::create(new Topic('test-topic')));

        self::assertInstanceOf(KafkaConsumeTimeout::class, $consumer->consume(0));
    }

    public function testConsumeUsesDefaultTimeoutWhenOmitted(): void
    {
        // KafkaConsumer::DEFAULT_CONSUME_TIMEOUT_MS = 1000: литерал пинсует
        // дефолт параметра consume() (передаётся в RdKafka без вычислений —
        // точный with() детерминирован).
        $rdKafka = $this->createMock(\RdKafka\KafkaConsumer::class);
        $rdKafka->method('getSubscription')->willReturn(['test-topic']);
        $rdKafka->expects($this->once())->method('consume')->with(1000)->willReturn(RdKafkaMessages::fromValues([
            'err' => \RD_KAFKA_RESP_ERR__TIMED_OUT,
            'partition' => 0,
            'offset' => 0,
        ]));

        $consumer = KafkaConsumers::build($rdKafka);
        $consumer->subscribe(TopicList::create(new Topic('test-topic')));

        self::assertInstanceOf(KafkaConsumeTimeout::class, $consumer->consume());
    }

    #[DataProvider('provideConsumeNormalizesUnknownTimestampToNullCases')]
    #[AllowMockObjectsWithoutExpectations]
    public function testConsumeNormalizesUnknownTimestampToNull(?int $brokerTimestamp, ?string $payload): void
    {
        $rdKafka = $this->createMock(\RdKafka\KafkaConsumer::class);
        $rdKafka->method('getSubscription')->willReturn(['test-topic']);
        $rdKafka->method('consume')->willReturn(RdKafkaMessages::fromValues([
            'err' => \RD_KAFKA_RESP_ERR_NO_ERROR,
            'topic_name' => 'test-topic',
            'partition' => 3,
            'offset' => 42,
            'payload' => $payload,
            'headers' => [],
            'timestamp' => $brokerTimestamp,
        ]));

        $consumer = KafkaConsumers::build($rdKafka);
        $consumer->subscribe(TopicList::create(new Topic('test-topic')));

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
        $rdKafka->method('consume')->willReturn(RdKafkaMessages::fromValues([
            'err' => \RD_KAFKA_RESP_ERR__PARTITION_EOF,
            'topic_name' => 'test-topic',
            'partition' => 1,
            'offset' => 7,
        ]));

        $consumer = KafkaConsumers::build($rdKafka);
        $consumer->subscribe(TopicList::create(new Topic('test-topic')));

        $result = $consumer->consume(100);

        self::assertInstanceOf(KafkaPartitionEof::class, $result);
        self::assertSame('test-topic', $result->topic->name);
        self::assertSame(1, $result->partition);
        self::assertSame(7, $result->offset);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testConsumeRejectsDeliveredMessageWithoutTopic(): void
    {
        // topic_name нативно nullable; для NO_ERROR-сообщения без топика
        // нормализация null → '' доходит до инварианта Topic и даёт
        // осмысленное исключение библиотеки вместо TypeError.
        $this->expectException(InvalidTopicException::class);

        $rdKafka = $this->createMock(\RdKafka\KafkaConsumer::class);
        $rdKafka->method('getSubscription')->willReturn(['test-topic']);
        $rdKafka->method('consume')->willReturn(RdKafkaMessages::fromValues([
            'err' => \RD_KAFKA_RESP_ERR_NO_ERROR,
            'topic_name' => null,
            'partition' => 3,
            'offset' => 42,
            'headers' => [],
        ]));

        $consumer = KafkaConsumers::build($rdKafka);
        $consumer->subscribe(TopicList::create(new Topic('test-topic')));

        $consumer->consume(100);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testConsumeRejectsPartitionEofWithoutTopic(): void
    {
        // EOF всегда относится к конкретной партиции, topic_name у него
        // ext-rdkafka заполняет всегда; для гипотетического null
        // нормализация '' отвергается инвариантом Topic.
        $this->expectException(InvalidTopicException::class);

        $rdKafka = $this->createMock(\RdKafka\KafkaConsumer::class);
        $rdKafka->method('getSubscription')->willReturn(['test-topic']);
        $rdKafka->method('consume')->willReturn(RdKafkaMessages::fromValues([
            'err' => \RD_KAFKA_RESP_ERR__PARTITION_EOF,
            'topic_name' => null,
            'partition' => 1,
            'offset' => 7,
        ]));

        $consumer = KafkaConsumers::build($rdKafka);
        $consumer->subscribe(TopicList::create(new Topic('test-topic')));

        $consumer->consume(100);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testConsumeReturnsTimeout(): void
    {
        $rdKafka = $this->createMock(\RdKafka\KafkaConsumer::class);
        $rdKafka->method('getSubscription')->willReturn(['test-topic']);
        $rdKafka->method('consume')->willReturn(RdKafkaMessages::fromValues([
            'err' => \RD_KAFKA_RESP_ERR__TIMED_OUT,
            'partition' => 0,
            'offset' => 0,
        ]));

        $consumer = KafkaConsumers::build($rdKafka);
        $consumer->subscribe(TopicList::create(new Topic('test-topic')));

        $result = $consumer->consume(100);

        self::assertInstanceOf(KafkaConsumeTimeout::class, $result);
    }

    public function testConsumeReturnsBrokersDownForAllBrokersDownAndDoesNotThrow(): void
    {
        // При полной потере связи librdkafka возвращает RD_KAFKA_RESP_ERR__ALL_BROKERS_DOWN.
        // Раньше это доходило до default arm match и бросало KafkaConsumerException,
        // затем маскировалось под таймаут. Теперь — отдельный результат: наблюдаем
        // для метрик/watchdog'а, но не бросает и позволяет циклу потребления
        // продолжаться и librdkafka прокачивать rebalance-протокол (JoinGroup/SyncGroup).
        $rdKafka = $this->createMock(\RdKafka\KafkaConsumer::class);
        $rdKafka->method('getSubscription')->willReturn(['test-topic']);
        $rdKafka->expects($this->once())->method('consume')->willReturn(RdKafkaMessages::fromValues([
            'err' => \RD_KAFKA_RESP_ERR__ALL_BROKERS_DOWN,
            'partition' => -1,
            'offset' => -1,
        ]));

        $consumer = KafkaConsumers::build($rdKafka);
        $consumer->subscribe(TopicList::create(new Topic('test-topic')));

        $result = $consumer->consume(100);

        self::assertInstanceOf(KafkaBrokersDown::class, $result);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testConsumeSelfHealsAfterBrokerRecovery(): void
    {
        // Ключевой сценарий самовосстановления:
        // 1. Брокер недоступен → consume() возвращает KafkaBrokersDown
        // 2. Брокер восстановился → consume() возвращает сообщение
        // Процесс продолжает работать без перезапуска.
        $allBrokersDownMessage = RdKafkaMessages::fromValues([
            'err' => \RD_KAFKA_RESP_ERR__ALL_BROKERS_DOWN,
            'partition' => -1,
            'offset' => -1,
        ]);
        $recoveredMessage = RdKafkaMessages::fromValues([
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

        $consumer = KafkaConsumers::build($rdKafka);
        $consumer->subscribe(TopicList::create(new Topic('test-topic')));

        // Первая итерация: брокер недоступен — consume() работает, не бросает.
        $result1 = $consumer->consume(100);
        self::assertInstanceOf(KafkaBrokersDown::class, $result1);

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
        $rdKafka->method('consume')->willReturn($double = RdKafkaMessages::fromValues([
            'err' => \RD_KAFKA_RESP_ERR__BAD_MSG,
            'topic_name' => 'test-topic',
            'partition' => 3,
            'offset' => 42,
        ]));

        $consumer = KafkaConsumers::build($rdKafka, $logger);
        $consumer->subscribe(TopicList::create(new Topic('test-topic')));

        try {
            $consumer->consume(100);
            self::fail('Expected KafkaConsumerException');
        } catch (KafkaConsumerException $e) {
            // Позиция ошибки (топик/партиция/смещение) — и в лог, и в
            // сообщение исключения: код без позиции не разобрать.
            // errstr() для RD_KAFKA_RESP_ERR__BAD_MSG возвращает 'Local: Bad message format'.
            self::assertSame(
                \sprintf(
                    'Consume failed: %s (error %d, topic "%s", partition %d, offset %d)',
                    $double->errstr(),
                    \RD_KAFKA_RESP_ERR__BAD_MSG,
                    'test-topic',
                    3,
                    42,
                ),
                $e->getMessage(),
            );
            self::assertSame(\RD_KAFKA_RESP_ERR__BAD_MSG, $e->getCode());
        }

        $errorRecords = $logger->findByMessage('Consume failed with unrecognized error');
        self::assertCount(1, $errorRecords);
        self::assertSame(\RD_KAFKA_RESP_ERR__BAD_MSG, $errorRecords[0]['context']['error_code']);
        self::assertIsString($errorRecords[0]['context']['reason']);
        self::assertStringContainsString('Bad message format', $errorRecords[0]['context']['reason']);
        self::assertSame('test-topic', $errorRecords[0]['context']['topic']);
        self::assertSame(3, $errorRecords[0]['context']['partition']);
        self::assertSame(42, $errorRecords[0]['context']['offset']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testConsumePropagatesRdKafkaExceptionAndLogsContext(): void
    {
        $logger = new InMemoryLogger();

        $rdKafka = $this->createMock(\RdKafka\KafkaConsumer::class);
        $rdKafka->method('getSubscription')->willReturn(['test-topic']);
        $rdKafka->method('consume')
            ->willThrowException($failure = new Exception('transport failure'))
        ;

        $consumer = KafkaConsumers::build($rdKafka, $logger);
        $consumer->subscribe(TopicList::create(new Topic('test-topic')));

        try {
            $consumer->consume(100);
            self::fail('Expected KafkaConsumerException');
        } catch (KafkaConsumerException $e) {
            self::assertSame('transport failure', $e->getMessage());
        }

        $errorRecords = $logger->findByMessage('Failed to consume message');
        self::assertCount(1, $errorRecords);
        self::assertSame(100, $errorRecords[0]['context']['timeout_ms']);
        self::assertSame('transport failure', $errorRecords[0]['context']['reason']);
        self::assertSame($failure, $errorRecords[0]['context']['exception']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testConsumeWithoutSubscriptionThrows(): void
    {
        $this->expectException(NotSubscribedException::class);

        KafkaConsumers::build($this->createMock(\RdKafka\KafkaConsumer::class))->consume(100);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testConsumeWrapsGetSubscriptionFailure(): void
    {
        // getSubscription() вызывается до try-блока consume() и раньше
        // не был защищён: сбой ext-rdkafka утекал как сырое RdKafka\Exception.
        $logger = new InMemoryLogger();

        $rdKafka = $this->createMock(\RdKafka\KafkaConsumer::class);
        $rdKafka->method('getSubscription')
            ->willThrowException($failure = new Exception('subscription state unavailable'))
        ;

        $consumer = KafkaConsumers::build($rdKafka, $logger);

        try {
            $consumer->consume(100);
            self::fail('Expected KafkaConsumerException');
        } catch (KafkaConsumerException $e) {
            self::assertSame('subscription state unavailable', $e->getMessage());
        }

        $errorRecords = $logger->findByMessage('Failed to get subscription state');
        self::assertCount(1, $errorRecords);
        self::assertSame('subscription state unavailable', $errorRecords[0]['context']['reason']);
        self::assertSame($failure, $errorRecords[0]['context']['exception']);
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

        $consumer = KafkaConsumers::build($rdKafka);
        $consumer->commit(new KafkaConsumerMessage(
            topic: new Topic('test-topic'),
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
            ->willThrowException($failure = new Exception('commit failed', 7))
        ;

        $consumer = KafkaConsumers::build($rdKafka, $logger);

        try {
            $consumer->commit(new KafkaConsumerMessage(
                topic: new Topic('test-topic'),
                body: 'hello',
                partition: 3,
                offset: 42,
            ));
            self::fail('Expected KafkaConsumerException');
        } catch (KafkaConsumerException $e) {
            // Позиция коммита — в сообщение исключения, а не только в лог.
            self::assertSame(
                'Failed to commit offset 42 for topic "test-topic" partition 3: commit failed',
                $e->getMessage(),
            );
            self::assertSame(7, $e->getCode());
            self::assertSame($failure, $e->getPrevious());
        }

        $errorRecords = $logger->findByMessage('Failed to commit message');
        self::assertCount(1, $errorRecords);
        self::assertSame('test-topic', $errorRecords[0]['context']['topic']);
        self::assertSame(3, $errorRecords[0]['context']['partition']);
        self::assertSame(42, $errorRecords[0]['context']['offset']);
        self::assertSame('commit failed', $errorRecords[0]['context']['reason']);
        self::assertSame($failure, $errorRecords[0]['context']['exception']);
    }
}
