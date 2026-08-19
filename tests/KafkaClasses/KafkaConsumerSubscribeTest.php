<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\KafkaClasses;

use Anktx\Kafka\Client\Config\ConsumerConfig;
use Anktx\Kafka\Client\ConsumeResult\KafkaConsumeTimeout;
use Anktx\Kafka\Client\Exception\Business\EmptySubscriptionsException;
use Anktx\Kafka\Client\Exception\Kafka\KafkaConsumerException;
use Anktx\Kafka\Client\Exception\Logic\NotSubscribedException;
use Anktx\Kafka\Client\KafkaConsumer;
use Anktx\Kafka\Client\Tests\Support\InMemoryLogger;
use Anktx\Kafka\Client\TopicSubscription\TopicSubscription;
use Anktx\Kafka\Client\TopicSubscription\TopicSubscriptionList;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use RdKafka\Exception;
use RdKafka\Message;

/**
 * Юнит-тесты для {@see KafkaConsumer::subscribe()} на mock'е RdKafka\KafkaConsumer.
 *
 * Регрессия для бага: в subscribe() сразу после consumer->subscribe() вызывался
 * consumer->assign(committedOffsets), что переключало consumer в manual mode и
 * затирало partition-назначения, выставленные внутренним rebalance-callback'ом
 * librdkafka. Тесты фиксируют контракт: subscribe() вызывает ровно один
 * RdKafka\KafkaConsumer::subscribe() и ноль раз assign(), корректно пишет
 * контекст в лог, а подписка видна в RdKafka::getSubscription().
 *
 * Также фиксируется контракт отложенного подключения: конструктор и
 * subscribe() не выполняют сетевых вызовов — подключение к брокерам
 * librdkafka выполняет асинхронно в фоновых потоках.
 */
final class KafkaConsumerSubscribeTest extends TestCase
{
    public function testConstructorDoesNotProbeBrokersAndNeverThrows(): void
    {
        // Конструктор — неблокирующая операция, безопасная для DI-резолва:
        // никаких getMetadata()/probe брокеров до первого consume().
        $logger = new InMemoryLogger();

        $consumer = new KafkaConsumer(
            new ConsumerConfig(
                brokers: 'localhost:1',
                groupId: 'contract-test',
            ),
            $logger,
        );

        self::assertInstanceOf(KafkaConsumer::class, $consumer);

        // Единственный observable-эффект конструктора — info-лог с конфигурацией.
        $createdRecords = $logger->findByMessage('KafkaConsumer created');
        self::assertCount(1, $createdRecords);
        self::assertSame([
            'brokers' => 'localhost:1',
            'group_id' => 'contract-test',
            'instance_id' => null,
            'offset_reset' => 'earliest',
            'auto_commit_ms' => null,
            'session_timeout_ms' => null,
        ], $createdRecords[0]['context']);

        $consumer->close();
    }

    public function testSubscribeCallsRdKafkaSubscribeOnceAndNeverAssigns(): void
    {
        $rdKafka = $this->createMock(\RdKafka\KafkaConsumer::class);
        $rdKafka->expects($this->once())->method('subscribe')->with(['test-topic']);
        $rdKafka->expects($this->never())->method('assign');

        $this->buildConsumer($rdKafka)->subscribe(TopicSubscriptionList::create('test-topic'));
    }

    public function testSubscribeDeduplicatesTopicNames(): void
    {
        // Один и тот же топик может попасть в список несколько раз —
        // RdKafka::subscribe() должен получить только уникальные имена.
        $list = new TopicSubscriptionList(
            new TopicSubscription('test-topic'),
            new TopicSubscription('test-topic'),
            new TopicSubscription('notifications'),
        );

        $rdKafka = $this->createMock(\RdKafka\KafkaConsumer::class);
        $rdKafka->expects($this->once())->method('subscribe')->with(['test-topic', 'notifications']);
        $rdKafka->expects($this->never())->method('assign');

        $this->buildConsumer($rdKafka)->subscribe($list);
    }

    public function testSubscribeWritesInfoLogContextAndEnablesConsumption(): void
    {
        // Интеграция трёх контрактов subscribe(): (1) ровно один RdKafka::subscribe(),
        // (2) info-лог содержит topics + subscriptions_count, (3) подписка видна в
        // RdKafka::getSubscription() — иначе consume() бросит NotSubscribedException.
        $logger = new InMemoryLogger();

        $rdKafka = $this->createMock(\RdKafka\KafkaConsumer::class);
        $rdKafka->expects($this->once())->method('subscribe')->with(['test-topic']);
        $rdKafka->method('getSubscription')->willReturn(['test-topic']);
        $rdKafka->expects($this->never())->method('assign');

        $timeoutMessage = new Message();
        $timeoutMessage->err = \RD_KAFKA_RESP_ERR__TIMED_OUT;
        $timeoutMessage->partition = 0;
        $timeoutMessage->offset = 0;
        $rdKafka->method('consume')->willReturn($timeoutMessage);

        $consumer = $this->buildConsumer($rdKafka, $logger);

        $consumer->subscribe(TopicSubscriptionList::create('test-topic'));

        // Если подписка не зарегистрировалась в librdkafka, здесь прилетит
        // NotSubscribedException.
        $result = $consumer->consume(100);
        self::assertInstanceOf(KafkaConsumeTimeout::class, $result);

        $infoRecords = $logger->findByMessage('Subscribed to topics');
        self::assertCount(1, $infoRecords);
        self::assertSame(['test-topic'], $infoRecords[0]['context']['topics']);
        self::assertSame(1, $infoRecords[0]['context']['subscriptions_count']);
    }

    public function testSubscribeLogsErrorContextAndRethrowsOnRdKafkaException(): void
    {
        $logger = new InMemoryLogger();

        $rdKafka = $this->createMock(\RdKafka\KafkaConsumer::class);
        $rdKafka->method('subscribe')
            ->willThrowException(new Exception('broker down'))
        ;
        $rdKafka->expects($this->never())->method('assign');

        $list = TopicSubscriptionList::create('test-topic', 'notifications');

        $consumer = $this->buildConsumer($rdKafka, $logger);

        try {
            $consumer->subscribe($list);
            self::fail('Expected KafkaConsumerException');
        } catch (KafkaConsumerException $e) {
            self::assertSame('broker down', $e->getMessage());
        }

        $errorRecords = $logger->findByMessage('Failed to subscribe to topics');
        self::assertCount(1, $errorRecords);
        self::assertSame(['test-topic', 'notifications'], $errorRecords[0]['context']['topics']);
        self::assertSame('broker down', $errorRecords[0]['context']['error']);
    }

    public function testSubscribeWithEmptyListThrowsBeforeAnyConsumerCallAndLogsNothing(): void
    {
        $logger = new InMemoryLogger();

        $rdKafka = $this->createMock(\RdKafka\KafkaConsumer::class);
        $rdKafka->expects($this->never())->method('subscribe');
        $rdKafka->expects($this->never())->method('assign');

        $this->expectException(EmptySubscriptionsException::class);

        try {
            $this->buildConsumer($rdKafka, $logger)->subscribe(new TopicSubscriptionList());
        } finally {
            self::assertSame([], $logger->records);
        }
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testConsumeBeforeSubscribeThrowsNotSubscribed(): void
    {
        $this->expectException(NotSubscribedException::class);

        $this->buildConsumer($this->createMock(\RdKafka\KafkaConsumer::class))->consume(100);
    }

    /**
     * Собирает KafkaConsumer без вызова конструктора (чтобы избежать реального
     * подключения к брокерам) и инжектит mock RdKafka\KafkaConsumer в приватный
     * readonly-параметр.
     */
    private function buildConsumer(\RdKafka\KafkaConsumer $rdKafka, ?InMemoryLogger $logger = null): KafkaConsumer
    {
        $consumer = (new \ReflectionClass(KafkaConsumer::class))->newInstanceWithoutConstructor();

        $this->setProp($consumer, 'consumer', $rdKafka);
        $this->setProp($consumer, 'logger', $logger ?? new InMemoryLogger());

        return $consumer;
    }

    private function setProp(KafkaConsumer $consumer, string $name, mixed $value): void
    {
        (new \ReflectionProperty(KafkaConsumer::class, $name))->setValue($consumer, $value);
    }
}
