<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\Connection;

use Anktx\Kafka\Client\Connection\BrokerHealthState;
use PHPUnit\Framework\TestCase;

final class BrokerHealthStateTest extends TestCase
{
    public function testIsConnectionErrorForAllBrokersDown(): void
    {
        $this->assertTrue(BrokerHealthState::isConnectionError(\RD_KAFKA_RESP_ERR__ALL_BROKERS_DOWN));
    }

    public function testIsConnectionErrorForTransport(): void
    {
        $this->assertTrue(BrokerHealthState::isConnectionError(\RD_KAFKA_RESP_ERR__TRANSPORT));
    }

    public function testIsConnectionErrorForResolve(): void
    {
        $this->assertTrue(BrokerHealthState::isConnectionError(\RD_KAFKA_RESP_ERR__RESOLVE));
    }

    public function testIsNotConnectionErrorForTimedOut(): void
    {
        $this->assertFalse(BrokerHealthState::isConnectionError(\RD_KAFKA_RESP_ERR__TIMED_OUT));
    }

    public function testIsNotConnectionErrorForUnknownCode(): void
    {
        $this->assertFalse(BrokerHealthState::isConnectionError(99999));
    }

    public function testInitiallyAvailable(): void
    {
        $health = new BrokerHealthState();

        $this->assertFalse($health->isUnavailable());
        $this->assertSame(0.0, $health->unavailableDurationSec(1000.0));
    }

    public function testMarkUnavailableSetsState(): void
    {
        $health = new BrokerHealthState();

        $health->markUnavailable(1000.0);

        $this->assertTrue($health->isUnavailable());
    }

    public function testRepeatedMarkUnavailableDoesNotResetStartTime(): void
    {
        $health = new BrokerHealthState();

        $health->markUnavailable(1000.0);
        $health->markUnavailable(1005.0);

        // 5 секунд от первого вызова, не 0 от второго
        $this->assertSame(5.0, $health->unavailableDurationSec(1005.0));
    }

    public function testMarkAvailableClearsState(): void
    {
        $health = new BrokerHealthState();
        $health->markUnavailable(1000.0);

        $this->assertTrue($health->isUnavailable());

        $health->markAvailable();

        $this->assertFalse($health->isUnavailable());
        $this->assertSame(0.0, $health->unavailableDurationSec(1000.0));
    }

    public function testMarkAvailableAllowsNewUnavailablePeriod(): void
    {
        $health = new BrokerHealthState();

        $health->markUnavailable(1000.0);
        $health->markAvailable();
        $health->markUnavailable(1010.0);

        // Вторая недоступность отсчитывается от 1010, а не от 1000
        $this->assertSame(0.0, $health->unavailableDurationSec(1010.0));
    }

    public function testIsUnavailableForBelowThreshold(): void
    {
        $health = new BrokerHealthState();

        $health->markUnavailable(1000.0);

        $this->assertFalse($health->isUnavailableFor(1029.9, 30));
    }

    public function testIsUnavailableForAtThreshold(): void
    {
        $health = new BrokerHealthState();

        $health->markUnavailable(1000.0);

        $this->assertTrue($health->isUnavailableFor(1030.0, 30));
    }

    public function testIsUnavailableForAboveThreshold(): void
    {
        $health = new BrokerHealthState();

        $health->markUnavailable(1000.0);

        $this->assertTrue($health->isUnavailableFor(1050.0, 30));
    }

    public function testIsUnavailableForWhenAvailable(): void
    {
        $health = new BrokerHealthState();

        $this->assertFalse($health->isUnavailableFor(1000.0, 0));
    }
}
