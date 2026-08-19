<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Exception\Kafka;

final class KafkaConsumerException extends KafkaException
{
    public static function create(string $message, int $code = 0): self
    {
        return new self($message, $code);
    }
}
