<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\Subscription;

use Anktx\Kafka\Client\TopicSubscription\TopicSubscription;
use PHPUnit\Framework\TestCase;

final class SubscriptionTest extends TestCase
{
    public function testStaticCreate(): void
    {
        $subscription = TopicSubscription::create('topic1');

        $this->assertSame('topic1', $subscription->topic);
        $this->assertNull($subscription->partition);
        $this->assertNull($subscription->offset);
    }

    public function testStaticCreateWithPartition(): void
    {
        $subscription = TopicSubscription::create('topic1', 0);

        $this->assertSame('topic1', $subscription->topic);
        $this->assertSame(0, $subscription->partition);
        $this->assertNull($subscription->offset);
    }
}
