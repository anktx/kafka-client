<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\Integration\KafkaClasses;

use Anktx\Kafka\Client\Config\Enum\CompressionType;
use Anktx\Kafka\Client\Config\ProducerConfig;
use Anktx\Kafka\Client\KafkaMessage\KafkaProducerMessage;
use Anktx\Kafka\Client\KafkaProducer;
use Anktx\Kafka\Client\PollStrategy\NeverPollStrategy;
use Anktx\Kafka\Client\PollStrategy\TimeoutPollStrategy;
use Anktx\Kafka\Client\Tests\Integration\Support\KafkaBroker;
use PHPUnit\Framework\TestCase;

/**
 * Интеграционные тесты {@see KafkaProducer} против реального брокера
 * (адрес — KAFKA_BROKERS, без брокера тесты помечаются skipped).
 */
final class KafkaProducerTest extends TestCase
{
    private string $brokers;

    protected function setUp(): void
    {
        $this->brokers = KafkaBroker::requireBroker();
    }

    public function testConstructor(): void
    {
        $producer = new KafkaProducer(new ProducerConfig(brokers: $this->brokers));

        self::assertInstanceOf(KafkaProducer::class, $producer);
    }

    public function testConstructorWithCustomPollStrategy(): void
    {
        $producer = new KafkaProducer(
            new ProducerConfig(brokers: $this->brokers),
            new TimeoutPollStrategy(pollIntervalSec: 1),
        );

        self::assertInstanceOf(KafkaProducer::class, $producer);
    }

    public function testConstructorWithNeverPollStrategy(): void
    {
        $producer = new KafkaProducer(
            new ProducerConfig(brokers: $this->brokers),
            new NeverPollStrategy(),
        );

        self::assertInstanceOf(KafkaProducer::class, $producer);
    }

    public function testConstructorWithDebugEnabled(): void
    {
        $producer = new KafkaProducer(new ProducerConfig(
            brokers: $this->brokers,
            isDebug: true,
        ));

        self::assertInstanceOf(KafkaProducer::class, $producer);
    }

    public function testConstructorWithCustomBatchSize(): void
    {
        $producer = new KafkaProducer(new ProducerConfig(
            brokers: $this->brokers,
            batchSize: 51200,
        ));

        self::assertInstanceOf(KafkaProducer::class, $producer);
    }

    public function testConstructorWithCompression(): void
    {
        $producer = new KafkaProducer(new ProducerConfig(
            brokers: $this->brokers,
            compressionType: CompressionType::gzip,
        ));

        self::assertInstanceOf(KafkaProducer::class, $producer);
    }

    public function testProduce(): void
    {
        $producer = new KafkaProducer(new ProducerConfig(brokers: $this->brokers));
        self::assertInstanceOf(KafkaProducer::class, $producer);

        $producer->produce(new KafkaProducerMessage(
            topic: 'test-topic',
            body: 'test message',
            partition: 0,
        ));

        $producer->flush(5000);
    }

    public function testProduceWithAllParameters(): void
    {
        $producer = new KafkaProducer(new ProducerConfig(brokers: $this->brokers));
        self::assertInstanceOf(KafkaProducer::class, $producer);

        $producer->produce(new KafkaProducerMessage(
            topic: 'test-topic',
            body: 'test message',
            partition: 0,
            key: 'test-key',
            headers: ['content-type' => 'application/json'],
            timestampMs: 123456789,
        ));

        $producer->flush(5000);
    }

    public function testProduceWithUnassignedPartition(): void
    {
        $producer = new KafkaProducer(new ProducerConfig(brokers: $this->brokers));
        self::assertInstanceOf(KafkaProducer::class, $producer);

        $producer->produce(new KafkaProducerMessage(
            topic: 'test-topic',
            body: 'test message',
        ));

        $producer->flush(5000);
    }

    public function testProduceWithNullBody(): void
    {
        $producer = new KafkaProducer(new ProducerConfig(brokers: $this->brokers));
        self::assertInstanceOf(KafkaProducer::class, $producer);

        $producer->produce(new KafkaProducerMessage(topic: 'test-topic'));

        $producer->flush(5000);
    }

    public function testProduceWithEmptyHeaders(): void
    {
        $producer = new KafkaProducer(new ProducerConfig(brokers: $this->brokers));
        self::assertInstanceOf(KafkaProducer::class, $producer);

        $producer->produce(new KafkaProducerMessage(
            topic: 'test-topic',
            body: 'test',
            headers: [],
        ));

        $producer->flush(5000);
    }

    public function testProduceMultipleMessages(): void
    {
        $producer = new KafkaProducer(new ProducerConfig(brokers: $this->brokers));
        self::assertInstanceOf(KafkaProducer::class, $producer);

        for ($i = 0; $i < 10; ++$i) {
            $producer->produce(new KafkaProducerMessage(
                topic: 'test-topic',
                body: "message {$i}",
                partition: 0,
            ));
        }

        $producer->flush(5000);
    }

    public function testProduceAndFlush(): void
    {
        $producer = new KafkaProducer(new ProducerConfig(brokers: $this->brokers));
        self::assertInstanceOf(KafkaProducer::class, $producer);

        $producer->produce(new KafkaProducerMessage(
            topic: 'test-topic',
            body: 'test message',
        ));
        $producer->flush(5000);
    }

    public function testFlushWithCustomTimeout(): void
    {
        $producer = new KafkaProducer(new ProducerConfig(brokers: $this->brokers));
        self::assertInstanceOf(KafkaProducer::class, $producer);

        $producer->produce(new KafkaProducerMessage(
            topic: 'test-topic',
            body: 'test message',
        ));
        $producer->flush(10000);
    }
}
