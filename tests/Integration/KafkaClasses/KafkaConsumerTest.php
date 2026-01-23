<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\Kafka;

use Anktx\Kafka\Client\Config\ConsumerConfig;
use Anktx\Kafka\Client\Config\Enum\OffsetReset;
use Anktx\Kafka\Client\ConsumeResult\KafkaConsumeTimeout;
use Anktx\Kafka\Client\ConsumeResult\KafkaPartitionEof;
use Anktx\Kafka\Client\Exception\Business\EmptySubscriptionsException;
use Anktx\Kafka\Client\Exception\Kafka\KafkaConsumerException;
use Anktx\Kafka\Client\Exception\Logic\NotSubscribedException;
use Anktx\Kafka\Client\KafkaConsumer;
use Anktx\Kafka\Client\KafkaMessage\KafkaConsumerMessage;
use Anktx\Kafka\Client\TopicSubscription\TopicSubscription;
use Anktx\Kafka\Client\TopicSubscription\TopicSubscriptionList;
use PHPUnit\Framework\TestCase;

final class KafkaConsumerTest extends TestCase
{
    public function testConstructor(): void
    {
        $config = new ConsumerConfig(
            brokers: 'localhost:9092',
            groupId: 'test-group',
            instanceId: 'test-instance',
        );

        $consumer = new KafkaConsumer($config);

        $this->assertInstanceOf(KafkaConsumer::class, $consumer);
        $consumer->close();
    }

    public function testSubscribe(): void
    {
        $config = new ConsumerConfig(
            brokers: 'localhost:9092',
            groupId: 'test-group',
            instanceId: 'test-instance',
        );

        $consumer = new KafkaConsumer($config);
        $subscriptionList = TopicSubscriptionList::create('test-topic');

        $consumer->subscribe($subscriptionList);

        $consumer->unsubscribe();
        $consumer->close();

        // Test passes if no exception is thrown
    }

    public function testSubscribeWithEmptyListThrowsException(): void
    {
        $config = new ConsumerConfig(
            brokers: 'localhost:9092',
            groupId: 'test-group',
            instanceId: 'test-instance',
        );

        $consumer = new KafkaConsumer($config);
        $emptyList = new TopicSubscriptionList();

        $this->expectException(EmptySubscriptionsException::class);
        $this->expectExceptionMessage('At least one subscription is required');

        try {
            $consumer->subscribe($emptyList);
        } finally {
            $consumer->close();
        }
    }

    public function testUnsubscribe(): void
    {
        $config = new ConsumerConfig(
            brokers: 'localhost:9092',
            groupId: 'test-group',
            instanceId: 'test-instance',
        );

        $consumer = new KafkaConsumer($config);
        $subscriptionList = TopicSubscriptionList::create('test-topic');

        $consumer->subscribe($subscriptionList);
        $consumer->unsubscribe();
        $consumer->close();

        // Test passes if no exception is thrown
    }

    public function testConsumeWithoutSubscriptionThrowsException(): void
    {
        $config = new ConsumerConfig(
            brokers: 'localhost:9092',
            groupId: 'test-group',
            instanceId: 'test-instance',
        );

        $consumer = new KafkaConsumer($config);

        $this->expectException(NotSubscribedException::class);

        try {
            $consumer->consume();
        } finally {
            $consumer->close();
        }
    }

    public function testCommit(): void
    {
        $config = new ConsumerConfig(
            brokers: 'localhost:9092',
            groupId: 'test-group',
            instanceId: 'test-instance',
        );

        $consumer = new KafkaConsumer($config);
        $subscriptionList = TopicSubscriptionList::create('test-topic');

        $consumer->subscribe($subscriptionList);

        $message = new KafkaConsumerMessage(
            topic: 'test-topic',
            body: 'test',
            partition: 0,
            offset: 100,
        );

        $consumer->commit($message);

        $consumer->close();

        // Test passes if no exception is thrown
    }

    public function testConsumeMatch(): void
    {
        $config = new ConsumerConfig(
            brokers: 'localhost:9092',
            groupId: 'test-group',
            instanceId: 'test-instance',
        );

        $consumer = new KafkaConsumer($config);
        $subscriptionList = TopicSubscriptionList::create('test-topic');

        $consumer->subscribe($subscriptionList);

        $messageCalled = false;
        $timeoutCalled = false;
        $eofCalled = false;

        try {
            $consumer->consumeMatch(
                onMessage: static function (KafkaConsumerMessage $msg) use (&$messageCalled) {
                    $messageCalled = true;
                },
                onTimeout: static function (KafkaConsumeTimeout $timeout) use (&$timeoutCalled) {
                    $timeoutCalled = true;
                },
                onEof: static function (KafkaPartitionEof $eof) use (&$eofCalled) {
                    $eofCalled = true;
                },
                timeoutMs: 100,
            );
        } catch (KafkaConsumerException $e) {
            // Ошибки могут возникать при чтении из несуществующего топика
        }

        $consumer->close();

        // Проверяем, что один из callback'ов был вызван
        $this->assertTrue($messageCalled || $timeoutCalled || $eofCalled);
    }

    public function testConstructorWithCustomTimeout(): void
    {
        $config = new ConsumerConfig(
            brokers: 'localhost:9092',
            groupId: 'test-group',
            instanceId: 'test-instance',
        );

        $consumer = new KafkaConsumer($config, 10000);

        $this->assertInstanceOf(KafkaConsumer::class, $consumer);
        $consumer->close();
    }

    public function testConstructorWithDebugEnabled(): void
    {
        $config = new ConsumerConfig(
            brokers: 'localhost:9092',
            groupId: 'test-group',
            instanceId: 'test-instance',
            isDebug: true,
        );

        $consumer = new KafkaConsumer($config);

        $this->assertInstanceOf(KafkaConsumer::class, $consumer);
        $consumer->close();
    }

    public function testConstructorWithAutoCommit(): void
    {
        $config = new ConsumerConfig(
            brokers: 'localhost:9092',
            groupId: 'test-group',
            instanceId: 'test-instance',
            autoCommitMs: 5000,
        );

        $consumer = new KafkaConsumer($config);

        $this->assertInstanceOf(KafkaConsumer::class, $consumer);
        $consumer->close();
    }

    public function testConstructorWithSessionTimeout(): void
    {
        $config = new ConsumerConfig(
            brokers: 'localhost:9092',
            groupId: 'test-group',
            instanceId: 'test-instance',
            sessionTimeoutMs: 10000,
        );

        $consumer = new KafkaConsumer($config);

        $this->assertInstanceOf(KafkaConsumer::class, $consumer);
        $consumer->close();
    }

    public function testConstructorWithLatestOffsetReset(): void
    {
        $config = new ConsumerConfig(
            brokers: 'localhost:9092',
            groupId: 'test-group',
            instanceId: 'test-instance',
            offsetReset: OffsetReset::latest,
        );

        $consumer = new KafkaConsumer($config);

        $this->assertInstanceOf(KafkaConsumer::class, $consumer);
        $consumer->close();
    }

    public function testClose(): void
    {
        $config = new ConsumerConfig(
            brokers: 'localhost:9092',
            groupId: 'test-group',
            instanceId: 'test-instance',
        );

        $consumer = new KafkaConsumer($config);

        $consumer->close();

        // Test passes if no exception is thrown
    }

    public function testSubscribeWithPartition(): void
    {
        $config = new ConsumerConfig(
            brokers: 'localhost:9092',
            groupId: 'test-group',
            instanceId: 'test-instance',
        );

        $consumer = new KafkaConsumer($config);
        $subscriptionList = new TopicSubscriptionList(
            new TopicSubscription('test-topic', 0),
        );

        $consumer->subscribe($subscriptionList);

        $consumer->unsubscribe();
        $consumer->close();

        // Test passes if no exception is thrown
    }
}
