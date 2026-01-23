<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\PollStrategy;

use Anktx\Kafka\Client\PollStrategy\NeverPoolStrategy;
use PHPUnit\Framework\TestCase;

final class NeverPoolStrategyTest extends TestCase
{
    public function testShouldPoll(): void
    {
        $strategy = new NeverPoolStrategy();

        $this->assertFalse($strategy->shouldPoll());
    }
}
