<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Subscription;

use Anktx\Kafka\Client\Exception\Business\TopicHasNoPartitionException;

final class Subscription
{
    public function __construct(
        public readonly string $topic,
        public readonly ?int $partition = null,
        public readonly ?int $offset = null,
    ) {
    }

    public static function create(string $topic, ?int $partition = null, ?int $offset = null): self
    {
        return new self($topic, $partition, $offset);
    }

    public static function fromKafkaTopicPartition(\RdKafka\TopicPartition $tp): self
    {
        return new self(
            topic: $tp->getTopic(),
            partition: $tp->getPartition(),
            offset: $tp->getOffset(),
        );
    }

    public function asKafkaTopicPartition(): \RdKafka\TopicPartition
    {
        if ($this->partition === null) {
            throw new TopicHasNoPartitionException('Topic "' . $this->topic . '" has no partition');
        }

        if ($this->offset === null) {
            return new \RdKafka\TopicPartition($this->topic, $this->partition);
        }

        return new \RdKafka\TopicPartition($this->topic, $this->partition, $this->offset);
    }
}
