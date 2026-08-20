<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Exception\Logic;

final class InvalidMessageException extends LogicException
{
    public static function nonNegativeInt(string $property, int $value): self
    {
        return new self(\sprintf('Message property "%s" must not be negative, %d given', $property, $value));
    }

    public static function partitionBelowUnassigned(int $partition): self
    {
        return new self(\sprintf(
            'Message property "partition" must not be less than RD_KAFKA_PARTITION_UA (%d), %d given',
            \RD_KAFKA_PARTITION_UA,
            $partition,
        ));
    }
}
