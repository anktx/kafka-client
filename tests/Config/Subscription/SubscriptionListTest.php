<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\Config\Subscription;

use Anktx\Kafka\Client\TopicSubscription\TopicSubscription;
use Anktx\Kafka\Client\TopicSubscription\TopicSubscriptionList;
use PHPUnit\Framework\TestCase;
use RdKafka\TopicPartition;

final class SubscriptionListTest extends TestCase
{
    public function testTopicNames(): void
    {
        $subscriptionList = new TopicSubscriptionList(
            new TopicSubscription('topic1'),
            new TopicSubscription('topic2'),
            new TopicSubscription('topic3'),
        );

        self::assertSame(['topic1', 'topic2', 'topic3'], $subscriptionList->topicNames());
    }

    public function testTopicNamesRemovesDuplicates(): void
    {
        $subscriptionList = new TopicSubscriptionList(
            new TopicSubscription('topic1'),
            new TopicSubscription('topic1'),
            new TopicSubscription('topic2'),
        );

        self::assertSame(['topic1', 'topic2'], $subscriptionList->topicNames());
    }

    public function testIsEmpty(): void
    {
        $subscriptionList = new TopicSubscriptionList();

        self::assertTrue($subscriptionList->isEmpty());
    }

    public function testIsNotEmpty(): void
    {
        $subscriptionList = new TopicSubscriptionList(
            new TopicSubscription('topic1'),
        );

        self::assertFalse($subscriptionList->isEmpty());
    }

    public function testCreate(): void
    {
        $subscriptionList = TopicSubscriptionList::create('topic1', 'topic2');

        self::assertSame(['topic1', 'topic2'], $subscriptionList->topicNames());
        self::assertCount(2, $subscriptionList->items);
    }

    public function testCreateEmpty(): void
    {
        $subscriptionList = TopicSubscriptionList::create();

        self::assertTrue($subscriptionList->isEmpty());
        self::assertCount(0, $subscriptionList->items);
    }

    public function testFromKafkaTopicPartition(): void
    {
        $tp1 = new TopicPartition('topic1', 0, 100);
        $tp2 = new TopicPartition('topic2', 1, 200);

        $subscriptionList = TopicSubscriptionList::fromKafkaTopicPartition($tp1, $tp2);

        self::assertCount(2, $subscriptionList->items);
        self::assertSame('topic1', $subscriptionList->items[0]->topic);
        self::assertSame('topic2', $subscriptionList->items[1]->topic);
    }

    public function testFromKafkaTopicPartitionEmpty(): void
    {
        $subscriptionList = TopicSubscriptionList::fromKafkaTopicPartition();

        self::assertTrue($subscriptionList->isEmpty());
    }

    public function testAsKafkaTopicPartitionArray(): void
    {
        $subscriptionList = new TopicSubscriptionList(
            new TopicSubscription('topic1', 0, 100),
            new TopicSubscription('topic2', 1, 200),
        );

        $topicPartitions = $subscriptionList->asKafkaTopicPartitionArray();

        self::assertCount(2, $topicPartitions);
        self::assertSame('topic1', $topicPartitions[0]->getTopic());
        self::assertSame(0, $topicPartitions[0]->getPartition());
        self::assertSame(100, $topicPartitions[0]->getOffset());
    }

    public function testAsKafkaTopicPartitionArrayFiltersSubscriptionsWithoutPartition(): void
    {
        $subscriptionList = new TopicSubscriptionList(
            new TopicSubscription('topic1', 0, 100),
            new TopicSubscription('topic2'), // без partition
            new TopicSubscription('topic3', 1, 200),
        );

        $topicPartitions = $subscriptionList->asKafkaTopicPartitionArray();

        self::assertCount(2, $topicPartitions);
        self::assertSame('topic1', $topicPartitions[0]->getTopic());
        self::assertSame('topic3', $topicPartitions[1]->getTopic());
    }

    public function testAsKafkaTopicPartitionArrayEmpty(): void
    {
        $subscriptionList = new TopicSubscriptionList(
            new TopicSubscription('topic1'), // без partition
        );

        $topicPartitions = $subscriptionList->asKafkaTopicPartitionArray();

        self::assertCount(0, $topicPartitions);
    }

    public function testConstructorWithItems(): void
    {
        $item1 = new TopicSubscription('topic1', 0);
        $item2 = new TopicSubscription('topic2', 1);

        $subscriptionList = new TopicSubscriptionList($item1, $item2);

        self::assertCount(2, $subscriptionList->items);
        self::assertSame($item1, $subscriptionList->items[0]);
        self::assertSame($item2, $subscriptionList->items[1]);
    }

    public function testTopicNamesPreservesOrder(): void
    {
        $subscriptionList = new TopicSubscriptionList(
            new TopicSubscription('topic3'),
            new TopicSubscription('topic1'),
            new TopicSubscription('topic2'),
        );

        self::assertSame(['topic3', 'topic1', 'topic2'], $subscriptionList->topicNames());
    }

    public function testTopicNamesWithPartitionsAndWithout(): void
    {
        $subscriptionList = new TopicSubscriptionList(
            new TopicSubscription('topic1', 0),
            new TopicSubscription('topic1'), // дубликат без partition
            new TopicSubscription('topic2'),
        );

        self::assertSame(['topic1', 'topic2'], $subscriptionList->topicNames());
    }
}
