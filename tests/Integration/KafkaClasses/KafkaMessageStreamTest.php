<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\Kafka;

use Anktx\Kafka\Client\Config\ConsumerConfig;
use Anktx\Kafka\Client\KafkaConsumer;
use Anktx\Kafka\Client\KafkaMessageStream;
use Anktx\Kafka\Client\TopicSubscription\TopicSubscriptionList;
use PHPUnit\Framework\TestCase;

final class KafkaMessageStreamTest extends TestCase
{
    public function testConstructor(): void
    {
        $config = new ConsumerConfig(
            brokers: 'localhost:9092',
            groupId: 'test-group',
            instanceId: 'test-instance',
        );

        $consumer = new KafkaConsumer($config);
        $stream = new KafkaMessageStream($consumer);

        $this->assertInstanceOf(KafkaMessageStream::class, $stream);

        $consumer->close();
    }

    public function testConstructorWithCustomTimeout(): void
    {
        $config = new ConsumerConfig(
            brokers: 'localhost:9092',
            groupId: 'test-group',
            instanceId: 'test-instance',
        );

        $consumer = new KafkaConsumer($config);
        $stream = new KafkaMessageStream($consumer, 2000);

        $this->assertInstanceOf(KafkaMessageStream::class, $stream);

        $consumer->close();
    }

    public function testStreamReturnsGenerator(): void
    {
        $config = new ConsumerConfig(
            brokers: 'localhost:9092',
            groupId: 'test-group',
            instanceId: 'test-instance',
        );

        $consumer = new KafkaConsumer($config);
        $stream = new KafkaMessageStream($consumer);

        $generator = $stream->stream();

        $this->assertInstanceOf(\Generator::class, $generator);

        // Закрываем генератор
        $generator->valid();
        $generator->send(null);

        $consumer->close();
    }

    public function testStreamWithSubscription(): void
    {
        $config = new ConsumerConfig(
            brokers: 'localhost:9092',
            groupId: 'test-group',
            instanceId: 'test-instance',
        );

        $consumer = new KafkaConsumer($config);
        $subscriptionList = TopicSubscriptionList::create('test-topic');

        $consumer->subscribe($subscriptionList);

        $stream = new KafkaMessageStream($consumer);
        $generator = $stream->stream();

        $this->assertInstanceOf(\Generator::class, $generator);

        // Закрываем генератор
        $generator->valid();

        $consumer->unsubscribe();
        $consumer->close();
    }

    public function testStreamWithCustomPollTimeout(): void
    {
        $config = new ConsumerConfig(
            brokers: 'localhost:9092',
            groupId: 'test-group',
            instanceId: 'test-instance',
        );

        $consumer = new KafkaConsumer($config);
        $stream = new KafkaMessageStream($consumer, 500);

        $generator = $stream->stream();

        $this->assertInstanceOf(\Generator::class, $generator);

        // Закрываем генератор
        $generator->valid();

        $consumer->close();
    }
}
