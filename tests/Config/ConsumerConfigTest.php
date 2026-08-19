<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\Config;

use Anktx\Kafka\Client\Config\ConsumerConfig;
use Anktx\Kafka\Client\Config\Enum\OffsetReset;
use PHPUnit\Framework\TestCase;
use RdKafka\Conf;

final class ConsumerConfigTest extends TestCase
{
    public function testCreate(): void
    {
        $config = new ConsumerConfig(
            brokers: 'kafka:9092',
            groupId: 'test-group',
            instanceId: 'test-instance',
        );

        self::assertSame('kafka:9092', $config->brokers);
        self::assertSame('test-group', $config->groupId);
        self::assertSame('test-instance', $config->instanceId);
    }

    public function testAsKafkaConfig(): void
    {
        $config = new ConsumerConfig(
            brokers: 'kafka:9092',
            groupId: 'test-group',
            instanceId: 'test-instance',
        );
        $kafkaConfig = $config->asKafkaConfig();

        self::assertInstanceOf(Conf::class, $kafkaConfig);
    }

    public function testDefaults(): void
    {
        $config = new ConsumerConfig(
            brokers: 'kafka:9092',
            groupId: 'test-group',
            instanceId: 'test-instance',
        );

        self::assertFalse($config->isDebug);
        self::assertSame(OffsetReset::earliest, $config->offsetReset);
        self::assertNull($config->autoCommitMs);
        self::assertNull($config->sessionTimeoutMs);
        self::assertNull($config->reconnectBackoffMs);
        self::assertNull($config->reconnectBackoffMaxMs);
        self::assertTrue($config->socketKeepaliveEnable);
    }

    public function testInstanceIdIsOptional(): void
    {
        $config = new ConsumerConfig(
            brokers: 'kafka:9092',
            groupId: 'test-group',
        );

        self::assertNull($config->instanceId);
        self::assertInstanceOf(Conf::class, $config->asKafkaConfig());
    }

    public function testWithDebugEnabled(): void
    {
        $config = new ConsumerConfig(
            brokers: 'kafka:9092',
            groupId: 'test-group',
            instanceId: 'test-instance',
            isDebug: true,
        );

        self::assertTrue($config->isDebug);
    }

    public function testWithAutoCommitEnabled(): void
    {
        $config = new ConsumerConfig(
            brokers: 'kafka:9092',
            groupId: 'test-group',
            instanceId: 'test-instance',
            autoCommitMs: 5000,
        );

        self::assertSame(5000, $config->autoCommitMs);
    }

    public function testWithSessionTimeout(): void
    {
        $config = new ConsumerConfig(
            brokers: 'kafka:9092',
            groupId: 'test-group',
            instanceId: 'test-instance',
            sessionTimeoutMs: 10000,
        );

        self::assertSame(10000, $config->sessionTimeoutMs);
    }

    public function testWithLatestOffsetReset(): void
    {
        $config = new ConsumerConfig(
            brokers: 'kafka:9092',
            groupId: 'test-group',
            instanceId: 'test-instance',
            offsetReset: OffsetReset::latest,
        );

        self::assertSame(OffsetReset::latest, $config->offsetReset);
    }

    public function testAsKafkaConfigWithAutoCommit(): void
    {
        $config = new ConsumerConfig(
            brokers: 'kafka:9092',
            groupId: 'test-group',
            instanceId: 'test-instance',
            autoCommitMs: 5000,
        );

        $kafkaConfig = $config->asKafkaConfig();

        self::assertInstanceOf(Conf::class, $kafkaConfig);
    }

    public function testAsKafkaConfigWithSessionTimeout(): void
    {
        $config = new ConsumerConfig(
            brokers: 'kafka:9092',
            groupId: 'test-group',
            instanceId: 'test-instance',
            sessionTimeoutMs: 10000,
        );

        $kafkaConfig = $config->asKafkaConfig();

        self::assertInstanceOf(Conf::class, $kafkaConfig);
    }

    public function testAsKafkaConfigWithDebugEnabled(): void
    {
        $config = new ConsumerConfig(
            brokers: 'kafka:9092',
            groupId: 'test-group',
            instanceId: 'test-instance',
            isDebug: true,
        );

        $kafkaConfig = $config->asKafkaConfig();

        self::assertInstanceOf(Conf::class, $kafkaConfig);
    }

    public function testAsKafkaConfigWithLatestOffsetReset(): void
    {
        $config = new ConsumerConfig(
            brokers: 'kafka:9092',
            groupId: 'test-group',
            instanceId: 'test-instance',
            offsetReset: OffsetReset::latest,
        );

        $kafkaConfig = $config->asKafkaConfig();

        self::assertInstanceOf(Conf::class, $kafkaConfig);
    }

    public function testAsKafkaConfigWithAllOptions(): void
    {
        $config = new ConsumerConfig(
            brokers: 'kafka:9092',
            groupId: 'test-group',
            instanceId: 'test-instance',
            offsetReset: OffsetReset::latest,
            autoCommitMs: 5000,
            sessionTimeoutMs: 10000,
            isDebug: true,
        );

        $kafkaConfig = $config->asKafkaConfig();

        self::assertInstanceOf(Conf::class, $kafkaConfig);
    }

    public function testDefaultSocketKeepaliveEnableIsTrue(): void
    {
        $config = new ConsumerConfig(brokers: 'kafka:9092', groupId: 'g');
        $dump = $config->asKafkaConfig()->dump();

        self::assertSame('true', $dump['socket.keepalive.enable']);
    }

    public function testSocketKeepaliveDisabled(): void
    {
        $config = new ConsumerConfig(
            brokers: 'kafka:9092',
            groupId: 'g',
            socketKeepaliveEnable: false,
        );
        $dump = $config->asKafkaConfig()->dump();

        self::assertSame('false', $dump['socket.keepalive.enable']);
    }

    public function testReconnectBackoffConfiguredWhenSet(): void
    {
        $config = new ConsumerConfig(
            brokers: 'kafka:9092',
            groupId: 'g',
            reconnectBackoffMs: 50,
            reconnectBackoffMaxMs: 5000,
        );
        $dump = $config->asKafkaConfig()->dump();

        self::assertSame('50', $dump['reconnect.backoff.ms']);
        self::assertSame('5000', $dump['reconnect.backoff.max.ms']);
    }
}
