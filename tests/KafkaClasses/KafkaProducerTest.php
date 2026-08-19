<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\KafkaClasses;

use Anktx\Kafka\Client\KafkaProducer;
use Anktx\Kafka\Client\Tests\Support\InMemoryLogger;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use RdKafka\Message;
use RdKafka\Producer;

/**
 * Юнит-тесты callback'ов {@see KafkaProducer} на mock'е RdKafka\Producer.
 *
 * Фиксируют контракт delivery-report callback'а (setDrMsgCb): успешная
 * доставка логируется как debug, сбой — как error с кодом ошибки; без
 * этого callback'а потерянные сообщения проходят бесследно.
 */
final class KafkaProducerTest extends TestCase
{
    #[AllowMockObjectsWithoutExpectations]
    public function testOnDeliveryReportLogsDeliveredMessageAsDebug(): void
    {
        $logger = new InMemoryLogger();
        $producer = $this->buildProducer($logger);

        (new \ReflectionMethod($producer, 'onDeliveryReport'))->invoke(
            $producer,
            $this->createMock(Producer::class),
            self::message([
                'err' => \RD_KAFKA_RESP_ERR_NO_ERROR,
                'topic_name' => 'test-topic',
                'partition' => 2,
                'offset' => 15,
            ]),
        );

        $records = $logger->findByMessage('Message delivered');
        self::assertCount(1, $records);
        self::assertSame(LogLevel::DEBUG, $records[0]['level']);
        self::assertSame('test-topic', $records[0]['context']['topic']);
        self::assertSame(2, $records[0]['context']['partition']);
        self::assertSame(15, $records[0]['context']['offset']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testOnDeliveryReportLogsFailureAsError(): void
    {
        $logger = new InMemoryLogger();
        $producer = $this->buildProducer($logger);

        (new \ReflectionMethod($producer, 'onDeliveryReport'))->invoke(
            $producer,
            $this->createMock(Producer::class),
            self::message([
                'err' => \RD_KAFKA_RESP_ERR__MSG_TIMED_OUT,
                'topic_name' => 'test-topic',
                'partition' => 1,
            ]),
        );

        $records = $logger->findByMessage('Message delivery failed');
        self::assertCount(1, $records);
        self::assertSame(LogLevel::ERROR, $records[0]['level']);
        self::assertSame('test-topic', $records[0]['context']['topic']);
        self::assertSame(1, $records[0]['context']['partition']);
        self::assertSame(\RD_KAFKA_RESP_ERR__MSG_TIMED_OUT, $records[0]['context']['error_code']);
        self::assertNotSame('', $records[0]['context']['error']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testOnBrokerErrorLogsConnectionErrors(): void
    {
        $logger = new InMemoryLogger();
        $producer = $this->buildProducer($logger);

        (new \ReflectionMethod($producer, 'onBrokerError'))->invoke(
            $producer,
            $this->createMock(Producer::class),
            \RD_KAFKA_RESP_ERR__TRANSPORT,
            'connection refused',
        );

        $records = $logger->findByMessage('Kafka broker connection error');
        self::assertCount(1, $records);
        self::assertSame(\RD_KAFKA_RESP_ERR__TRANSPORT, $records[0]['context']['error_code']);
        self::assertSame('connection refused', $records[0]['context']['reason']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testOnBrokerErrorIgnoresNonConnectionErrors(): void
    {
        $logger = new InMemoryLogger();
        $producer = $this->buildProducer($logger);

        (new \ReflectionMethod($producer, 'onBrokerError'))->invoke(
            $producer,
            $this->createMock(Producer::class),
            \RD_KAFKA_RESP_ERR__BAD_MSG,
            'bad message format',
        );

        self::assertSame([], $logger->records);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testOnLogForwardsLibrdkafkaLogToLogger(): void
    {
        $logger = new InMemoryLogger();
        $producer = $this->buildProducer($logger);

        (new \ReflectionMethod($producer, 'onLog'))->invoke(
            $producer,
            $this->createMock(Producer::class),
            3,
            'PRODUCE',
            'message queued',
        );

        self::assertCount(1, $logger->records);
        self::assertSame(LogLevel::ERROR, $logger->records[0]['level']);
        self::assertSame('message queued', $logger->records[0]['message']);
        self::assertSame(['facility' => 'PRODUCE'], $logger->records[0]['context']);
    }

    /**
     * @param array<string, mixed> $values
     */
    private static function message(array $values): Message
    {
        $message = new Message();
        foreach ($values as $name => $value) {
            // @phpstan-ignore property.dynamicName
            $message->{$name} = $value;
        }

        return $message;
    }

    private function buildProducer(InMemoryLogger $logger): KafkaProducer
    {
        $producer = (new \ReflectionClass(KafkaProducer::class))->newInstanceWithoutConstructor();

        (new \ReflectionProperty(KafkaProducer::class, 'producer'))
            ->setValue($producer, $this->createMock(Producer::class))
        ;
        (new \ReflectionProperty(KafkaProducer::class, 'logger'))->setValue($producer, $logger);

        return $producer;
    }
}
