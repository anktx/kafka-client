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

        self::assertCount(3, $cases);
    }

    public function testEarliestCase(): void
    {
        $case = OffsetReset::earliest;

        self::assertSame('earliest', $case->name);
    }

    public function testLatestCase(): void
    {
        $case = OffsetReset::latest;

        self::assertSame('latest', $case->name);
    }

    public function testNoneCase(): void
    {
        $case = OffsetReset::none;

        self::assertSame('none', $case->name);
    }

    public function testCasesContainAllTypes(): void
    {
        $cases = OffsetReset::cases();
        $names = array_map(static fn($case) => $case->name, $cases);

        self::assertContains('earliest', $names);
        self::assertContains('latest', $names);
        self::assertContains('none', $names);
    }
}
