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
        $case = CompressionType::None;

        self::assertSame('None', $case->name);
        self::assertSame('none', $case->value);
    }

    public function testSnappyCase(): void
    {
        $case = CompressionType::Snappy;

        self::assertSame('Snappy', $case->name);
        self::assertSame('snappy', $case->value);
    }

    public function testGzipCase(): void
    {
        $case = CompressionType::Gzip;

        self::assertSame('Gzip', $case->name);
        self::assertSame('gzip', $case->value);
    }

    public function testLz4Case(): void
    {
        $case = CompressionType::Lz4;

        self::assertSame('Lz4', $case->name);
        self::assertSame('lz4', $case->value);
    }

    public function testZstdCase(): void
    {
        $case = CompressionType::Zstd;

        self::assertSame('Zstd', $case->name);
        self::assertSame('zstd', $case->value);
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
