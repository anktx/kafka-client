<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\Subscription;

use Anktx\Kafka\Client\Exception\Business\TopicHasNoPartitionException;
use Anktx\Kafka\Client\TopicSubscription\TopicSubscription;
use PHPUnit\Framework\TestCase;
use RdKafka\TopicPartition;

final class SubscriptionTest extends TestCase
{
    public function testCreate(): void
    {
        $subscription = TopicSubscription::create('topic1');

        self::assertSame('topic1', $subscription->topic);
        self::assertNull($subscription->partition);
        self::assertNull($subscription->offset);
    }

    public function testCreateWithPartition(): void
    {
        $subscription = TopicSubscription::create('topic1', 0);

        self::assertSame('topic1', $subscription->topic);
        self::assertSame(0, $subscription->partition);
        self::assertNull($subscription->offset);
    }

    public function testCreateWithPartitionAndOffset(): void
    {
        $subscription = TopicSubscription::create('topic1', 0, 100);

        self::assertSame('topic1', $subscription->topic);
        self::assertSame(0, $subscription->partition);
        self::assertSame(100, $subscription->offset);
    }

    public function testAsKafkaTopicPartition(): void
    {
        $subscription = TopicSubscription::create('topic1', 0);

        $tp = $subscription->asKafkaTopicPartition();

        self::assertSame('topic1', $tp->getTopic());
        self::assertSame(0, $tp->getPartition());
    }

    public function testAsKafkaTopicPartitionWithOffset(): void
    {
        $subscription = TopicSubscription::create('topic1', 0, 100);

        $tp = $subscription->asKafkaTopicPartition();

        self::assertSame('topic1', $tp->getTopic());
        self::assertSame(0, $tp->getPartition());
        self::assertSame(100, $tp->getOffset());
    }

    public function testAsKafkaTopicPartitionThrowsExceptionWithoutPartition(): void
    {
        $subscription = TopicSubscription::create('topic1');

        $this->expectException(TopicHasNoPartitionException::class);
        $this->expectExceptionMessage('Topic "topic1" has no partition');

        $subscription->asKafkaTopicPartition();
    }

    public function testFromKafkaTopicPartition(): void
    {
        $kafkaTp = new TopicPartition('topic1', 0, 100);

        $subscription = TopicSubscription::fromKafkaTopicPartition($kafkaTp);

        self::assertSame('topic1', $subscription->topic);
        self::assertSame(0, $subscription->partition);
        self::assertSame(100, $subscription->offset);
    }

    public function testFromKafkaTopicPartitionWithoutOffset(): void
    {
        $kafkaTp = new TopicPartition('topic1', 0);

        $subscription = TopicSubscription::fromKafkaTopicPartition($kafkaTp);

        self::assertSame('topic1', $subscription->topic);
        self::assertSame(0, $subscription->partition);
        // TopicPartition без offset возвращает offset как int, но может быть RD_KAFKA_OFFSET_INVALID
        self::assertIsInt($subscription->offset);
    }

    public function testConstructor(): void
    {
        $subscription = new TopicSubscription('topic1', 1, 200);

        self::assertSame('topic1', $subscription->topic);
        self::assertSame(1, $subscription->partition);
        self::assertSame(200, $subscription->offset);
    }

    public function testConstructorWithOnlyTopic(): void
    {
        $subscription = new TopicSubscription('topic1');

        self::assertSame('topic1', $subscription->topic);
        self::assertNull($subscription->partition);
        self::assertNull($subscription->offset);
    }
}
