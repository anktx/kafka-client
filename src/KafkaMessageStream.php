<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client;

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
     *
     * @throws KafkaConsumerException
     * @throws NotSubscribedException
     */
    public function stream(): \Generator
    {
        // @phpstan-ignore while.alwaysTrue
        while (true) {
            $message = $this->consumer->consumeMatch(
                onMessage: static fn(KafkaConsumerMessage $msg): KafkaConsumerMessage => $msg,
                onTimeout: static fn(): null => null,
                onEof: static fn(): null => null,
                timeoutMs: $this->pollTimeoutMs,
            );

            if ($message !== null) {
                yield $message;
            }
        }
    }
}
