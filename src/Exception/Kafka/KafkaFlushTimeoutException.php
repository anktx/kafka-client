<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Exception\Kafka;

final class KafkaFlushTimeoutException extends KafkaException
{
    public static function flushTimeout(int $timeoutMs): self
    {
        return new self(
            \sprintf('Flush timed out in %dms', $timeoutMs),
            \RD_KAFKA_RESP_ERR__TIMED_OUT,
        );
    }
}
