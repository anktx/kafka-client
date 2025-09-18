<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\Config\Subscription;

use Anktx\Kafka\Client\TopicSubscription\TopicSubscription;
use Anktx\Kafka\Client\TopicSubscription\TopicSubscriptionList;
use PHPUnit\Framework\TestCase;

final class SubscriptionListTest extends TestCase
{
    public function testListTopics(): void
    {
        $subscriptionList = new TopicSubscriptionList(
            new TopicSubscription('topic1'),
            new TopicSubscription('topic1'),
            new TopicSubscription('topic1'),
            new TopicSubscription('topic2'),
            new TopicSubscription('topic3'),
        );

        $this->assertSame(['topic1', 'topic2', 'topic3'], $subscriptionList->topicNames());
    }

    public function testListTopicsWithPartitions(): void
    {
        $subscriptionList = new TopicSubscriptionList(
            new TopicSubscription('topic1', 0),
            new TopicSubscription('topic1', 1),
            new TopicSubscription('topic1', 2),
            new TopicSubscription('topic2'),
            new TopicSubscription('topic3', 3),
        );

        $this->assertSame(['topic1', 'topic2', 'topic3'], $subscriptionList->topicNames());
    }

    public function testStaticCreateSubscription(): void
    {
        $subscriptionList = TopicSubscriptionList::create('topic1', 'topic2', 'topic3');

        $this->assertSame(['topic1', 'topic2', 'topic3'], $subscriptionList->topicNames());
    }
}
