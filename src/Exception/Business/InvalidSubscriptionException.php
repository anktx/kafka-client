<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Exception\Business;

final class InvalidSubscriptionException extends BusinessException
{
    public static function emptyTopic(): self
    {
        return new self('Subscription topic must not be an empty string');
    }

    public static function negativePartition(int $partition): self
    {
        return new self(\sprintf('Subscription partition must not be negative, %d given', $partition));
    }

    public static function negativeOffset(int $offset): self
    {
        return new self(\sprintf('Subscription offset must not be negative, %d given', $offset));
    }

    public static function offsetWithoutPartition(): self
    {
        return new self('Subscription offset cannot be set without a partition');
    }
}
