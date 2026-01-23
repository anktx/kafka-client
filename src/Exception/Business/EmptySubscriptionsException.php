<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Exception\Business;

final class EmptySubscriptionsException extends BusinessException
{
    public static function create(): self
    {
        return new self('At least one subscription is required');
    }
}
