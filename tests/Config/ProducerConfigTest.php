<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\Config;

use Anktx\Kafka\Client\Config\Brokers;
use Anktx\Kafka\Client\Config\Enum\CompressionType;
use Anktx\Kafka\Client\Config\ProducerConfig;
use Anktx\Kafka\Client\Exception\Logic\InvalidConfigException;
use PHPUnit\Framework\TestCase;
use RdKafka\Conf;

final class ProducerConfigTest extends TestCase
{
    public function testCreate(): void
    {
        $config = new ProducerConfig(new Brokers('kafka:9092'));

        self::assertSame('kafka:9092', $config->brokers->value);
    }

    public function testAsKafkaConfig(): void
    {
        $config = new ProducerConfig(new Brokers('kafka:9092'));
        $dump = $config->asKafkaConfig()->dump();

        // bootstrap.servers / linger.ms — алиасы: dump() отдаёт
        // канонические имена metadata.broker.list / queue.buffering.max.ms
        self::assertSame('kafka:9092', $dump['metadata.broker.list']);
        self::assertSame('20480', $dump['queue.buffering.max.kbytes']);
        self::assertSame('102400', $dump['batch.size']);
        self::assertSame('10', $dump['queue.buffering.max.ms']);
        self::assertSame('true', $dump['enable.idempotence']);
        self::assertSame('180000', $dump['connections.max.idle.ms']);
        self::assertSame('100', $dump['reconnect.backoff.ms']);
        self::assertSame('10000', $dump['reconnect.backoff.max.ms']);
        self::assertSame('true', $dump['socket.keepalive.enable']);
        self::assertSame('', $dump['debug']);
    }

    public function testAsKafkaConfigAcceptsCustomMessageTimeout(): void
    {
        // message.timeout.ms — topic-level свойство: в Conf::dump() не виден,
        // но librdkafka валидирует значение при set(), поэтому «asKafkaConfig()
        // не бросил» означает, что значение принято.
        $config = new ProducerConfig(new Brokers('kafka:9092'), messageTimeoutMs: 600000);

        self::assertInstanceOf(Conf::class, $config->asKafkaConfig());
    }

    public function testAsKafkaConfigMapsDefaultCompressionType(): void
    {
        $config = new ProducerConfig(new Brokers('kafka:9092'));
        $dump = $config->asKafkaConfig()->dump();

        // compression.type — алиас: dump() отдаёт канонический compression.codec
        self::assertSame('snappy', $dump['compression.codec']);
    }

    public function testAsKafkaConfigAcceptsAllCompressionTypeBackingValues(): void
    {
        foreach (CompressionType::cases() as $compressionType) {
            $config = new ProducerConfig(new Brokers('kafka:9092'), compressionType: $compressionType);
            $dump = $config->asKafkaConfig()->dump();

            self::assertSame($compressionType->value, $dump['compression.codec']);
        }
    }

    public function testAsKafkaConfigWrapsRdKafkaExceptionIntoInvalidConfigException(): void
    {
        // linger.ms вне диапазона librdkafka (0..900000): конструктор проходит
        // (значение неотрицательное), а Conf::set() бросает сырой RdKafka\Exception
        // — asKafkaConfig() обязан обернуть его в наш тип.
        $config = new ProducerConfig(new Brokers('kafka:9092'), lingerMs: \PHP_INT_MAX);

        try {
            $config->asKafkaConfig();
            self::fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            self::assertStringContainsString('outside allowed range', $e->getMessage());
            self::assertSame(-1, $e->getCode());
        }
    }

    public function testDefaults(): void
    {
        $config = new ProducerConfig(new Brokers('kafka:9092'));

        self::assertSame(20480, $config->queueBufferingMaxKBytes);
        self::assertSame(102400, $config->batchSize);
        self::assertSame(10, $config->lingerMs);
        self::assertSame(CompressionType::Snappy, $config->compressionType);
        self::assertTrue($config->enableIdempotence);
        self::assertSame(120000, $config->messageTimeoutMs);
        self::assertSame(180000, $config->connectionsMaxIdleMs);
        self::assertSame(100, $config->reconnectBackoffMs);
        self::assertSame(10000, $config->reconnectBackoffMaxMs);
        self::assertTrue($config->socketKeepaliveEnable);
        self::assertFalse($config->isDebug);
    }

    public function testIdempotenceDisabled(): void
    {
        $config = new ProducerConfig(new Brokers('kafka:9092'), enableIdempotence: false);
        $dump = $config->asKafkaConfig()->dump();

        self::assertFalse($config->enableIdempotence);
        self::assertSame('false', $dump['enable.idempotence']);
    }

    public function testAsKafkaConfigWithCustomConnectionSettings(): void
    {
        $config = new ProducerConfig(
            new Brokers('kafka:9092'),
            connectionsMaxIdleMs: 90000,
            reconnectBackoffMs: 250,
            reconnectBackoffMaxMs: 25000,
            socketKeepaliveEnable: false,
        );
        $dump = $config->asKafkaConfig()->dump();

        self::assertSame('90000', $dump['connections.max.idle.ms']);
        self::assertSame('250', $dump['reconnect.backoff.ms']);
        self::assertSame('25000', $dump['reconnect.backoff.max.ms']);
        self::assertSame('false', $dump['socket.keepalive.enable']);
    }

    public function testWithCustomQueueBufferingMaxKBytes(): void
    {
        $config = new ProducerConfig(
            new Brokers('kafka:9092'),
            queueBufferingMaxKBytes: 10240,
        );

        self::assertSame(10240, $config->queueBufferingMaxKBytes);
    }

    public function testWithCustomBatchSize(): void
    {
        $config = new ProducerConfig(
            new Brokers('kafka:9092'),
            batchSize: 51200,
        );

        self::assertSame(51200, $config->batchSize);
    }

    public function testWithCustomLingerMs(): void
    {
        $config = new ProducerConfig(
            new Brokers('kafka:9092'),
            lingerMs: 100,
        );

        self::assertSame(100, $config->lingerMs);
    }

    public function testWithGzipCompression(): void
    {
        $config = new ProducerConfig(
            new Brokers('kafka:9092'),
            compressionType: CompressionType::Gzip,
        );

        self::assertSame(CompressionType::Gzip, $config->compressionType);
    }

    public function testWithLz4Compression(): void
    {
        $config = new ProducerConfig(
            new Brokers('kafka:9092'),
            compressionType: CompressionType::Lz4,
        );

        self::assertSame(CompressionType::Lz4, $config->compressionType);
    }

    public function testWithZstdCompression(): void
    {
        $config = new ProducerConfig(
            new Brokers('kafka:9092'),
            compressionType: CompressionType::Zstd,
        );

        self::assertSame(CompressionType::Zstd, $config->compressionType);
    }

    public function testWithNoneCompression(): void
    {
        $config = new ProducerConfig(
            new Brokers('kafka:9092'),
            compressionType: CompressionType::None,
        );

        self::assertSame(CompressionType::None, $config->compressionType);
    }

    public function testWithDebugEnabled(): void
    {
        $config = new ProducerConfig(
            new Brokers('kafka:9092'),
            isDebug: true,
        );

        self::assertTrue($config->isDebug);
    }

    public function testAsKafkaConfigWithAllOptions(): void
    {
        $config = new ProducerConfig(
            new Brokers('kafka:9092'),
            queueBufferingMaxKBytes: 10240,
            batchSize: 51200,
            lingerMs: 100,
            compressionType: CompressionType::Gzip,
            enableIdempotence: false,
            messageTimeoutMs: 600000,
            connectionsMaxIdleMs: 90000,
            reconnectBackoffMs: 250,
            reconnectBackoffMaxMs: 25000,
            socketKeepaliveEnable: false,
            isDebug: true,
        );

        $dump = $config->asKafkaConfig()->dump();

        self::assertSame('kafka:9092', $dump['metadata.broker.list']);
        self::assertSame('10240', $dump['queue.buffering.max.kbytes']);
        self::assertSame('51200', $dump['batch.size']);
        self::assertSame('100', $dump['queue.buffering.max.ms']);
        self::assertSame('gzip', $dump['compression.codec']);
        self::assertSame('false', $dump['enable.idempotence']);
        self::assertSame('90000', $dump['connections.max.idle.ms']);
        self::assertSame('250', $dump['reconnect.backoff.ms']);
        self::assertSame('25000', $dump['reconnect.backoff.max.ms']);
        self::assertSame('false', $dump['socket.keepalive.enable']);
        // librdkafka разворачивает 'all' в полный список флагов
        self::assertStringContainsString('all', $dump['debug']);
    }

    public function testEmptyBrokersIsRejectedByBrokersValueObject(): void
    {
        // Пустой список и его формат — инварианты Brokers VO (полный набор
        // граничных случаев — в BrokersTest): невалидный Brokers невозможно
        // даже сконструировать, отдельные проверки в конфиге не дублируются.
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Config parameter "brokers" must not be an empty string');

        new ProducerConfig(new Brokers(''));
    }

    public function testInvalidBrokersFormatIsRejectedByBrokersValueObject(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage(
            'Config parameter "brokers" must be a comma-separated list of host[:port] entries, "kafka:9092," given',
        );

        new ProducerConfig(new Brokers('kafka:9092,'));
    }

    public function testValidMultiBrokerListPasses(): void
    {
        $config = new ProducerConfig(new Brokers('kafka:9092,[::1]:9093'));

        self::assertSame('kafka:9092,[::1]:9093', $config->brokers->value);
    }

    public function testZeroQueueBufferingMaxKBytesThrows(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Config parameter "queueBufferingMaxKBytes" must be positive, 0 given');

        new ProducerConfig(new Brokers('kafka:9092'), queueBufferingMaxKBytes: 0);
    }

    public function testZeroBatchSizeThrows(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Config parameter "batchSize" must be positive, 0 given');

        new ProducerConfig(new Brokers('kafka:9092'), batchSize: 0);
    }

    public function testNegativeBatchSizeThrows(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Config parameter "batchSize" must be positive, -1 given');

        new ProducerConfig(new Brokers('kafka:9092'), batchSize: -1);
    }

    public function testNegativeLingerMsThrows(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Config parameter "lingerMs" must not be negative, -10 given');

        new ProducerConfig(new Brokers('kafka:9092'), lingerMs: -10);
    }

    public function testZeroMessageTimeoutMsThrows(): void
    {
        // 0 для librdkafka значил бы «ретраить вечно» — запрещаем явно.
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Config parameter "messageTimeoutMs" must be positive, 0 given');

        new ProducerConfig(new Brokers('kafka:9092'), messageTimeoutMs: 0);
    }

    public function testNegativeConnectionsMaxIdleMsThrows(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Config parameter "connectionsMaxIdleMs" must not be negative, -1 given');

        new ProducerConfig(new Brokers('kafka:9092'), connectionsMaxIdleMs: -1);
    }

    public function testNegativeReconnectBackoffMsThrows(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Config parameter "reconnectBackoffMs" must not be negative, -5 given');

        new ProducerConfig(new Brokers('kafka:9092'), reconnectBackoffMs: -5);
    }

    public function testNegativeReconnectBackoffMaxThrows(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Config parameter "reconnectBackoffMaxMs" must not be negative, -50 given');

        new ProducerConfig(new Brokers('kafka:9092'), reconnectBackoffMaxMs: -50);
    }

    public function testReconnectBackoffMaxLessThanMinThrows(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage(
            'Config parameter "reconnectBackoffMaxMs" (100) must not be less than "reconnectBackoffMs" (500)',
        );

        new ProducerConfig(
            new Brokers('kafka:9092'),
            reconnectBackoffMs: 500,
            reconnectBackoffMaxMs: 100,
        );
    }

    public function testReconnectBackoffMaxEqualToMinIsValid(): void
    {
        $config = new ProducerConfig(
            new Brokers('kafka:9092'),
            reconnectBackoffMs: 500,
            reconnectBackoffMaxMs: 500,
        );

        self::assertSame(500, $config->reconnectBackoffMaxMs);
    }

    public function testZeroConnectionsMaxIdleMsIsValid(): void
    {
        // connections.max.idle.ms = 0 — валидное значение librdkafka
        // (не закрывать соединения по простою).
        $config = new ProducerConfig(new Brokers('kafka:9092'), connectionsMaxIdleMs: 0);

        self::assertSame(0, $config->connectionsMaxIdleMs);
    }

    public function testZeroReconnectBackoffMsIsValid(): void
    {
        $config = new ProducerConfig(
            new Brokers('kafka:9092'),
            reconnectBackoffMs: 0,
            reconnectBackoffMaxMs: 0,
        );

        self::assertSame(0, $config->reconnectBackoffMs);
        self::assertSame(0, $config->reconnectBackoffMaxMs);
    }

    public function testZeroLingerMsIsValid(): void
    {
        $config = new ProducerConfig(new Brokers('kafka:9092'), lingerMs: 0);

        self::assertSame(0, $config->lingerMs);
    }
}
