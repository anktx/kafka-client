<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\PollStrategy;

use Anktx\Kafka\Client\PollStrategy\NeverPollStrategy;
use PHPUnit\Framework\TestCase;

final class NeverPollStrategyTest extends TestCase
{
    public function testShouldPoll(): void
    {
        $strategy = new NeverPollStrategy();

        self::assertFalse($strategy->shouldPoll());
    }

    public function testMarkPolledChangesNothing(): void
    {
        $strategy = new NeverPollStrategy();

        $strategy->markPolled();

        self::assertFalse($strategy->shouldPoll());
    }
}
