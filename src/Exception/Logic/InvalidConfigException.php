<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Exception\Logic;

use RdKafka\Exception;

final class InvalidConfigException extends LogicException
{
    public static function fromKafkaException(Exception $e): self
    {
        return new self($e->getMessage(), $e->getCode(), $e);
    }

    public static function emptyString(string $parameter): self
    {
        return new self(\sprintf('Config parameter "%s" must not be an empty string', $parameter));
    }

    public static function brokers(string $brokers): self
    {
        return new self(\sprintf(
            'Config parameter "brokers" must be a comma-separated list of host[:port] entries, "%s" given',
            $brokers,
        ));
    }

    public static function positiveInt(string $parameter, int $value): self
    {
        return new self(\sprintf('Config parameter "%s" must be positive, %d given', $parameter, $value));
    }

    public static function nonNegativeInt(string $parameter, int $value): self
    {
        return new self(\sprintf('Config parameter "%s" must not be negative, %d given', $parameter, $value));
    }

    public static function probability(string $parameter, float $value): self
    {
        return new self(\sprintf('Config parameter "%s" must be between 0 and 1, %g given', $parameter, $value));
    }

    public static function backoffRange(int $backoffMs, int $backoffMaxMs): self
    {
        return new self(\sprintf(
            'Config parameter "reconnectBackoffMaxMs" (%d) must not be less than "reconnectBackoffMs" (%d)',
            $backoffMaxMs,
            $backoffMs,
        ));
    }

    public static function heartbeatSessionRange(int $heartbeatIntervalMs, int $sessionTimeoutMs): self
    {
        return new self(\sprintf(
            'Config parameter "heartbeatIntervalMs" (%d) must not exceed one third of "sessionTimeoutMs" (%d)',
            $heartbeatIntervalMs,
            $sessionTimeoutMs,
        ));
    }
}
