<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Subscription;

final class SubscriptionList
{
    /**
     * @var Subscription[]
     */
    public readonly array $items;

    public function __construct(Subscription ...$items)
    {
        $this->items = $items;
    }

    public static function create(string ...$topics): self
    {
        return new self(...array_map(fn (string $topic) => Subscription::create($topic), $topics));
    }

    public static function fromKafkaTopicPartition(\RdKafka\TopicPartition ...$items): self
    {
        return new self(
            ...array_map(fn (\RdKafka\TopicPartition $tp) => Subscription::fromKafkaTopicPartition($tp), $items)
        );
    }

    /**
     * @return string[]
     */
    public function topicNames(): array
    {
        return array_values(
            array_unique(
                array_map(fn (Subscription $s) => $s->topic, $this->items)
            )
        );
    }

    /**
     * @return \RdKafka\TopicPartition[]
     */
    public function asKafkaTopicPartitionArray(): array
    {
        return array_map(
            fn (Subscription $s) => $s->asKafkaTopicPartition(),
            $this->havingPartitions()->items
        );
    }

    public function isEmpty(): bool
    {
        return \count($this->items) === 0;
    }

    private function havingPartitions(): self
    {
        return new self(
            ...array_filter($this->items, fn (Subscription $s) => $s->partition !== null)
        );
    }
}
