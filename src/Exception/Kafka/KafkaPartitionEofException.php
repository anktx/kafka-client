<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Exception\Kafka;

final class KafkaPartitionEofException extends KafkaException
{
    public static function create(\RdKafka\Message $message): self
    {
        return new self($message->errstr());
    }
}
