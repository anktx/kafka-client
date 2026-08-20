<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\KafkaMessage;

use Anktx\Kafka\Client\Exception\Logic\InvalidMessageException;
use Anktx\Kafka\Client\Topic\Topic;

/**
 * Сообщение для отправки в Kafka.
 *
 * Оффсет отсутствует намеренно: его назначает брокер, продюсеру он не известен.
 * partition = RD_KAFKA_PARTITION_UA и timestampMs = 0 означают, что значения
 * выставит librdkafka.
 *
 * @throws InvalidMessageException Если partition меньше RD_KAFKA_PARTITION_UA
 *                                 или timestampMs отрицателен; пустое имя топика
 *                                 невозможно by construction ({@see Topic})
 */
final readonly class KafkaProducerMessage
{
    /**
     * @param null|array<string, int|string> $headers
     */
    public function __construct(
        public Topic $topic,
        public ?string $body = null,
        public int $partition = \RD_KAFKA_PARTITION_UA,
        public ?string $key = null,
        public ?array $headers = null,
        public int $timestampMs = 0,
    ) {
        if ($this->partition < \RD_KAFKA_PARTITION_UA) {
            throw InvalidMessageException::partitionBelowUnassigned($this->partition);
        }

        if ($this->timestampMs < 0) {
            throw InvalidMessageException::nonNegativeInt('timestampMs', $this->timestampMs);
        }
    }
}
