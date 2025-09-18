<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\KafkaMessage;

abstract class AbstractMessage
{
    /**
     * @param null|array<string, int|string> $headers
     */
    public function __construct(
        public readonly string $topic,
        public readonly ?string $body = null,
        public readonly int $partition = \RD_KAFKA_PARTITION_UA,
        public readonly ?int $offset = null,
        public readonly ?string $key = null,
        public readonly ?array $headers = null,
        public readonly int $timestampMs = 0,
    ) {}
}
