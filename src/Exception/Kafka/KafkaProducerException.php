<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Exception\Kafka;

use RdKafka\Exception;

final class KafkaProducerException extends KafkaException
{
    /**
     * Контекст destination (топик/партиция) — в сообщение: без него
     * исключение из produce() не позволяет понять, какое именно
     * сообщение не удалось отправить.
     */
    public static function produceFailed(string $topic, int $partition, Exception $e): self
    {
        return new self(
            \sprintf(
                'Failed to produce message to topic "%s" partition %d: %s',
                $topic,
                $partition,
                $e->getMessage(),
            ),
            $e->getCode(),
            $e,
        );
    }

    public static function flushFailed(int $errorCode): self
    {
        return new self(
            \sprintf('Flush failed: %s (%d)', rd_kafka_err2str($errorCode), $errorCode),
            $errorCode,
        );
    }
}
