<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\Config;

use Anktx\Kafka\Client\Config\ConsumerConfig;
use Anktx\Kafka\Client\Config\Enum\OffsetReset;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
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

        $this->assertSame('kafka:9092', $config->brokers);
        $this->assertSame('test-group', $config->groupId);
        $this->assertSame('test-instance', $config->instanceId);
    }

    public function testAsKafkaConfig(): void
    {
        $config = new ConsumerConfig(
            brokers: 'kafka:9092',
            groupId: 'test-group',
            instanceId: 'test-instance',
        );
        $kafkaConfig = $config->asKafkaConfig();

        $this->assertInstanceOf(Conf::class, $kafkaConfig);
    }

    public function testDefaults(): void
    {
        $config = new ConsumerConfig(
            brokers: 'kafka:9092',
            groupId: 'test-group',
            instanceId: 'test-instance',
        );

        $this->assertFalse($config->isDebug);
        $this->assertSame(OffsetReset::earliest, $config->offsetReset);
        $this->assertNull($config->autoCommitMs);
        $this->assertNull($config->sessionTimeoutMs);
        $this->assertInstanceOf(NullLogger::class, $config->logger);
        $this->assertNull($config->reconnectBackoffMs);
        $this->assertNull($config->reconnectBackoffMaxMs);
        $this->assertTrue($config->socketKeepaliveEnable);
    }

    public function testInstanceIdIsOptional(): void
    {
        $config = new ConsumerConfig(
            brokers: 'kafka:9092',
            groupId: 'test-group',
        );

        $this->assertNull($config->instanceId);
        $this->assertInstanceOf(Conf::class, $config->asKafkaConfig());
    }

    public function testWithDebugEnabled(): void
    {
        $config = new ConsumerConfig(
            brokers: 'kafka:9092',
            groupId: 'test-group',
            instanceId: 'test-instance',
            isDebug: true,
        );

        $this->assertTrue($config->isDebug);
    }

    public function testWithAutoCommitEnabled(): void
    {
        $config = new ConsumerConfig(
            brokers: 'kafka:9092',
            groupId: 'test-group',
            instanceId: 'test-instance',
            autoCommitMs: 5000,
        );

        $this->assertSame(5000, $config->autoCommitMs);
    }

    public function testWithSessionTimeout(): void
    {
        $config = new ConsumerConfig(
            brokers: 'kafka:9092',
            groupId: 'test-group',
            instanceId: 'test-instance',
            sessionTimeoutMs: 10000,
        );

        $this->assertSame(10000, $config->sessionTimeoutMs);
    }

    public function testWithLatestOffsetReset(): void
    {
        $config = new ConsumerConfig(
            brokers: 'kafka:9092',
            groupId: 'test-group',
            instanceId: 'test-instance',
            offsetReset: OffsetReset::latest,
        );

        $this->assertSame(OffsetReset::latest, $config->offsetReset);
    }

    public function testWithCustomLogger(): void
    {
        $logger = new NullLogger();
        $config = new ConsumerConfig(
            brokers: 'kafka:9092',
            groupId: 'test-group',
            instanceId: 'test-instance',
            logger: $logger,
        );

        $this->assertSame($logger, $config->logger);
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

        $this->assertInstanceOf(Conf::class, $kafkaConfig);
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

        $this->assertInstanceOf(Conf::class, $kafkaConfig);
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

        $this->assertInstanceOf(Conf::class, $kafkaConfig);
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

        $this->assertInstanceOf(Conf::class, $kafkaConfig);
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

        $this->assertInstanceOf(Conf::class, $kafkaConfig);
    }

    public function testDefaultSocketKeepaliveEnableIsTrue(): void
    {
        $config = new ConsumerConfig(brokers: 'kafka:9092', groupId: 'g');
        $dump = $config->asKafkaConfig()->dump();

        $this->assertSame('true', $dump['socket.keepalive.enable']);
    }

    public function testSocketKeepaliveDisabled(): void
    {
        $config = new ConsumerConfig(
            brokers: 'kafka:9092',
            groupId: 'g',
            socketKeepaliveEnable: false,
        );
        $dump = $config->asKafkaConfig()->dump();

        $this->assertSame('false', $dump['socket.keepalive.enable']);
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

        $this->assertSame('50', $dump['reconnect.backoff.ms']);
        $this->assertSame('5000', $dump['reconnect.backoff.max.ms']);
    }
}
