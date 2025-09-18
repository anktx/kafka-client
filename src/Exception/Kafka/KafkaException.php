<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Exception\Kafka;

use RdKafka\Exception;

abstract class KafkaException extends Exception
{
    final public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    final public static function fromKafkaException(Exception $e): static
    {
        return new static($e->getMessage(), $e->getCode(), $e->getPrevious());
    }
}
