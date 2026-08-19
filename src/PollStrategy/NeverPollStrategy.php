<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\PollStrategy;

final readonly class NeverPollStrategy implements PollStrategy
{
    public function shouldPoll(): bool
    {
        return false;
    }

    public function markPolled(): void {}
}
