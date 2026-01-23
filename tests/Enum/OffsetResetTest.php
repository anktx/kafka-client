<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\Enum;

use Anktx\Kafka\Client\Config\Enum\OffsetReset;
use PHPUnit\Framework\TestCase;

final class OffsetResetTest extends TestCase
{
    public function testCases(): void
    {
        $cases = OffsetReset::cases();

        $this->assertCount(3, $cases);
    }

    public function testEarliestCase(): void
    {
        $case = OffsetReset::earliest;

        $this->assertSame('earliest', $case->name);
    }

    public function testLatestCase(): void
    {
        $case = OffsetReset::latest;

        $this->assertSame('latest', $case->name);
    }

    public function testNoneCase(): void
    {
        $case = OffsetReset::none;

        $this->assertSame('none', $case->name);
    }

    public function testCasesContainAllTypes(): void
    {
        $cases = OffsetReset::cases();
        $names = array_map(static fn($case) => $case->name, $cases);

        $this->assertContains('earliest', $names);
        $this->assertContains('latest', $names);
        $this->assertContains('none', $names);
    }
}
