<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Exception\Logic;

final class EmptySubscriptionsException extends LogicException
{
    public static function create(): self
    {
        return new self('At least one subscription is required');
    }
}
