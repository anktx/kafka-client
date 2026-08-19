<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\Config;

use Anktx\Kafka\Client\Config\Enum\CompressionType;
use Anktx\Kafka\Client\Config\ProducerConfig;
use PHPUnit\Framework\TestCase;
use RdKafka\Conf;

final class ProducerConfigTest extends TestCase
{
    public function testCreate(): void
    {
        $config = new ProducerConfig('kafka:9092');

        self::assertSame('kafka:9092', $config->brokers);
    }

    public function testAsKafkaConfig(): void
    {
        $config = new ProducerConfig('kafka:9092');
        $kafkaConfig = $config->asKafkaConfig();

        self::assertInstanceOf(Conf::class, $kafkaConfig);
    }

    public function testDefaults(): void
    {
        $config = new ProducerConfig('kafka:9092');

        self::assertSame(20480, $config->queueBufferingMaxKBytes);
        self::assertSame(102400, $config->batchSize);
        self::assertSame(10, $config->lingerMs);
        self::assertSame(CompressionType::snappy, $config->compressionType);
        self::assertFalse($config->isDebug);
    }

    public function testWithCustomQueueBufferingMaxKBytes(): void
    {
        $config = new ProducerConfig(
            'kafka:9092',
            queueBufferingMaxKBytes: 10240,
        );

        self::assertSame(10240, $config->queueBufferingMaxKBytes);
    }

    public function testWithCustomBatchSize(): void
    {
        $config = new ProducerConfig(
            'kafka:9092',
            batchSize: 51200,
        );

        self::assertSame(51200, $config->batchSize);
    }

    public function testWithCustomLingerMs(): void
    {
        $config = new ProducerConfig(
            'kafka:9092',
            lingerMs: 100,
        );

        self::assertSame(100, $config->lingerMs);
    }

    public function testWithGzipCompression(): void
    {
        $config = new ProducerConfig(
            'kafka:9092',
            compressionType: CompressionType::gzip,
        );

        self::assertSame(CompressionType::gzip, $config->compressionType);
    }

    public function testWithLz4Compression(): void
    {
        $config = new ProducerConfig(
            'kafka:9092',
            compressionType: CompressionType::lz4,
        );

        self::assertSame(CompressionType::lz4, $config->compressionType);
    }

    public function testWithZstdCompression(): void
    {
        $config = new ProducerConfig(
            'kafka:9092',
            compressionType: CompressionType::zstd,
        );

        self::assertSame(CompressionType::zstd, $config->compressionType);
    }

    public function testWithDebugEnabled(): void
    {
        $config = new ProducerConfig(
            'kafka:9092',
            isDebug: true,
        );

        self::assertTrue($config->isDebug);
    }

    public function testAsKafkaConfigWithAllOptions(): void
    {
        $config = new ProducerConfig(
            'kafka:9092',
            queueBufferingMaxKBytes: 10240,
            batchSize: 51200,
            lingerMs: 100,
            compressionType: CompressionType::gzip,
            isDebug: true,
        );

        $kafkaConfig = $config->asKafkaConfig();

        self::assertInstanceOf(Conf::class, $kafkaConfig);
    }

    public function testAsKafkaConfigWithGzipCompression(): void
    {
        $config = new ProducerConfig(
            'kafka:9092',
            compressionType: CompressionType::gzip,
        );

        $kafkaConfig = $config->asKafkaConfig();

        self::assertInstanceOf(Conf::class, $kafkaConfig);
    }

    public function testAsKafkaConfigWithLz4Compression(): void
    {
        $config = new ProducerConfig(
            'kafka:9092',
            compressionType: CompressionType::lz4,
        );

        $kafkaConfig = $config->asKafkaConfig();

        self::assertInstanceOf(Conf::class, $kafkaConfig);
    }

    public function testAsKafkaConfigWithZstdCompression(): void
    {
        $config = new ProducerConfig(
            'kafka:9092',
            compressionType: CompressionType::zstd,
        );

        $kafkaConfig = $config->asKafkaConfig();

        self::assertInstanceOf(Conf::class, $kafkaConfig);
    }
}
