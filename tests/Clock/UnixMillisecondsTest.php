<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\Clock;

use Anktx\Kafka\Client\Clock\UnixMilliseconds;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UnixMillisecondsTest extends TestCase
{
    #[DataProvider('provideOfCases')]
    public function testOfReturnsUnixMilliseconds(string $time, int $expectedMs): void
    {
        self::assertSame($expectedMs, UnixMilliseconds::of(new \DateTimeImmutable($time)));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideOfCases(): iterable
    {
        yield 'epoch' => ['@0', 0];

        yield 'whole second' => ['@2', 2000];

        yield 'fractional seconds' => ['@1.234', 1234];

        yield 'wall-clock time with offset' => ['2026-01-01T00:00:01.500+00:00', 1767225601500];
    }
}
