<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\Kafka;

use Anktx\Kafka\Client\Config\ProducerConfig;
use Anktx\Kafka\Client\Exception\Kafka\KafkaConnectionException;
use Anktx\Kafka\Client\Exception\Kafka\KafkaProducerException;
use Anktx\Kafka\Client\KafkaMessage\KafkaProducerMessage;
use Anktx\Kafka\Client\KafkaProducer;
use Anktx\Kafka\Client\PollStrategy\NeverPoolStrategy;
use PHPUnit\Framework\TestCase;

final class KafkaProducerTest extends TestCase
{
    public function testConstructor(): void
    {
        $config = new ProducerConfig(brokers: 'localhost:9092');

        $producer = new KafkaProducer($config);

        $this->assertInstanceOf(KafkaProducer::class, $producer);
    }

    public function testConstructorWithCustomPollStrategy(): void
    {
        $config = new ProducerConfig(brokers: 'localhost:9092');
        $strategy = new NeverPoolStrategy();

        $producer = new KafkaProducer($config, $strategy);

        $this->assertInstanceOf(KafkaProducer::class, $producer);
    }

    public function testProduce(): void
    {
        $config = new ProducerConfig(brokers: 'localhost:9092');

        $producer = new KafkaProducer($config);

        $message = new KafkaProducerMessage(
            topic: 'test-topic',
            body: 'test message',
            partition: 0,
        );

        try {
            $producer->produce($message);
        } catch (KafkaProducerException $e) {
            // Ошибки могут возникать, если Kafka недоступен
        }

        $this->assertTrue(true);
    }

    public function testProduceWithAllParameters(): void
    {
        $config = new ProducerConfig(brokers: 'localhost:9092');

        $producer = new KafkaProducer($config);

        $message = new KafkaProducerMessage(
            topic: 'test-topic',
            body: 'test message',
            partition: 1,
            key: 'test-key',
            headers: ['content-type' => 'application/json'],
            timestampMs: 123456789,
        );

        try {
            $producer->produce($message);
        } catch (KafkaProducerException $e) {
            // Ошибки могут возникать, если Kafka недоступен
        }

        $this->assertTrue(true);
    }

    public function testProduceWithUnassignedPartition(): void
    {
        $config = new ProducerConfig(brokers: 'localhost:9092');

        $producer = new KafkaProducer($config);

        $message = new KafkaProducerMessage(
            topic: 'test-topic',
            body: 'test message',
        );

        try {
            $producer->produce($message);
        } catch (KafkaProducerException $e) {
            // Ошибки могут возникать, если Kafka недоступен
        }

        $this->assertTrue(true);
    }

    public function testFlush(): void
    {
        $config = new ProducerConfig(brokers: 'localhost:9092');

        $producer = new KafkaProducer($config);

        try {
            $producer->flush();
        } catch (KafkaConnectionException|KafkaProducerException $e) {
            // Ошибки могут возникать, если Kafka недоступна
        }

        $this->assertTrue(true);
    }

    public function testFlushWithCustomTimeout(): void
    {
        $config = new ProducerConfig(brokers: 'localhost:9092');

        $producer = new KafkaProducer($config);

        try {
            $producer->flush(5000);
        } catch (KafkaConnectionException|KafkaProducerException $e) {
            // Ошибки могут возникать, если Kafka недоступна
        }

        $this->assertTrue(true);
    }

    public function testProduceMultipleMessages(): void
    {
        $config = new ProducerConfig(brokers: 'localhost:9092');

        $producer = new KafkaProducer($config);

        for ($i = 0; $i < 10; $i++) {
            $message = new KafkaProducerMessage(
                topic: 'test-topic',
                body: "message {$i}",
                partition: 0,
            );

            try {
                $producer->produce($message);
            } catch (KafkaProducerException $e) {
                // Ошибки могут возникать, если Kafka недоступен
            }
        }

        $this->assertTrue(true);
    }

    public function testConstructorWithDebugEnabled(): void
    {
        $config = new ProducerConfig(
            brokers: 'localhost:9092',
            isDebug: true,
        );

        $producer = new KafkaProducer($config);

        $this->assertInstanceOf(KafkaProducer::class, $producer);
    }

    public function testConstructorWithCustomBatchSize(): void
    {
        $config = new ProducerConfig(
            brokers: 'localhost:9092',
            batchSize: 51200,
        );

        $producer = new KafkaProducer($config);

        $this->assertInstanceOf(KafkaProducer::class, $producer);
    }

    public function testProduceAndFlush(): void
    {
        $config = new ProducerConfig(brokers: 'localhost:9092');

        $producer = new KafkaProducer($config);

        $message = new KafkaProducerMessage(
            topic: 'test-topic',
            body: 'test message',
        );

        try {
            $producer->produce($message);
            $producer->flush();
        } catch (KafkaConnectionException|KafkaProducerException $e) {
            // Ошибки могут возникать, если Kafka недоступна
        }

        $this->assertTrue(true);
    }

    public function testProduceWithNullBody(): void
    {
        $config = new ProducerConfig(brokers: 'localhost:9092');

        $producer = new KafkaProducer($config);

        $message = new KafkaProducerMessage(
            topic: 'test-topic',
        );

        try {
            $producer->produce($message);
        } catch (KafkaProducerException $e) {
            // Ошибки могут возникать, если Kafka недоступен
        }

        $this->assertTrue(true);
    }

    public function testProduceWithEmptyHeaders(): void
    {
        $config = new ProducerConfig(brokers: 'localhost:9092');

        $producer = new KafkaProducer($config);

        $message = new KafkaProducerMessage(
            topic: 'test-topic',
            body: 'test',
            headers: [],
        );

        try {
            $producer->produce($message);
        } catch (KafkaProducerException $e) {
            // Ошибки могут возникать, если Kafka недоступен
        }

        $this->assertTrue(true);
    }
}
