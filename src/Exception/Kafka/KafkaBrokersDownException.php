<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Exception\Kafka;

use Anktx\Kafka\Client\StreamObserver\BrokersDownBudgetStreamObserver;

/**
 * Брокеры недоступны непрерывно дольше бюджета
 * {@see BrokersDownBudgetStreamObserver::maxBrokersDownMs}:
 * поток сообщений прерван, процесс должен упасть и пересоздаться супервизором.
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
