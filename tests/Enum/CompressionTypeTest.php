<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\Enum;

use Anktx\Kafka\Client\Config\Enum\CompressionType;
use PHPUnit\Framework\TestCase;

final class CompressionTypeTest extends TestCase
{
    public function testCases(): void
    {
        $cases = CompressionType::cases();

        self::assertCount(5, $cases);
    }

    public function testNoneCase(): void
    {
        $case = CompressionType::none;

        self::assertSame('none', $case->name);
        self::assertSame('none', $case->value);
    }

    public function testSnappyCase(): void
    {
        $case = CompressionType::snappy;

        self::assertSame('snappy', $case->name);
        self::assertSame('snappy', $case->value);
    }

    public function testGzipCase(): void
    {
        $case = CompressionType::gzip;

        self::assertSame('gzip', $case->name);
        self::assertSame('gzip', $case->value);
    }

    public function testLz4Case(): void
    {
        $case = CompressionType::lz4;

        self::assertSame('lz4', $case->name);
        self::assertSame('lz4', $case->value);
    }

    public function testZstdCase(): void
    {
        $case = CompressionType::zstd;

        self::assertSame('zstd', $case->name);
        self::assertSame('zstd', $case->value);
    }

    public function testCasesContainAllTypes(): void
    {
        $cases = CompressionType::cases();
        $names = array_map(static fn($case) => $case->name, $cases);

        self::assertContains('none', $names);
        self::assertContains('snappy', $names);
        self::assertContains('gzip', $names);
        self::assertContains('lz4', $names);
        self::assertContains('zstd', $names);
    }

    public function testFromBackingValueRoundTrip(): void
    {
        // Бэкинг-значения — контракт с librdkafka (compression.type):
        // каждое должно резолвиться обратно в свой кейс.
        foreach (CompressionType::cases() as $case) {
            self::assertSame($case, CompressionType::from($case->value));
        }
    }
}
