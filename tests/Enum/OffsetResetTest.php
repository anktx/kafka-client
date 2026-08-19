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
        self::assertSame('earliest', $case->value);
    }

    public function testLatestCase(): void
    {
        $case = OffsetReset::latest;

        self::assertSame('latest', $case->name);
        self::assertSame('latest', $case->value);
    }

    public function testNoneCase(): void
    {
        $case = OffsetReset::none;

        self::assertSame('none', $case->name);
        // Бэкинг-значение — контракт с librdkafka: семантика `none`
        // Kafka-протокола там называется `error`.
        self::assertSame('error', $case->value);
    }

    public function testCasesContainAllTypes(): void
    {
        $cases = OffsetReset::cases();
        $names = array_map(static fn($case) => $case->name, $cases);

        self::assertContains('earliest', $names);
        self::assertContains('latest', $names);
        self::assertContains('none', $names);
    }

    public function testFromBackingValueRoundTrip(): void
    {
        // Бэкинг-значения — контракт с librdkafka (auto.offset.reset):
        // каждое должно резолвиться обратно в свой кейс.
        foreach (OffsetReset::cases() as $case) {
            self::assertSame($case, OffsetReset::from($case->value));
        }
    }
}
