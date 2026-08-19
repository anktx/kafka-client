<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\PollStrategy;

use Anktx\Kafka\Client\Exception\Logic\InvalidConfigException;
use Anktx\Kafka\Client\PollStrategy\ProbabilityPollStrategy;
use PHPUnit\Framework\TestCase;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Юнит-тесты для {@see ProbabilityPollStrategy}.
 *
 * Стратегия детерминирована через инъекцию {@see Randomizer} с
 * фиксированным движком Xoshiro256StarStar: последовательность значений
 * getFloat(0, 1, ClosedOpen) воспроизводима, поэтому граничные случаи
 * (строгий <, порог ровно равен выпавшему значению, p=0/p=1) проверяются
 * без статистических допущений.
 *
 * Опорные последовательности getFloat(0, 1, ClosedOpen):
 * - seed 42: 0.24864, 0.84845, 0.27109, 0.22885, ...
 * - seed 7:  0.21936, 0.11748, 0.44296, 0.71186, ...
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
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Config parameter "probability" must be between 0 and 1, -0.1 given');

        new ProbabilityPollStrategy(probability: -0.1);
    }

    public function testInvalidProbabilityAboveOne(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Config parameter "probability" must be between 0 and 1, 1.1 given');

        new ProbabilityPollStrategy(probability: 1.1);
    }

    public function testShouldPollUsesInjectedRandomizer(): void
    {
        // seed 42: 0.24864 < 0.5 → true; 0.84845 ≥ 0.5 → false; 0.27109 < 0.5 → true; 0.22885 < 0.5 → true.
        $strategy = new ProbabilityPollStrategy(
            probability: 0.5,
            randomizer: new Randomizer(new Xoshiro256StarStar(42)),
        );

        self::assertTrue($strategy->shouldPoll());
        self::assertFalse($strategy->shouldPoll());
        self::assertTrue($strategy->shouldPoll());
        self::assertTrue($strategy->shouldPoll());
    }

    public function testShouldPollUsesStrictLessThan(): void
    {
        // Порог задан ровно равным первому выпавшему значению последовательности
        // seed 42 (0.24864...): строгий < даёт false, нестрогий <= дал бы true.
        $strategy = new ProbabilityPollStrategy(
            probability: 0.24863526936112834,
            randomizer: new Randomizer(new Xoshiro256StarStar(42)),
        );

        self::assertFalse($strategy->shouldPoll());
        self::assertFalse($strategy->shouldPoll());
        self::assertFalse($strategy->shouldPoll());
        self::assertTrue($strategy->shouldPoll());
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
        // seed 7, порог 0.12: первое значение 0.21936 (false), второе 0.11748 (true),
        // третье 0.44296 (false) — проверка значений вплотную к порогу.
        $strategy = new ProbabilityPollStrategy(
            probability: 0.12,
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
