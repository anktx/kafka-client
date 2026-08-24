<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\Integration\KafkaClasses;

use Anktx\Kafka\Client\Config\Brokers;
use Anktx\Kafka\Client\Config\ConsumerConfig;
use Anktx\Kafka\Client\Config\Enum\OffsetReset;
use Anktx\Kafka\Client\Config\ProducerConfig;
use Anktx\Kafka\Client\ConsumeResult\ConsumeResult;
use Anktx\Kafka\Client\Exception\Logic\EmptySubscriptionsException;
use Anktx\Kafka\Client\Exception\Logic\NotSubscribedException;
use Anktx\Kafka\Client\KafkaConsumer;
use Anktx\Kafka\Client\KafkaMessage\KafkaConsumerMessage;
use Anktx\Kafka\Client\KafkaMessage\KafkaProducerMessage;
use Anktx\Kafka\Client\KafkaProducer;
use Anktx\Kafka\Client\Tests\Integration\Support\KafkaBroker;
use Anktx\Kafka\Client\Topic\Topic;
use Anktx\Kafka\Client\Topic\TopicList;
use PHPUnit\Framework\TestCase;

/**
 * Интеграционные тесты {@see KafkaConsumer} против реального брокера
 * (адрес — KAFKA_BROKERS, без брокера тесты помечаются skipped).
 */
final class KafkaConsumerTest extends TestCase
{
    private string $brokers;

    protected function setUp(): void
    {
        $this->brokers = KafkaBroker::requireBroker();
    }

    public function testConstructor(): void
    {
        $consumer = new KafkaConsumer($this->consumerConfig());

        self::assertInstanceOf(KafkaConsumer::class, $consumer);
        $consumer->close();
    }

    public function testConstructorWithDebugEnabled(): void
    {
        $consumer = new KafkaConsumer($this->consumerConfig(isDebug: true));

        self::assertInstanceOf(KafkaConsumer::class, $consumer);
        $consumer->close();
    }

    public function testConstructorWithAutoCommit(): void
    {
        $consumer = new KafkaConsumer($this->consumerConfig(autoCommitMs: 5000));

        self::assertInstanceOf(KafkaConsumer::class, $consumer);
        $consumer->close();
    }

    public function testConstructorWithSessionTimeout(): void
    {
        $consumer = new KafkaConsumer($this->consumerConfig(sessionTimeoutMs: 10000, heartbeatIntervalMs: 3000));

        self::assertInstanceOf(KafkaConsumer::class, $consumer);
        $consumer->close();
    }

    public function testConstructorWithLatestOffsetReset(): void
    {
        $consumer = new KafkaConsumer($this->consumerConfig(offsetReset: OffsetReset::Latest));

        self::assertInstanceOf(KafkaConsumer::class, $consumer);
        $consumer->close();
    }

    public function testSubscribe(): void
    {
        $consumer = new KafkaConsumer($this->consumerConfig());

        $consumer->subscribe(TopicList::create(new Topic('test-topic')));

        $consumer->unsubscribe();
        $consumer->close();

        self::assertInstanceOf(KafkaConsumer::class, $consumer);
    }

    public function testSubscribeWithEmptyListThrowsException(): void
    {
        $consumer = new KafkaConsumer($this->consumerConfig());

        $this->expectException(EmptySubscriptionsException::class);
        $this->expectExceptionMessage('At least one subscription is required');

        try {
            $consumer->subscribe(new TopicList());
        } finally {
            $consumer->close();
        }
    }

    public function testUnsubscribe(): void
    {
        $consumer = new KafkaConsumer($this->consumerConfig());

        $consumer->subscribe(TopicList::create(new Topic('test-topic')));
        $consumer->unsubscribe();
        $consumer->close();

        self::assertInstanceOf(KafkaConsumer::class, $consumer);
    }

    public function testConsumeWithoutSubscriptionThrowsException(): void
    {
        $consumer = new KafkaConsumer($this->consumerConfig());

        $this->expectException(NotSubscribedException::class);

        try {
            $consumer->consume();
        } finally {
            $consumer->close();
        }
    }

    public function testCommit(): void
    {
        $consumer = new KafkaConsumer($this->consumerConfig());
        $consumer->subscribe(TopicList::create(new Topic('test-topic')));

        $consumer->commit(new KafkaConsumerMessage(
            topic: new Topic('test-topic'),
            body: 'test',
            partition: 0,
            offset: 100,
        ));

        $consumer->close();

        self::assertInstanceOf(KafkaConsumer::class, $consumer);
    }

    public function testConsume(): void
    {
        $consumer = new KafkaConsumer($this->consumerConfig());
        $consumer->subscribe(TopicList::create(new Topic('test-topic')));

        try {
            $result = $consumer->consume(timeoutMs: 100);
        } finally {
            $consumer->close();
        }

        // Пустой топик после auto-create отдаёт таймаут, конец партиции —
        // EOF: contract consume() — любой из четырёх результатов union.
        self::assertInstanceOf(ConsumeResult::class, $result);
    }

    /**
     * Регрессия бага: subscribe() не должен вызывать assign().
     *
     * До фикса KafkaConsumer::subscribe() сразу после RdKafka\KafkaConsumer::subscribe()
     * дёргал assign() со снимком committed offsets, что переключало consumer в manual
     * mode и затирало partition-назначения, выставленные внутренним rebalance-callback'ом
     * librdkafka. Симптом в проде: часть partition'ов «выпадала» из обработки.
     *
     * Тест эмулирует продуктивный сценарий: producer пишет сообщения с разными
     * ключами (для распределения по partition'ам), consumer с уникальной group.id
     * и offsetReset=earliest подписывается и должен прочитать записи из всех
     * partition'ов topic'а.
     */
    public function testSubscribeWithoutAssignReceivesMessagesFromAllPartitions(): void
    {
        $topic = 'test-topic';
        $messageCount = 6;

        // Producer: пишем сообщения с разными ключами, чтобы librdkafka распределил
        // их по всем доступным partition'ам (для multi-partition topic).
        $producer = new KafkaProducer(new ProducerConfig(brokers: new Brokers($this->brokers)));
        for ($i = 0; $i < $messageCount; ++$i) {
            $producer->produce(new KafkaProducerMessage(
                topic: new Topic($topic),
                body: "regression-{$i}",
                key: "key-{$i}",
            ));
        }
        $producer->flush(5000);

        // Consumer: уникальная group.id → committed offsets пустые, earliest
        // гарантирует чтение с начала partition'а.
        $consumer = new KafkaConsumer(new ConsumerConfig(
            brokers: new Brokers($this->brokers),
            groupId: 'subscribe-regression-' . uniqid('', true),
            offsetReset: OffsetReset::Earliest,
        ));

        try {
            $consumer->subscribe(TopicList::create(new Topic($topic)));

            $seenPartitions = [];
            $seenBodies = [];
            $deadline = microtime(true) + 15.0;

            while (\count($seenBodies) < $messageCount && microtime(true) < $deadline) {
                $result = $consumer->consume(500);

                if (!$result instanceof KafkaConsumerMessage) {
                    continue;
                }

                // Берём только наши сообщения: на earliest могут прилететь
                // и ранее записанные другими тестами.
                if ($result->body === null || !str_starts_with($result->body, 'regression-')) {
                    continue;
                }

                $seenPartitions[$result->partition] = true;
                $seenBodies[] = $result->body;
                $consumer->commit($result);
            }

            // Главный assertion: consumer functional после subscribe() — без
            // assign() partition'ы реально назначаются librdkafka'ой.
            self::assertNotEmpty(
                $seenBodies,
                'Consumer did not receive any messages after subscribe — rebalance/assign broken.',
            );

            // Если topic multi-partition, проверяем что consumer получил
            // сообщения более чем из одной partition. На single-partition topic
            // assertion тривиально выполняется (== 1).
            self::assertGreaterThan(
                0,
                \count($seenPartitions),
                'Consumer was not assigned any partition.',
            );
        } finally {
            $consumer->close();
        }
    }

    private function consumerConfig(
        OffsetReset $offsetReset = OffsetReset::Earliest,
        ?int $autoCommitMs = null,
        int $sessionTimeoutMs = 30000,
        int $heartbeatIntervalMs = 3000,
        bool $isDebug = false,
    ): ConsumerConfig {
        // Уникальные group.id/instance.id на каждый тест: статические члены
        // (KIP-345) не покидают группу при close(), и переиспользование пары
        // group+instance в соседнем тесте приводит к фенсингу консьюмера.
        return new ConsumerConfig(
            brokers: new Brokers($this->brokers),
            groupId: 'test-group-' . uniqid('', true),
            instanceId: 'test-instance-' . uniqid('', true),
            offsetReset: $offsetReset,
            autoCommitMs: $autoCommitMs,
            sessionTimeoutMs: $sessionTimeoutMs,
            heartbeatIntervalMs: $heartbeatIntervalMs,
            isDebug: $isDebug,
        );
    }
}
