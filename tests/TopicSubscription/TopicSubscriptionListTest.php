<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\TopicSubscription;

use Anktx\Kafka\Client\TopicSubscription\TopicSubscription;
use Anktx\Kafka\Client\TopicSubscription\TopicSubscriptionList;
use PHPUnit\Framework\TestCase;

final class TopicSubscriptionListTest extends TestCase
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

    public function testConstructorWithItems(): void
    {
        $item1 = new TopicSubscription('topic1');
        $item2 = new TopicSubscription('topic2');

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
}
