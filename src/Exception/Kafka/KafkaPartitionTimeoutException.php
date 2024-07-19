<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Exception\Kafka;

final class KafkaPartitionTimeoutException extends KafkaException
{
    public static function create(\RdKafka\Message $message): self
    {
        return new self($message->errstr());
    }
}
