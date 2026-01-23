<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Exception\Kafka;

final class KafkaProducerException extends KafkaException
{
    public static function flushFailed(int $errorCode): self
    {
        return new self('Flush failed, error ' . $errorCode);
    }
}
