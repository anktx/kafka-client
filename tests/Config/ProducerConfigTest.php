<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\Config;

use Anktx\Kafka\Client\Config\Brokers;
use Anktx\Kafka\Client\Config\Enum\CompressionType;
use Anktx\Kafka\Client\Config\ProducerConfig;
use Anktx\Kafka\Client\Exception\Logic\InvalidConfigException;
use PHPUnit\Framework\TestCase;

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
        self::assertSame('', $dump['debug']);
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
        self::assertFalse($config->isDebug);
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
            isDebug: true,
        );

        $dump = $config->asKafkaConfig()->dump();

        self::assertSame('kafka:9092', $dump['metadata.broker.list']);
        self::assertSame('10240', $dump['queue.buffering.max.kbytes']);
        self::assertSame('51200', $dump['batch.size']);
        self::assertSame('100', $dump['queue.buffering.max.ms']);
        self::assertSame('gzip', $dump['compression.codec']);
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

    public function testZeroLingerMsIsValid(): void
    {
        $config = new ProducerConfig(new Brokers('kafka:9092'), lingerMs: 0);

        self::assertSame(0, $config->lingerMs);
    }
}
