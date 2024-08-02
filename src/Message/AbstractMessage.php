<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Message;

abstract class AbstractMessage
{
    /**
     * @param array<string, int|string>|null $headers
     */
    public function __construct(
        public readonly string $topic,
        public readonly ?string $body = null,
        public readonly int $partition = \RD_KAFKA_PARTITION_UA,
        public readonly ?int $offset = null,
        public readonly ?string $key = null,
        public readonly ?array $headers = null,
        public readonly int $timestampMs = 0,
    ) {
    }
}
