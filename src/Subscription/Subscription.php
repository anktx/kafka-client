<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Subscription;

use Anktx\Kafka\Client\Exception\Business\TopicHasNoPartitionException;
use RdKafka\TopicPartition;

final readonly class Subscription
{
    public function __construct(
        public string $topic,
        public ?int $partition = null,
        public ?int $offset = null,
    ) {}

    public static function create(string $topic, ?int $partition = null, ?int $offset = null): self
    {
        return new self($topic, $partition, $offset);
    }

    public static function fromKafkaTopicPartition(TopicPartition $tp): self
    {
        return new self(
            topic: $tp->getTopic(),
            partition: $tp->getPartition(),
            offset: $tp->getOffset(),
        );
    }

    public function asKafkaTopicPartition(): TopicPartition
    {
        if ($this->partition === null) {
            throw new TopicHasNoPartitionException('Topic "' . $this->topic . '" has no partition');
        }

        if ($this->offset === null) {
            return new TopicPartition($this->topic, $this->partition);
        }

        return new TopicPartition($this->topic, $this->partition, $this->offset);
    }
}
