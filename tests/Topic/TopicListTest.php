<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\Topic;

use Anktx\Kafka\Client\Topic\Topic;
use Anktx\Kafka\Client\Topic\TopicList;
use PHPUnit\Framework\TestCase;

final class TopicListTest extends TestCase
{
    public function testTopicNames(): void
    {
        $subscriptionList = new TopicList(
            new Topic('topic1'),
            new Topic('topic2'),
            new Topic('topic3'),
        );

        self::assertSame(['topic1', 'topic2', 'topic3'], $subscriptionList->topicNames());
    }

    public function testTopicNamesRemovesDuplicates(): void
    {
        $subscriptionList = new TopicList(
            new Topic('topic1'),
            new Topic('topic1'),
            new Topic('topic2'),
        );

        self::assertSame(['topic1', 'topic2'], $subscriptionList->topicNames());
    }

    public function testIsEmpty(): void
    {
        $subscriptionList = new TopicList();

        self::assertTrue($subscriptionList->isEmpty());
    }

    public function testIsNotEmpty(): void
    {
        $subscriptionList = new TopicList(
            new Topic('topic1'),
        );

        self::assertFalse($subscriptionList->isEmpty());
    }

    public function testCreate(): void
    {
        $subscriptionList = TopicList::create(new Topic('topic1'), new Topic('topic2'));

        self::assertSame(['topic1', 'topic2'], $subscriptionList->topicNames());
        self::assertCount(2, $subscriptionList->items);
    }

    public function testCreateEmpty(): void
    {
        $subscriptionList = TopicList::create();

        self::assertTrue($subscriptionList->isEmpty());
        self::assertCount(0, $subscriptionList->items);
    }

    public function testConstructorWithItems(): void
    {
        $item1 = new Topic('topic1');
        $item2 = new Topic('topic2');

        $subscriptionList = new TopicList($item1, $item2);

        self::assertCount(2, $subscriptionList->items);
        self::assertSame($item1, $subscriptionList->items[0]);
        self::assertSame($item2, $subscriptionList->items[1]);
    }

    public function testTopicNamesPreservesOrder(): void
    {
        $subscriptionList = new TopicList(
            new Topic('topic3'),
            new Topic('topic1'),
            new Topic('topic2'),
        );

        self::assertSame(['topic3', 'topic1', 'topic2'], $subscriptionList->topicNames());
    }
}
