<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Topic;

use Anktx\Kafka\Client\Topic\Topic;

/**
 * Список топиков для подписки в составе consumer group.
 *
 * Партиции и смещения назначает librdkafka через rebalance-callback,
 * поэтому подписка задаётся только именами топиков.
 */
final readonly class TopicList
{
    /**
     * @var Topic[]
     */
    public array $items;

    public function __construct(Topic ...$items)
    {
        $this->items = $items;
    }

    public static function create(Topic ...$topics): self
    {
        return new self(...$topics);
    }

    /**
     * @return string[]
     */
    public function topicNames(): array
    {
        return array_values(
            array_unique(
                array_map(static fn(Topic $topic) => $topic->name, $this->items),
            ),
        );
    }

    public function isEmpty(): bool
    {
        return \count($this->items) === 0;
    }
}
