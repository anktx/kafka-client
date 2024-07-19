<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\Config\Subscription;

use Anktx\Kafka\Client\Exception\Business\EmptySubscriptionsException;
use Anktx\Kafka\Client\Subscription\Subscription;
use Anktx\Kafka\Client\Subscription\SubscriptionList;
use PHPUnit\Framework\TestCase;

final class SubscriptionListTest extends TestCase
{
    public function testListTopics(): void
    {
        $subscriptionList = new SubscriptionList(
            new Subscription('topic1'),
            new Subscription('topic1'),
            new Subscription('topic1'),
            new Subscription('topic2'),
            new Subscription('topic3'),
        );

        $this->assertSame(['topic1', 'topic2', 'topic3'], $subscriptionList->topicNames());
    }

    public function testListTopicsWithPartitions(): void
    {
        $subscriptionList = new SubscriptionList(
            new Subscription('topic1', 0),
            new Subscription('topic1', 1),
            new Subscription('topic1', 2),
            new Subscription('topic2'),
            new Subscription('topic3', 3),
        );

        $this->assertSame(['topic1', 'topic2', 'topic3'], $subscriptionList->topicNames());
    }
}
