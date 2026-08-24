<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\Config;

use Anktx\Kafka\Client\Config\Brokers;
use Anktx\Kafka\Client\Config\ConsumerConfig;
use Anktx\Kafka\Client\Config\Enum\OffsetReset;
use Anktx\Kafka\Client\Exception\Logic\InvalidConfigException;
use Anktx\Kafka\Client\Exception\Logic\LogicException;
use PHPUnit\Framework\TestCase;
use RdKafka\Conf;

final class ConsumerConfigTest extends TestCase
{
    public function testCreate(): void
    {
        $config = new ConsumerConfig(
            brokers: new Brokers('kafka:9092'),
            groupId: 'test-group',
            instanceId: 'test-instance',
        );

        self::assertSame('kafka:9092', $config->brokers->value);
        self::assertSame('test-group', $config->groupId);
        self::assertSame('test-instance', $config->instanceId);
    }

    public function testAsKafkaConfig(): void
    {
        $config = new ConsumerConfig(
            brokers: new Brokers('kafka:9092'),
            groupId: 'test-group',
            instanceId: 'test-instance',
        );
        $dump = $config->asKafkaConfig()->dump();

        self::assertSame('kafka:9092', $dump['metadata.broker.list']);
        self::assertSame('test-group', $dump['group.id']);
        self::assertSame('test-instance', $dump['group.instance.id']);
        self::assertSame('false', $dump['enable.auto.commit']);
        self::assertSame('true', $dump['enable.partition.eof']);
        self::assertSame('30000', $dump['session.timeout.ms']);
        self::assertSame('3000', $dump['heartbeat.interval.ms']);
        self::assertSame('300000', $dump['max.poll.interval.ms']);
        self::assertSame('540000', $dump['connections.max.idle.ms']);
        self::assertSame('100', $dump['reconnect.backoff.ms']);
        self::assertSame('10000', $dump['reconnect.backoff.max.ms']);
        self::assertSame('', $dump['debug']);
    }

    public function testAsKafkaConfigAcceptsAllOffsetResetBackingValues(): void
    {
        // auto.offset.reset — topic-level свойство: в Conf::dump() не виден,
        // но librdkafka валидирует значение при set(), поэтому «asKafkaConfig()
        // не бросил» означает, что бэкинг-значение принято.
        foreach (OffsetReset::cases() as $offsetReset) {
            $config = new ConsumerConfig(brokers: new Brokers('kafka:9092'), groupId: 'g', offsetReset: $offsetReset);

            self::assertInstanceOf(Conf::class, $config->asKafkaConfig());
        }
    }

    public function testDefaults(): void
    {
        $config = new ConsumerConfig(
            brokers: new Brokers('kafka:9092'),
            groupId: 'test-group',
            instanceId: 'test-instance',
        );

        self::assertFalse($config->isDebug);
        self::assertSame(OffsetReset::Earliest, $config->offsetReset);
        self::assertNull($config->autoCommitMs);
        self::assertSame(30000, $config->sessionTimeoutMs);
        self::assertSame(3000, $config->heartbeatIntervalMs);
        self::assertSame(300000, $config->maxPollIntervalMs);
        self::assertSame(540000, $config->connectionsMaxIdleMs);
        self::assertSame(100, $config->reconnectBackoffMs);
        self::assertSame(10000, $config->reconnectBackoffMaxMs);
        self::assertTrue($config->socketKeepaliveEnable);
    }

    public function testInstanceIdIsOptional(): void
    {
        $config = new ConsumerConfig(
            brokers: new Brokers('kafka:9092'),
            groupId: 'test-group',
        );

        self::assertNull($config->instanceId);

        $dump = $config->asKafkaConfig()->dump();

        self::assertArrayNotHasKey('group.instance.id', $dump);
    }

    public function testEmptyInstanceIdThrowsInvalidConfigException(): void
    {
        // Пустая строка — ошибка программиста, а не «не задано»: null для
        // этого есть отдельное значение по умолчанию.
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Config parameter "instanceId" must not be an empty string');

        new ConsumerConfig(brokers: new Brokers('kafka:9092'), groupId: 'test-group', instanceId: '');
    }

    public function testAsKafkaConfigWrapsRdKafkaExceptionIntoInvalidConfigException(): void
    {
        // session.timeout.ms вне диапазона librdkafka (1..3600000): конструктор
        // проходит (значение положительное), а Conf::set() бросает сырой
        // RdKafka\Exception — asKafkaConfig() обязан обернуть его в наш тип.
        $config = new ConsumerConfig(
            brokers: new Brokers('kafka:9092'),
            groupId: 'test-group',
            sessionTimeoutMs: \PHP_INT_MAX,
        );

        try {
            $config->asKafkaConfig();
            self::fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            self::assertInstanceOf(LogicException::class, $e);
            self::assertStringContainsString('outside allowed range', $e->getMessage());
            self::assertSame(-1, $e->getCode());
        }
    }

    public function testWithDebugEnabled(): void
    {
        $config = new ConsumerConfig(
            brokers: new Brokers('kafka:9092'),
            groupId: 'test-group',
            instanceId: 'test-instance',
            isDebug: true,
        );

        self::assertTrue($config->isDebug);
    }

    public function testWithAutoCommitEnabled(): void
    {
        $config = new ConsumerConfig(
            brokers: new Brokers('kafka:9092'),
            groupId: 'test-group',
            instanceId: 'test-instance',
            autoCommitMs: 5000,
        );

        self::assertSame(5000, $config->autoCommitMs);
    }

    public function testWithSessionTimeout(): void
    {
        $config = new ConsumerConfig(
            brokers: new Brokers('kafka:9092'),
            groupId: 'test-group',
            instanceId: 'test-instance',
            sessionTimeoutMs: 10000,
            heartbeatIntervalMs: 3000,
        );

        self::assertSame(10000, $config->sessionTimeoutMs);
        self::assertSame(3000, $config->heartbeatIntervalMs);
    }

    public function testWithLatestOffsetReset(): void
    {
        $config = new ConsumerConfig(
            brokers: new Brokers('kafka:9092'),
            groupId: 'test-group',
            instanceId: 'test-instance',
            offsetReset: OffsetReset::Latest,
        );

        self::assertSame(OffsetReset::Latest, $config->offsetReset);
    }

    public function testAsKafkaConfigWithAutoCommit(): void
    {
        // 7000, а не дефолтные librdkafka 5000: иначе удаление set()
        // auto.commit.interval.ms неотличимо в dump() от значения по умолчанию.
        $config = new ConsumerConfig(
            brokers: new Brokers('kafka:9092'),
            groupId: 'test-group',
            instanceId: 'test-instance',
            autoCommitMs: 7000,
        );

        $dump = $config->asKafkaConfig()->dump();

        self::assertSame('true', $dump['enable.auto.commit']);
        self::assertSame('7000', $dump['auto.commit.interval.ms']);
    }

    public function testAsKafkaConfigWithSessionTimeout(): void
    {
        $config = new ConsumerConfig(
            brokers: new Brokers('kafka:9092'),
            groupId: 'test-group',
            instanceId: 'test-instance',
            sessionTimeoutMs: 10000,
            heartbeatIntervalMs: 3000,
        );

        $dump = $config->asKafkaConfig()->dump();

        self::assertSame('10000', $dump['session.timeout.ms']);
        self::assertSame('3000', $dump['heartbeat.interval.ms']);
    }

    public function testAsKafkaConfigWithDebugEnabled(): void
    {
        $config = new ConsumerConfig(
            brokers: new Brokers('kafka:9092'),
            groupId: 'test-group',
            instanceId: 'test-instance',
            isDebug: true,
        );

        $dump = $config->asKafkaConfig()->dump();

        // librdkafka разворачивает 'all' в полный список флагов
        self::assertStringContainsString('all', $dump['debug']);
    }

    public function testAsKafkaConfigWithLatestOffsetReset(): void
    {
        $config = new ConsumerConfig(
            brokers: new Brokers('kafka:9092'),
            groupId: 'test-group',
            instanceId: 'test-instance',
            offsetReset: OffsetReset::Latest,
        );

        // auto.offset.reset — topic-level свойство, в dump() не виден;
        // успешный вызов означает, что librdkafka принял значение.
        // Полный маппинг всех кейсов — testAsKafkaConfigAcceptsAllOffsetResetBackingValues
        self::assertInstanceOf(Conf::class, $config->asKafkaConfig());
    }

    public function testAsKafkaConfigWithAllOptions(): void
    {
        $config = new ConsumerConfig(
            brokers: new Brokers('kafka:9092'),
            groupId: 'test-group',
            instanceId: 'test-instance',
            offsetReset: OffsetReset::Latest,
            autoCommitMs: 7000,
            sessionTimeoutMs: 60000,
            heartbeatIntervalMs: 20000,
            maxPollIntervalMs: 600000,
            connectionsMaxIdleMs: 90000,
            reconnectBackoffMs: 50,
            reconnectBackoffMaxMs: 5000,
            socketKeepaliveEnable: false,
            isDebug: true,
        );

        $dump = $config->asKafkaConfig()->dump();

        self::assertSame('kafka:9092', $dump['metadata.broker.list']);
        self::assertSame('test-group', $dump['group.id']);
        self::assertSame('test-instance', $dump['group.instance.id']);
        self::assertSame('true', $dump['enable.auto.commit']);
        self::assertSame('7000', $dump['auto.commit.interval.ms']);
        self::assertSame('60000', $dump['session.timeout.ms']);
        self::assertSame('20000', $dump['heartbeat.interval.ms']);
        self::assertSame('600000', $dump['max.poll.interval.ms']);
        self::assertSame('90000', $dump['connections.max.idle.ms']);
        self::assertSame('50', $dump['reconnect.backoff.ms']);
        self::assertSame('5000', $dump['reconnect.backoff.max.ms']);
        self::assertSame('false', $dump['socket.keepalive.enable']);
        // librdkafka разворачивает 'all' в полный список флагов
        self::assertStringContainsString('all', $dump['debug']);
    }

    public function testDefaultSocketKeepaliveEnableIsTrue(): void
    {
        $config = new ConsumerConfig(brokers: new Brokers('kafka:9092'), groupId: 'g');
        $dump = $config->asKafkaConfig()->dump();

        self::assertSame('true', $dump['socket.keepalive.enable']);
    }

    public function testSocketKeepaliveDisabled(): void
    {
        $config = new ConsumerConfig(
            brokers: new Brokers('kafka:9092'),
            groupId: 'g',
            socketKeepaliveEnable: false,
        );
        $dump = $config->asKafkaConfig()->dump();

        self::assertSame('false', $dump['socket.keepalive.enable']);
    }

    public function testReconnectBackoffConfiguredWhenSet(): void
    {
        $config = new ConsumerConfig(
            brokers: new Brokers('kafka:9092'),
            groupId: 'g',
            reconnectBackoffMs: 50,
            reconnectBackoffMaxMs: 5000,
        );
        $dump = $config->asKafkaConfig()->dump();

        self::assertSame('50', $dump['reconnect.backoff.ms']);
        self::assertSame('5000', $dump['reconnect.backoff.max.ms']);
    }

    public function testEmptyBrokersIsRejectedByBrokersValueObject(): void
    {
        // Пустой список и его формат — инварианты Brokers VO (полный набор
        // граничных случаев — в BrokersTest): невалидный Brokers невозможно
        // даже сконструировать, отдельные проверки в конфиге не дублируются.
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Config parameter "brokers" must not be an empty string');

        new ConsumerConfig(brokers: new Brokers(''), groupId: 'g');
    }

    public function testInvalidBrokersFormatIsRejectedByBrokersValueObject(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage(
            'Config parameter "brokers" must be a comma-separated list of host[:port] entries, "kafka:abc" given',
        );

        new ConsumerConfig(brokers: new Brokers('kafka:abc'), groupId: 'g');
    }

    public function testEmptyGroupIdThrowsInvalidConfigException(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Config parameter "groupId" must not be an empty string');

        new ConsumerConfig(brokers: new Brokers('kafka:9092'), groupId: '');
    }

    public function testNegativeAutoCommitMsThrows(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Config parameter "autoCommitMs" must not be negative, -1 given');

        new ConsumerConfig(brokers: new Brokers('kafka:9092'), groupId: 'g', autoCommitMs: -1);
    }

    public function testZeroSessionTimeoutMsThrows(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Config parameter "sessionTimeoutMs" must be positive, 0 given');

        new ConsumerConfig(brokers: new Brokers('kafka:9092'), groupId: 'g', sessionTimeoutMs: 0);
    }

    public function testZeroHeartbeatIntervalMsThrows(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Config parameter "heartbeatIntervalMs" must be positive, 0 given');

        new ConsumerConfig(brokers: new Brokers('kafka:9092'), groupId: 'g', heartbeatIntervalMs: 0);
    }

    public function testZeroMaxPollIntervalMsThrows(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Config parameter "maxPollIntervalMs" must be positive, 0 given');

        new ConsumerConfig(brokers: new Brokers('kafka:9092'), groupId: 'g', maxPollIntervalMs: 0);
    }

    public function testNegativeConnectionsMaxIdleMsThrows(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Config parameter "connectionsMaxIdleMs" must not be negative, -1 given');

        new ConsumerConfig(brokers: new Brokers('kafka:9092'), groupId: 'g', connectionsMaxIdleMs: -1);
    }

    public function testHeartbeatExceedingThirdOfSessionTimeoutThrows(): void
    {
        // 3 * 20000 = 60000 > 50000: правило heartbeat ≈ session/3 нарушено
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage(
            'Config parameter "heartbeatIntervalMs" (20000) must not exceed one third of "sessionTimeoutMs" (50000)',
        );

        new ConsumerConfig(
            brokers: new Brokers('kafka:9092'),
            groupId: 'g',
            sessionTimeoutMs: 50000,
            heartbeatIntervalMs: 20000,
        );
    }

    public function testHeartbeatEqualToThirdOfSessionTimeoutIsValid(): void
    {
        // 3 * 20000 = 60000 = 60000: граница включительно
        $config = new ConsumerConfig(
            brokers: new Brokers('kafka:9092'),
            groupId: 'g',
            sessionTimeoutMs: 60000,
            heartbeatIntervalMs: 20000,
        );

        self::assertSame(60000, $config->sessionTimeoutMs);
        self::assertSame(20000, $config->heartbeatIntervalMs);
    }

    public function testZeroConnectionsMaxIdleMsIsValid(): void
    {
        // connections.max.idle.ms = 0 — валидное значение librdkafka
        // (не закрывать соединения по простою).
        $config = new ConsumerConfig(brokers: new Brokers('kafka:9092'), groupId: 'g', connectionsMaxIdleMs: 0);

        self::assertSame(0, $config->connectionsMaxIdleMs);
    }

    public function testNegativeReconnectBackoffThrows(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Config parameter "reconnectBackoffMs" must not be negative, -5 given');

        new ConsumerConfig(brokers: new Brokers('kafka:9092'), groupId: 'g', reconnectBackoffMs: -5);
    }

    public function testNegativeReconnectBackoffMaxThrows(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Config parameter "reconnectBackoffMaxMs" must not be negative, -50 given');

        new ConsumerConfig(brokers: new Brokers('kafka:9092'), groupId: 'g', reconnectBackoffMaxMs: -50);
    }

    public function testReconnectBackoffMsWithoutMaxIsValid(): void
    {
        // reconnectBackoffMs задан явно, reconnectBackoffMaxMs — дефолт:
        // 100 <= 10000, инверсии диапазона нет.
        $config = new ConsumerConfig(brokers: new Brokers('kafka:9092'), groupId: 'g', reconnectBackoffMs: 100);

        self::assertSame(10000, $config->reconnectBackoffMaxMs);
    }

    public function testReconnectBackoffMaxLessThanMinThrows(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage(
            'Config parameter "reconnectBackoffMaxMs" (100) must not be less than "reconnectBackoffMs" (500)',
        );

        new ConsumerConfig(
            brokers: new Brokers('kafka:9092'),
            groupId: 'g',
            reconnectBackoffMs: 500,
            reconnectBackoffMaxMs: 100,
        );
    }

    public function testZeroAutoCommitMsIsValid(): void
    {
        // auto.commit.interval.ms = 0 — валидное значение (коммит после каждого сообщения).
        $config = new ConsumerConfig(brokers: new Brokers('kafka:9092'), groupId: 'g', autoCommitMs: 0);

        self::assertSame(0, $config->autoCommitMs);
    }

    public function testZeroReconnectBackoffMsIsValid(): void
    {
        $config = new ConsumerConfig(brokers: new Brokers('kafka:9092'), groupId: 'g', reconnectBackoffMs: 0);

        self::assertSame(0, $config->reconnectBackoffMs);
    }

    public function testZeroReconnectBackoffMaxIsValid(): void
    {
        $config = new ConsumerConfig(
            brokers: new Brokers('kafka:9092'),
            groupId: 'g',
            reconnectBackoffMs: 0,
            reconnectBackoffMaxMs: 0,
        );

        self::assertSame(0, $config->reconnectBackoffMaxMs);
    }

    public function testReconnectBackoffMaxEqualToMinIsValid(): void
    {
        $config = new ConsumerConfig(
            brokers: new Brokers('kafka:9092'),
            groupId: 'g',
            reconnectBackoffMs: 500,
            reconnectBackoffMaxMs: 500,
        );

        self::assertSame(500, $config->reconnectBackoffMaxMs);
    }

    public function testInvalidConfigExceptionIsCatchableAsLogicException(): void
    {
        try {
            new ConsumerConfig(brokers: new Brokers('kafka:9092'), groupId: '');
            self::fail('Expected InvalidConfigException');
        } catch (LogicException $e) {
            self::assertInstanceOf(InvalidConfigException::class, $e);
        }
    }
}
