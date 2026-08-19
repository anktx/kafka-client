<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\TopicSubscription;

final readonly class TopicSubscriptionList
{
    /**
     * @var TopicSubscription[]
     */
    public array $items;

    public function __construct(TopicSubscription ...$items)
    {
        $this->items = $items;
    }

    public static function create(string ...$topics): self
    {
        return new self(
            ...array_map(static fn(string $topic) => TopicSubscription::create($topic), $topics),
        );
    }

    /**
     * @return string[]
     */
    public function topicNames(): array
    {
        return array_values(
            array_unique(
                array_map(static fn(TopicSubscription $s) => $s->topic, $this->items),
            ),
        );
    }

    public function isEmpty(): bool
    {
        return \count($this->items) === 0;
    }
}
