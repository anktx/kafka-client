<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Exception\Kafka;

abstract class KafkaException extends \RdKafka\Exception
{
    final public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    final public static function fromKafkaException(\RdKafka\Exception $e): static
    {
        return new static($e->getMessage(), $e->getCode(), $e->getPrevious());
    }
}
