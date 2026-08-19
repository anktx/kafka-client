<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\TopicSubscription;

use Anktx\Kafka\Client\Exception\Logic\InvalidSubscriptionException;
use Anktx\Kafka\Client\TopicSubscription\TopicSubscription;
use PHPUnit\Framework\TestCase;

final class TopicSubscriptionTest extends TestCase
{
    public function testCreate(): void
    {
        $subscription = TopicSubscription::create('topic1');

        self::assertSame('topic1', $subscription->topic);
    }

    public function testConstructor(): void
    {
        $subscription = new TopicSubscription('topic1');

        self::assertSame('topic1', $subscription->topic);
    }

    public function testEmptyTopicIsRejected(): void
    {
        $this->expectException(InvalidSubscriptionException::class);
        $this->expectExceptionMessage('Subscription topic must not be an empty string');

        new TopicSubscription('');
    }
}
