<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Exception\Kafka;

final class KafkaConnectionException extends KafkaException
{
    public static function flushTimeout(int $timeoutMs): self
    {
        return new self('Flush timed out in ' . $timeoutMs . 'ms');
    }
}
