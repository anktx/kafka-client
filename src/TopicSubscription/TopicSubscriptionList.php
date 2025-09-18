<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\TopicSubscription;

use RdKafka\TopicPartition;

final readonly class TopicSubscriptionList
{
    /**
     * @var TopicSubscription[]
     */
    public array $items;

    public function __construct(TopicSubscription ...$items)
    {
        $this->items = $items;
    }

    public static function create(string ...$topics): self
    {
        return new self(
            ...array_map(static fn(string $topic) => TopicSubscription::create($topic), $topics),
        );
    }

    public static function fromKafkaTopicPartition(TopicPartition ...$items): self
    {
        return new self(
            ...array_map(static fn(TopicPartition $tp) => TopicSubscription::fromKafkaTopicPartition($tp), $items),
        );
    }

    /**
     * @return string[]
     */
    public function topicNames(): array
    {
        return array_values(
            array_unique(
                array_map(static fn(TopicSubscription $s) => $s->topic, $this->items),
            ),
        );
    }

    /**
     * @return TopicPartition[]
     */
    public function asKafkaTopicPartitionArray(): array
    {
        return array_map(
            static fn(TopicSubscription $s) => $s->asKafkaTopicPartition(),
            $this->havingPartitions()->items,
        );
    }

    public function isEmpty(): bool
    {
        return \count($this->items) === 0;
    }

    private function havingPartitions(): self
    {
        return new self(
            ...array_filter($this->items, static fn(TopicSubscription $s) => $s->partition !== null),
        );
    }
}
