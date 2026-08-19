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

        self::assertCount(4, $cases);
    }

    public function testSnappyCase(): void
    {
        $case = CompressionType::snappy;

        self::assertSame('snappy', $case->name);
    }

    public function testGzipCase(): void
    {
        $case = CompressionType::gzip;

        self::assertSame('gzip', $case->name);
    }

    public function testLz4Case(): void
    {
        $case = CompressionType::lz4;

        self::assertSame('lz4', $case->name);
    }

    public function testZstdCase(): void
    {
        $case = CompressionType::zstd;

        self::assertSame('zstd', $case->name);
    }

    public function testCasesContainAllTypes(): void
    {
        $cases = CompressionType::cases();
        $names = array_map(static fn($case) => $case->name, $cases);

        self::assertContains('snappy', $names);
        self::assertContains('gzip', $names);
        self::assertContains('lz4', $names);
        self::assertContains('zstd', $names);
    }
}
