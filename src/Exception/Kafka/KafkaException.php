<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Exception\Kafka;

use Anktx\Kafka\Client\Exception\KafkaClientException;
use RdKafka\Exception;

/**
 * База инфраструктурных сбоев библиотеки.
 *
 * Наследует \RuntimeException, а не RdKafka\Exception: исключения
 * библиотеки не должны ловиться чужим catch (RdKafka\Exception) в
 * вызывающем коде и не должны случайно поглощаться собственными
 * catch-блоками обёрток вокруг RdKafka-вызовов. Класс RdKafka\Exception
 * остаётся только типом параметра fromKafkaException().
 */
abstract class KafkaException extends \RuntimeException implements KafkaClientException
{
    final public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    final public static function fromKafkaException(Exception $e): static
    {
        return new static($e->getMessage(), $e->getCode(), $e);
    }
}
