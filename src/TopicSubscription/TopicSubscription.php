<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\TopicSubscription;

use Anktx\Kafka\Client\Exception\Business\InvalidSubscriptionException;

/**
 * Подписка на топик в составе consumer group.
 *
 * Партиции и смещения назначает librdkafka через rebalance-callback,
 * поэтому подписка задаётся только именем топика.
 */
final readonly class TopicSubscription
{
    public function __construct(
        public string $topic,
    ) {
        if ($this->topic === '') {
            throw InvalidSubscriptionException::emptyTopic();
        }
    }

    public static function create(string $topic): self
    {
        return new self($topic);
    }
}
