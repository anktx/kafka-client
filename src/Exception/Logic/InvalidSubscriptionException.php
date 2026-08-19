<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Exception\Logic;

final class InvalidSubscriptionException extends LogicException
{
    public static function emptyTopic(): self
    {
        return new self('Subscription topic must not be an empty string');
    }
}
