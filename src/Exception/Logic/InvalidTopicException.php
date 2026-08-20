<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Exception\Logic;

final class InvalidTopicException extends LogicException
{
    public static function emptyName(): self
    {
        return new self('Topic name must not be an empty string');
    }
}
