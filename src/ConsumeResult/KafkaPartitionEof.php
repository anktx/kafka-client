<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\ConsumeResult;

use Anktx\Kafka\Client\Topic\Topic;

final readonly class KafkaPartitionEof implements ConsumeResult
{
    public function __construct(
        public Topic $topic,
        public int $partition,
        public int $offset,
    ) {}
}
