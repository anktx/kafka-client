<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\PollStrategy;

use Anktx\Kafka\Client\PollStrategy\ProbabilityPollStrategy;
use PHPUnit\Framework\TestCase;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Юнит-тесты для {@see ProbabilityPollStrategy}.
 *
 * Стратегия детерминирована через инъекцию {@see Randomizer} с
 * фиксированным движком Xoshiro256StarStar: последовательность значений
 * getInt(0, 9999) воспроизводима, поэтому граничные случаи (строгое <,
 * точный порог probability * PRECISION, p=0/p=1) проверяются без
 * статистических допущений.
 *
 * Опорные последовательности:
 * - seed 42: 2214, 5630, 2849, 7329, 1476, ...
 * - seed 7:  1610, 130, 9894, 4336, 5032, ...
 */
final class ProbabilityPollStrategyTest extends TestCase
{
    public function testCreate(): void
    {
        $strategy = new ProbabilityPollStrategy(probability: 0.5);

        self::assertSame(0.5, $strategy->probability);
    }

    public function testInvalidProbability(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ProbabilityPollStrategy(probability: -0.1);
    }

    public function testInvalidProbabilityAboveOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ProbabilityPollStrategy(probability: 1.1);
    }

    public function testShouldPollUsesInjectedRandomizer(): void
    {
        // seed 42: 2214 < 5000 → true; 5630 ≥ 5000 → false; 2849 < 5000 → true; 7329 ≥ 5000 → false.
        $strategy = new ProbabilityPollStrategy(
            probability: 0.5,
            randomizer: new Randomizer(new Xoshiro256StarStar(42)),
        );

        self::assertTrue($strategy->shouldPoll());
        self::assertFalse($strategy->shouldPoll());
        self::assertTrue($strategy->shouldPoll());
        self::assertFalse($strategy->shouldPoll());
    }

    public function testShouldPollUsesStrictLessThan(): void
    {
        // p = 0.2849 задаёт порог ровно 2849 (0.2849 * 10000 === 2849.0).
        // Третье значение последовательности seed 42 равно 2849: строгий <
        // даёт false, нестрогий <= дал бы true.
        $strategy = new ProbabilityPollStrategy(
            probability: 0.2849,
            randomizer: new Randomizer(new Xoshiro256StarStar(42)),
        );

        self::assertTrue($strategy->shouldPoll());
        self::assertFalse($strategy->shouldPoll());
        self::assertFalse($strategy->shouldPoll());
    }

    public function testShouldPollWithZeroProbabilityAlwaysFalse(): void
    {
        $strategy = new ProbabilityPollStrategy(
            probability: 0.0,
            randomizer: new Randomizer(new Xoshiro256StarStar(7)),
        );

        for ($i = 0; $i < 10; ++$i) {
            self::assertFalse($strategy->shouldPoll());
        }
    }

    public function testShouldPollWithOneProbabilityAlwaysTrue(): void
    {
        $strategy = new ProbabilityPollStrategy(
            probability: 1.0,
            randomizer: new Randomizer(new Xoshiro256StarStar(7)),
        );

        for ($i = 0; $i < 10; ++$i) {
            self::assertTrue($strategy->shouldPoll());
        }
    }

    public function testShouldPollNearZeroBoundaries(): void
    {
        // seed 7: порог 1610 — первое значение 1610 (false), второе 130 (true), третье 9894 (false).
        $strategy = new ProbabilityPollStrategy(
            probability: 0.161,
            randomizer: new Randomizer(new Xoshiro256StarStar(7)),
        );

        self::assertFalse($strategy->shouldPoll());
        self::assertTrue($strategy->shouldPoll());
        self::assertFalse($strategy->shouldPoll());
    }

    public function testDefaultRandomizerKeepsStrategyUsable(): void
    {
        // Без явной инъекции стратегия работает на системном CSPRNG:
        // граничные вероятности детерминированы по определению.
        $never = new ProbabilityPollStrategy(probability: 0.0);
        $always = new ProbabilityPollStrategy(probability: 1.0);

        self::assertFalse($never->shouldPoll());
        self::assertTrue($always->shouldPoll());
    }
}
