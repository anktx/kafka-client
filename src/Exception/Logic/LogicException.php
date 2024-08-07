<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Exception\Logic;

abstract class LogicException extends \LogicException
{
    final public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    final public static function create(string $message): static
    {
        return new static($message);
    }
}
