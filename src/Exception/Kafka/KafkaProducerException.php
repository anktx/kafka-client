<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Exception\Kafka;

final class KafkaProducerException extends KafkaException
{
    public static function flushFailed(int $errorCode): self
    {
        return new self(\sprintf('Flush failed: %s (%d)', rd_kafka_err2str($errorCode), $errorCode));
    }
}
