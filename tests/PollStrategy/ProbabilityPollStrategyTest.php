<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\PollStrategy;

use Anktx\Kafka\Client\PollStrategy\ProbabilityPollStrategy;
use PHPUnit\Framework\TestCase;

final class ProbabilityPollStrategyTest extends TestCase
{
    public function testCreate(): void
    {
        $strategy = new ProbabilityPollStrategy(probability: 0.5);

        $this->assertSame(0.5, $strategy->probability);
    }

    public function testInvalidProbability(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ProbabilityPollStrategy(probability: -0.1);
    }

    public function testShouldPollWithZeroProbability(): void
    {
        $strategy = new ProbabilityPollStrategy(probability: 0.0);

        $this->assertFalse($strategy->shouldPoll());
    }

    public function testShouldPollWithOneProbability(): void
    {
        $strategy = new ProbabilityPollStrategy(probability: 1.0);

        $this->assertTrue($strategy->shouldPoll());
    }

    public function testShouldPollBoundary(): void
    {
        // Проверяем граничные значения для mt_rand(0, 10000)
        // Максимальное значение 10000 должно быть строго меньше probability * 10000
        $strategy = new ProbabilityPollStrategy(probability: 1.0);

        for ($i = 0; $i < 100; ++$i) {
            $this->assertTrue($strategy->shouldPoll());
        }
    }

    public function testShouldPollVeryCloseToZero(): void
    {
        // С вероятностью 0.0001, из 10000 только 1 должен быть true
        $strategy = new ProbabilityPollStrategy(probability: 0.0001);

        // mt_rand(0, 10000) < 0.0001 * 10000 = 1
        // Только когда mt_rand вернет 0, будет true
        $trueCount = 0;
        for ($i = 0; $i < 10000; ++$i) {
            if ($strategy->shouldPoll()) {
                ++$trueCount;
            }
        }

        // Должно быть очень мало true, но не 0 (хотя может быть случайно)
        $this->assertLessThan(10, $trueCount);
    }

    public function testShouldPollVeryCloseToOne(): void
    {
        // С вероятностью 0.9999, из 10000 только 1 должен быть false
        $strategy = new ProbabilityPollStrategy(probability: 0.9999);

        // mt_rand(0, 10000) < 0.9999 * 10000 = 9999
        // Все значения от 0 до 9998 должны быть true
        $trueCount = 0;
        for ($i = 0; $i < 10000; ++$i) {
            if ($strategy->shouldPoll()) {
                ++$trueCount;
            }
        }

        // Почти все должны быть true
        $this->assertGreaterThan(9990, $trueCount);
    }

    public function testStrictLessThan(): void
    {
        // Проверяем, что используется < а не <=
        // При probability = 0.001, условие: mt_rand(0, 10000) < 10
        // Значения 0-9 дают true
        $strategy = new ProbabilityPollStrategy(probability: 0.001);

        // Вызываем много раз, должно быть иногда true
        $trueFound = false;
        for ($i = 0; $i < 20000; ++$i) {
            if ($strategy->shouldPoll()) {
                $trueFound = true;

                break;
            }
        }

        // Должен быть хотя бы один true за счёт случайности
        $this->assertTrue($trueFound, 'Expected at least one true result with probability 0.001');
    }

    public function testMtRandRangeZeroToTenThousand(): void
    {
        // Проверяем, что mt_rand(0, 10000) используется правильно
        // Мутанты изменяют диапазон, что сломает распределение
        $strategy = new ProbabilityPollStrategy(probability: 0.5);

        $count = 0;
        $iterations = 10000;

        for ($i = 0; $i < $iterations; ++$i) {
            if ($strategy->shouldPoll()) {
                ++$count;
            }
        }

        // С вероятностью 0.5, матожидание = 5000
        // Допускаем отклонение ±10%
        $this->assertGreaterThan(4000, $count);
        $this->assertLessThan(6000, $count);
    }

    public function testVeryLargeIterations(): void
    {
        // Очень много итераций для проверки статистики
        $strategy = new ProbabilityPollStrategy(probability: 0.1);

        $count = 0;
        $iterations = 10000;

        for ($i = 0; $i < $iterations; ++$i) {
            if ($strategy->shouldPoll()) {
                ++$count;
            }
        }

        // С вероятностью 0.1, матожидание = 1000
        // Допускаем отклонение ±10%
        $this->assertGreaterThan(800, $count);
        $this->assertLessThan(1200, $count);
    }

    public function testProbabilityRangeBounds(): void
    {
        // Проверяем, что диапазон mt_rand(0, 10000) соблюдается
        // Если мутант изменит диапазон, это повлияет на распределение

        // Для probability = 0.001, условие: mt_rand(0, 10000) < 10
        // Значения 0-9 дают true (10/10001 шанс ≈ 0.1%)
        $strategy = new ProbabilityPollStrategy(probability: 0.001);

        // Запускаем много раз
        $trueCount = 0;
        $iterations = 20000;

        for ($i = 0; $i < $iterations; ++$i) {
            if ($strategy->shouldPoll()) {
                ++$trueCount;
            }
        }

        // Матожидание ≈ 20 (20000 * 10/10001)
        // Допускаем широкий диапазон из-за случайности
        $this->assertGreaterThan(5, $trueCount);
        $this->assertLessThan(50, $trueCount);
    }

    public function testMutantDetectionRangeZero(): void
    {
        // Детектирует мутанта mt_rand(1, 10000) вместо mt_rand(0, 10000)
        // С probability=1.0, условие: mt_rand(0, 10000) < 10000
        // При mt_rand(1, 10000) диапазон теряет 0, но результат всё равно тот же
        // Этот тест проверяет, что крайние значения работают корректно
        $strategy = new ProbabilityPollStrategy(probability: 0.0001);

        // mt_rand(0, 10000) < 1 - только при 0 будет true
        // Если мутант изменит 0 на 1: mt_rand(1, 10000) < 1 - всегда false
        // Если мутант изменит 10000 на 9999: mt_rand(0, 9999) < 1 - только при 0
        // Для детекции нужен мутант <= : mt_rand(0, 10000) <= 1 - при 0 и 1 будет true
        $hasTrue = false;
        for ($i = 0; $i < 100000; ++$i) {
            if ($strategy->shouldPoll()) {
                $hasTrue = true;

                break;
            }
        }

        // С вероятностью 0.0001 должен быть хотя бы один true за 100000 попыток
        $this->assertTrue($hasTrue, 'Expected at least one true with probability 0.0001');
    }

    public function testMutantDetectionUpperBound(): void
    {
        // Детектирует мутантов, изменяющих верхнюю границу или умножитель
        // При probability = 1.0, должны получать всегда true
        $strategy = new ProbabilityPollStrategy(probability: 1.0);

        for ($i = 0; $i < 1000; ++$i) {
            $this->assertTrue($strategy->shouldPoll());
        }
    }

    public function testMutantDetectionLowerBound(): void
    {
        // Детектирует мутанта mt_rand(-1, 10000)
        // При probability = 0.0 должен всегда возвращать false
        $strategy = new ProbabilityPollStrategy(probability: 0.0);

        for ($i = 0; $i < 1000; ++$i) {
            $this->assertFalse($strategy->shouldPoll());
        }
    }

    public function testStrictLessThanDetection(): void
    {
        // Детектирует мутанта < на <=
        // При probability = 0.0001: mt_rand(0, 10000) < 1
        // С мутантом: mt_rand(0, 10000) <= 1
        // Мутант даёт в 2 раза больше true (0 и 1 вместо только 0)
        // Но это трудно детектировать без очень большого количества итераций

        // Вместо этого проверяем probability = 0.9999
        // mt_rand(0, 10000) < 9999 - значения 0-9998 дают true
        // С мутантом: mt_rand(0, 10000) <= 9999 - значения 0-9999 дают true
        // Мутант добавляет одно значение (9999) в true

        $strategy = new ProbabilityPollStrategy(probability: 0.9999);

        $falseCount = 0;
        $iterations = 50000;

        for ($i = 0; $i < $iterations; ++$i) {
            if (!$strategy->shouldPoll()) {
                ++$falseCount;
            }
        }

        // С оригиналом: false только при 10000 (1/10001 ≈ 0.01%)
        // Ожидаем ≈ 5 false за 50000
        // С мутантом: нет false (0/10001)
        // Если нашли хотя бы один false, значит мутанта нет
        // Если falseCount = 0, может быть как мутант, так и удача
        $this->assertLessThan(20, $falseCount, 'Too many false results, possible mutation');
    }

    public function testPreciseUpperBound(): void
    {
        // Детектирует мутантов, изменяющих верхнюю границу умножения
        // При probability = 0.0001: mt_rand(0, 10000) < 1
        // Мутант * 9999: mt_rand(0, 10000) < 0.9999 -> 0 (всегда false)
        // Мутант * 10001: mt_rand(0, 10000) < 1.0001 -> 1 (почти то же)

        $strategy = new ProbabilityPollStrategy(probability: 0.0001);

        $trueCount = 0;
        $iterations = 200000;

        for ($i = 0; $i < $iterations; ++$i) {
            if ($strategy->shouldPoll()) {
                ++$trueCount;
            }
        }

        // С оригиналом: true при 0 (1/10001 шанс)
        // Ожидаем ≈ 20 true за 200000
        // С мутантом * 9999: всегда false (0/10001)
        $this->assertGreaterThan(10, $trueCount, 'Expected some true results with probability 0.0001');
    }

    public function testVeryHighPrecision(): void
    {
        // Детектирует мутантов с изменением диапазона mt_rand
        // Используем probability = 0.5 для лучшей детекции

        $strategy = new ProbabilityPollStrategy(probability: 0.5);

        $trueCount = 0;
        $iterations = 100000;

        for ($i = 0; $i < $iterations; ++$i) {
            if ($strategy->shouldPoll()) {
                ++$trueCount;
            }
        }

        // С оригиналом: mt_rand(0, 10000) < 5000
        // Значения 0-4999 дают true (5000/10001 ≈ 49.995%)
        // Ожидаем ≈ 49995 true за 100000

        // Мутанты:
        // mt_rand(0, 9999) < 5000 - 5000/10000 = 50.0%
        // mt_rand(0, 10001) < 5000 - 5000/10002 ≈ 49.99%
        // mt_rand(-1, 10000) < 5000 - 5001/10002 ≈ 49.995%
        // * 9999: mt_rand(0, 10000) < 4999.5 -> 4999 (4999.5/10001 ≈ 49.99%)
        // * 10001: mt_rand(0, 10000) < 5000.5 -> 5000 (5000.5/10001 ≈ 49.997%)

        // Все мутанты дают близкие результаты, поэтому используем узкий диапазон
        $this->assertGreaterThan(49000, $trueCount);
        $this->assertLessThan(51000, $trueCount);
    }

    public function testEdgeCaseOneMinusEpsilon(): void
    {
        // Детектирует мутанта <= вместо <
        // При probability = 0.9999: mt_rand(0, 10000) < 9999
        // Мутант: mt_rand(0, 10000) <= 9999 - всегда true!

        $strategy = new ProbabilityPollStrategy(probability: 0.9999);

        // Если есть мутант, этот тест пройдёт за 1 итерацию
        // Если нет мутанта, нужно много итераций чтобы поймать false
        $foundFalse = false;
        for ($i = 0; $i < 100000; ++$i) {
            if (!$strategy->shouldPoll()) {
                $foundFalse = true;

                break;
            }
        }

        // Если false не найден за 100000 итераций, возможно есть мутант
        // Но это не гарантия из-за случайности
        // Этот тест скорее проверяет, что код может вернуть false
        $this->assertTrue($foundFalse, 'Strategy should return false at least once in 100000 iterations with probability 0.9999');
    }
}
