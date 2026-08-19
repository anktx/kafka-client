<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\Integration\KafkaClasses;

use Anktx\Kafka\Client\Config\ConsumerConfig;
use Anktx\Kafka\Client\Config\Enum\OffsetReset;
use Anktx\Kafka\Client\Config\ProducerConfig;
use Anktx\Kafka\Client\Exception\Logic\NotSubscribedException;
use Anktx\Kafka\Client\KafkaConsumer;
use Anktx\Kafka\Client\KafkaMessage\KafkaProducerMessage;
use Anktx\Kafka\Client\KafkaMessageStream;
use Anktx\Kafka\Client\KafkaProducer;
use Anktx\Kafka\Client\Tests\Integration\Support\KafkaBroker;
use Anktx\Kafka\Client\TopicSubscription\TopicSubscriptionList;
use PHPUnit\Framework\TestCase;

/**
 * Интеграционные тесты {@see KafkaMessageStream} против реального брокера
 * (адрес — KAFKA_BROKERS, без брокера тесты помечаются skipped).
 */
final class KafkaMessageStreamTest extends TestCase
{
    private string $brokers;

    protected function setUp(): void
    {
        $this->brokers = KafkaBroker::requireBroker();
    }

    public function testConstructor(): void
    {
        $consumer = new KafkaConsumer(new ConsumerConfig(
            brokers: $this->brokers,
            groupId: 'stream-test-' . uniqid('', true),
        ));

        $stream = new KafkaMessageStream($consumer);

        self::assertInstanceOf(KafkaMessageStream::class, $stream);

        $consumer->close();
    }

    public function testConstructorWithCustomTimeout(): void
    {
        $consumer = new KafkaConsumer(new ConsumerConfig(
            brokers: $this->brokers,
            groupId: 'stream-test-' . uniqid('', true),
        ));

        $stream = new KafkaMessageStream($consumer, 2000);

        self::assertInstanceOf(KafkaMessageStream::class, $stream);

        $consumer->close();
    }

    public function testStreamRequiresSubscription(): void
    {
        // stream() делегирует consume(), который без подписки бросает
        // NotSubscribedException — контракт задокументирован в @throws.
        $consumer = new KafkaConsumer(new ConsumerConfig(
            brokers: $this->brokers,
            groupId: 'stream-test-' . uniqid('', true),
        ));

        $stream = new KafkaMessageStream($consumer, 100);

        try {
            $this->expectException(NotSubscribedException::class);

            $stream->stream()->current();
        } finally {
            $consumer->close();
        }
    }

    public function testStreamYieldsProducedMessage(): void
    {
        // end-to-end: produce → subscribe (earliest, уникальная группа)
        // → stream() отдаёт ровно отправленное сообщение.
        $topic = 'stream-test-topic-' . uniqid('', true);

        $producer = new KafkaProducer(new ProducerConfig(brokers: $this->brokers));
        $producer->produce(new KafkaProducerMessage(
            topic: $topic,
            body: 'streamed',
            key: 'k',
        ));
        $producer->flush(5000);

        $consumer = new KafkaConsumer(new ConsumerConfig(
            brokers: $this->brokers,
            groupId: 'stream-test-' . uniqid('', true),
            offsetReset: OffsetReset::earliest,
        ));

        try {
            $consumer->subscribe(TopicSubscriptionList::create($topic));

            $received = 0;

            foreach ((new KafkaMessageStream($consumer, 500))->stream() as $message) {
                self::assertSame('streamed', $message->body);
                ++$received;

                break;
            }

            self::assertSame(1, $received, 'Stream did not yield the produced message');
        } finally {
            $consumer->close();
        }
    }
}
