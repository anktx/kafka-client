<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Exception\Kafka;

use RdKafka\Exception;

final class KafkaConsumerException extends KafkaException
{
    public static function create(string $message, int $code = 0): self
    {
        return new self($message, $code);
    }

    /**
     * Позиция коммита (топик/партиция/смещение) — в сообщение: коммит
     * идёт потоком, и без позиции исключение не привязать к сообщению.
     */
    public static function commitFailed(string $topic, int $partition, int $offset, Exception $e): self
    {
        return new self(
            \sprintf(
                'Failed to commit offset %d for topic "%s" partition %d: %s',
                $offset,
                $topic,
                $partition,
                $e->getMessage(),
            ),
            $e->getCode(),
            $e,
        );
    }

    /**
     * Позиция ошибки потребления — в сообщение: для кодов без
     * типизированной ветки в consume() контекст теряется целиком.
     */
    public static function consumeFailed(string $errstr, int $errorCode, string $topic, int $partition, int $offset): self
    {
        return new self(
            \sprintf(
                'Consume failed: %s (error %d, topic "%s", partition %d, offset %d)',
                $errstr,
                $errorCode,
                $topic,
                $partition,
                $offset,
            ),
            $errorCode,
        );
    }
}
