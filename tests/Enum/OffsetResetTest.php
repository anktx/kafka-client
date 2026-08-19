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
        $case = OffsetReset::Earliest;

        self::assertSame('Earliest', $case->name);
        self::assertSame('earliest', $case->value);
    }

    public function testLatestCase(): void
    {
        $case = OffsetReset::Latest;

        self::assertSame('Latest', $case->name);
        self::assertSame('latest', $case->value);
    }

    public function testErrorCase(): void
    {
        $case = OffsetReset::Error;

        self::assertSame('Error', $case->name);
        // Бэкинг-значение — контракт с librdkafka: политика «без сброса»
        // (в Kafka-протоколе — `none`) там называется `error`.
        self::assertSame('error', $case->value);
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
