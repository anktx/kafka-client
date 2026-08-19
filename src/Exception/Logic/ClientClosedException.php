<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Exception\Logic;

final class ClientClosedException extends LogicException
{
    public static function forMethod(string $method): self
    {
        return new self(\sprintf('Cannot call %s(): the client is closed', $method));
    }
}
