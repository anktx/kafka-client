<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\KafkaMessage;

use Anktx\Kafka\Client\ConsumeResult\ConsumeResult;
use Anktx\Kafka\Client\ConsumeResult\ConsumeResultKind;
use Anktx\Kafka\Client\Exception\Logic\InvalidMessageException;

/**
 * Сообщение, прочитанное из Kafka.
 *
 * Положение сообщения (topic, partition, offset) обязательно: прочитанное
 * сообщение всегда знает, где находится. timestampMs = null означает, что
 * брокер не передал время создания сообщения.
 *
 * @throws InvalidMessageException Если topic пустой либо partition, offset
 *                                 или timestampMs отрицательны
 */
final readonly class KafkaConsumerMessage implements ConsumeResult
{
    /**
     * @param null|array<string, int|string> $headers
     */
    public function __construct(
        public string $topic,
        public int $partition,
        public int $offset,
        public ?string $body = null,
        public ?string $key = null,
        public ?array $headers = null,
        public ?int $timestampMs = null,
    ) {
        if ($this->topic === '') {
            throw InvalidMessageException::emptyString('topic');
        }

        if ($this->partition < 0) {
            throw InvalidMessageException::nonNegativeInt('partition', $this->partition);
        }

        if ($this->offset < 0) {
            throw InvalidMessageException::nonNegativeInt('offset', $this->offset);
        }

        if ($this->timestampMs !== null && $this->timestampMs < 0) {
            throw InvalidMessageException::nonNegativeInt('timestampMs', $this->timestampMs);
        }
    }

    public function kind(): ConsumeResultKind
    {
        return ConsumeResultKind::Message;
    }
}
