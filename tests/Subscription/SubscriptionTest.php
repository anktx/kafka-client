<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\Subscription;

use Anktx\Kafka\Client\Exception\Business\InvalidSubscriptionException;
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
        // TopicPartition без явного offset в ext-rdkafka 6.x отдаёт 0
        // (дефолт конструктора), а не sentinel-значение.
        self::assertSame(0, $subscription->offset);
    }

    public function testEmptyTopicIsRejected(): void
    {
        $this->expectException(InvalidSubscriptionException::class);
        $this->expectExceptionMessage('Subscription topic must not be an empty string');

        new TopicSubscription('');
    }

    public function testNegativePartitionIsRejected(): void
    {
        $this->expectException(InvalidSubscriptionException::class);
        $this->expectExceptionMessage('Subscription partition must not be negative, -1 given');

        new TopicSubscription('topic1', -1);
    }

    public function testNegativeOffsetIsRejected(): void
    {
        $this->expectException(InvalidSubscriptionException::class);
        $this->expectExceptionMessage('Subscription offset must not be negative, -5 given');

        new TopicSubscription('topic1', 0, -5);
    }

    public function testOffsetWithoutPartitionIsRejected(): void
    {
        // offset без partition бессмысленен: asKafkaTopicPartition() всё равно
        // отверг бы такую подписку — фиксируем отказ уже в конструкторе.
        $this->expectException(InvalidSubscriptionException::class);
        $this->expectExceptionMessage('Subscription offset cannot be set without a partition');

        new TopicSubscription('topic1', null, 100);
    }

    public function testZeroPartitionAndZeroOffsetAreValid(): void
    {
        // 0 — легитимные значения partition и offset (начало партиции).
        $subscription = new TopicSubscription('topic1', 0, 0);

        self::assertSame(0, $subscription->partition);
        self::assertSame(0, $subscription->offset);
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
