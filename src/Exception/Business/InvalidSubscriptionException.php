<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Exception\Business;

final class InvalidSubscriptionException extends BusinessException
{
    public static function emptyTopic(): self
    {
        return new self('Subscription topic must not be an empty string');
    }
}
