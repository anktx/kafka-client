<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Exception\Kafka;

/**
 * Брокеры недоступны непрерывно дольше бюджета maxBrokersDownMs
 * fail-fast наблюдателя (BrokersDownBudgetStreamObserver): поток
 * сообщений прерван, процесс должен упасть и пересоздаться супервизором.
 * Листовый слой исключений на слой наблюдателей не ссылается —
 * ссылка направлена в обратную сторону.
 */
final class KafkaBrokersDownException extends KafkaException
{
    public static function brokersDownFor(int $downForMs, int $maxDownMs): self
    {
        return new self(
            \sprintf(
                'All Kafka brokers are down for %dms (max allowed %dms)',
                $downForMs,
                $maxDownMs,
            ),
            \RD_KAFKA_RESP_ERR__ALL_BROKERS_DOWN,
        );
    }
}
