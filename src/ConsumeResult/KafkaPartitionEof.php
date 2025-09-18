<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\ConsumeResult;

final readonly class KafkaPartitionEof
{
    public function __construct(
        public string $topic,
        public int $partition,
        public int $offset,
    ) {}
}
