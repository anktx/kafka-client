<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Exception\Logic;

final class InvalidLogLevelException extends LogicException
{
    public static function unknownSeverity(int $severity): self
    {
        return new self(\sprintf('Unknown librdkafka log severity %d, expected 0-7', $severity));
    }
}
