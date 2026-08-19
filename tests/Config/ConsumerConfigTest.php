<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\Config;

use Anktx\Kafka\Client\Config\ConsumerConfig;
use Anktx\Kafka\Client\Config\Enum\OffsetReset;
use Anktx\Kafka\Client\Exception\Kafka\InvalidConfigException;
use Anktx\Kafka\Client\Exception\Kafka\KafkaException;
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

    public function testEmptyInstanceIdThrowsInvalidConfigException(): void
    {
        // Пустая строка — ошибка программиста, а не «не задано»: null для
        // этого есть отдельное значение по умолчанию.
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Config parameter "instanceId" must not be an empty string');

        new ConsumerConfig(brokers: 'kafka:9092', groupId: 'test-group', instanceId: '');
    }

    public function testAsKafkaConfigWrapsRdKafkaExceptionIntoInvalidConfigException(): void
    {
        // session.timeout.ms вне диапазона librdkafka (1..3600000): конструктор
        // проходит (значение положительное), а Conf::set() бросает сырой
        // RdKafka\Exception — asKafkaConfig() обязан обернуть его в наш тип.
        $config = new ConsumerConfig(
            brokers: 'kafka:9092',
            groupId: 'test-group',
            sessionTimeoutMs: \PHP_INT_MAX,
        );

        try {
            $config->asKafkaConfig();
            self::fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            self::assertInstanceOf(KafkaException::class, $e);
            self::assertStringContainsString('outside allowed range', $e->getMessage());
            self::assertSame(-1, $e->getCode());
        }
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

    public function testEmptyBrokersThrowsInvalidConfigException(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Config parameter "brokers" must not be an empty string');

        new ConsumerConfig(brokers: '', groupId: 'g');
    }

    public function testEmptyGroupIdThrowsInvalidConfigException(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Config parameter "groupId" must not be an empty string');

        new ConsumerConfig(brokers: 'kafka:9092', groupId: '');
    }

    public function testNegativeAutoCommitMsThrows(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Config parameter "autoCommitMs" must not be negative, -1 given');

        new ConsumerConfig(brokers: 'kafka:9092', groupId: 'g', autoCommitMs: -1);
    }

    public function testZeroSessionTimeoutMsThrows(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Config parameter "sessionTimeoutMs" must be positive, 0 given');

        new ConsumerConfig(brokers: 'kafka:9092', groupId: 'g', sessionTimeoutMs: 0);
    }

    public function testNegativeReconnectBackoffThrows(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Config parameter "reconnectBackoffMs" must not be negative, -5 given');

        new ConsumerConfig(brokers: 'kafka:9092', groupId: 'g', reconnectBackoffMs: -5);
    }

    public function testNegativeReconnectBackoffMaxThrows(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Config parameter "reconnectBackoffMaxMs" must not be negative, -50 given');

        new ConsumerConfig(brokers: 'kafka:9092', groupId: 'g', reconnectBackoffMaxMs: -50);
    }

    public function testReconnectBackoffMsWithoutMaxIsValid(): void
    {
        // reconnectBackoffMs задан, reconnectBackoffMaxMs нет: librdkafka
        // подставит дефолт для max — инверсии диапазона быть не может.
        $config = new ConsumerConfig(brokers: 'kafka:9092', groupId: 'g', reconnectBackoffMs: 100);

        self::assertNull($config->reconnectBackoffMaxMs);
    }

    public function testReconnectBackoffMaxLessThanMinThrows(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage(
            'Config parameter "reconnectBackoffMaxMs" (100) must not be less than "reconnectBackoffMs" (500)',
        );

        new ConsumerConfig(
            brokers: 'kafka:9092',
            groupId: 'g',
            reconnectBackoffMs: 500,
            reconnectBackoffMaxMs: 100,
        );
    }

    public function testZeroAutoCommitMsIsValid(): void
    {
        // auto.commit.interval.ms = 0 — валидное значение (коммит после каждого сообщения).
        $config = new ConsumerConfig(brokers: 'kafka:9092', groupId: 'g', autoCommitMs: 0);

        self::assertSame(0, $config->autoCommitMs);
    }

    public function testZeroReconnectBackoffMsIsValid(): void
    {
        $config = new ConsumerConfig(brokers: 'kafka:9092', groupId: 'g', reconnectBackoffMs: 0);

        self::assertSame(0, $config->reconnectBackoffMs);
    }

    public function testZeroReconnectBackoffMaxIsValid(): void
    {
        $config = new ConsumerConfig(brokers: 'kafka:9092', groupId: 'g', reconnectBackoffMaxMs: 0);

        self::assertSame(0, $config->reconnectBackoffMaxMs);
    }

    public function testReconnectBackoffMaxEqualToMinIsValid(): void
    {
        $config = new ConsumerConfig(
            brokers: 'kafka:9092',
            groupId: 'g',
            reconnectBackoffMs: 500,
            reconnectBackoffMaxMs: 500,
        );

        self::assertSame(500, $config->reconnectBackoffMaxMs);
    }

    public function testInvalidConfigExceptionIsCatchableAsKafkaException(): void
    {
        try {
            new ConsumerConfig(brokers: '', groupId: 'g');
            self::fail('Expected InvalidConfigException');
        } catch (KafkaException $e) {
            self::assertInstanceOf(InvalidConfigException::class, $e);
        }
    }
}
