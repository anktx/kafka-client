<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Exception\Kafka;

final class InvalidConfigException extends KafkaException
{
    public static function emptyString(string $parameter): self
    {
        return new self(\sprintf('Config parameter "%s" must not be an empty string', $parameter));
    }

    public static function positiveInt(string $parameter, int $value): self
    {
        return new self(\sprintf('Config parameter "%s" must be positive, %d given', $parameter, $value));
    }

    public static function nonNegativeInt(string $parameter, int $value): self
    {
        return new self(\sprintf('Config parameter "%s" must not be negative, %d given', $parameter, $value));
    }

    public static function backoffRange(int $backoffMs, int $backoffMaxMs): self
    {
        return new self(\sprintf(
            'Config parameter "reconnectBackoffMaxMs" (%d) must not be less than "reconnectBackoffMs" (%d)',
            $backoffMaxMs,
            $backoffMs,
        ));
    }
}
