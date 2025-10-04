<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client;

use Anktx\Kafka\Client\ConsumeResult\KafkaConsumeTimeout;
use Anktx\Kafka\Client\ConsumeResult\KafkaPartitionEof;
use Anktx\Kafka\Client\Exception\Kafka\KafkaConsumerException;
use Anktx\Kafka\Client\Exception\Logic\NotSubscribedException;
use Anktx\Kafka\Client\KafkaMessage\KafkaConsumerMessage;

final readonly class KafkaMessageStream
{
    public function __construct(
        private KafkaConsumer $consumer,
        private int $pollTimeoutMs = 1000,
    ) {}

    /**
     * @return \Generator<int, KafkaConsumerMessage>
     * @throws KafkaConsumerException
     * @throws NotSubscribedException
     */
    public function stream(): \Generator
    {
        // @phpstan-ignore-next-line
        while (true) {
            $result = $this->consumer->consume($this->pollTimeoutMs);

            // Пропускаем служебные ответы
            if ($result instanceof KafkaConsumeTimeout || $result instanceof KafkaPartitionEof) {
                continue;
            }

            // Возвращаем только реальные сообщения
            yield $result;
        }
    }
}
