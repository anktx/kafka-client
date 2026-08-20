<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\Config;

use Anktx\Kafka\Client\Config\Brokers;
use Anktx\Kafka\Client\Exception\Logic\InvalidConfigException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты value object {@see Brokers}: валидация списка брокеров
 * `host[:port][,host[:port]...]` — инвариант конструктора, общий для
 * ProducerConfig/ConsumerConfig. Полный набор граничных случаев живёт
 * здесь; конфиги принимают уже провалидированный тип и собственных
 * проверок brokers не дублируют.
 */
final class BrokersTest extends TestCase
{
    #[DataProvider('provideValidBrokerLists')]
    public function testAcceptsValidBrokerLists(string $brokers): void
    {
        $valueObject = new Brokers($brokers);

        self::assertSame($brokers, $valueObject->value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideValidBrokerLists(): iterable
    {
        yield 'single host, default port' => ['kafka'];

        yield 'host with port' => ['kafka:9092'];

        yield 'two hosts' => ['kafka:9092,backup:9093'];

        yield 'ipv4 with port' => ['10.0.0.1:9092'];

        yield 'ipv6 in brackets with port' => ['[::1]:9092'];

        yield 'dns name with dashes and dots' => ['broker-1.internal.example:9092'];

        yield 'underscore in host (docker service names)' => ['broker_1:9092'];

        yield 'zero port passes to librdkafka' => ['kafka:0'];

        yield 'port boundary 65535' => ['kafka:65535'];
    }

    public function testRejectsEmptyList(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Config parameter "brokers" must not be an empty string');

        new Brokers('');
    }

    #[DataProvider('provideInvalidBrokerLists')]
    public function testRejectsInvalidBrokerLists(string $brokers): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage(\sprintf(
            'Config parameter "brokers" must be a comma-separated list of host[:port] entries, "%s" given',
            $brokers,
        ));

        new Brokers($brokers);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidBrokerLists(): iterable
    {
        yield 'trailing comma — empty entry' => ['kafka:9092,'];

        yield 'double comma — empty entry' => ['kafka,,backup'];

        yield 'whitespace entry' => [' '];

        yield 'port without host' => [':9092'];

        yield 'non-numeric port' => ['kafka:abc'];

        yield 'incomplete port separator' => ['kafka:'];

        yield 'port above 65535' => ['kafka:99999'];

        yield 'space inside entry' => ['kafka 9092'];

        yield 'bare ipv6 without brackets' => ['::1:9092'];

        yield 'second entry invalid' => ['kafka:9092,backup:oops'];
    }
}
