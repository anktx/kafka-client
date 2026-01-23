<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Exception\Logic;

final class NotSubscribedException extends LogicException
{
    public static function create(): self
    {
        return new self('Consumer is not subscribed to any topics');
    }
}
