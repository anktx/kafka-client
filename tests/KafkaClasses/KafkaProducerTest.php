<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\KafkaClasses;

use Anktx\Kafka\Client\Exception\Kafka\KafkaConnectionException;
use Anktx\Kafka\Client\Exception\Kafka\KafkaProducerException;
use Anktx\Kafka\Client\KafkaMessage\KafkaProducerMessage;
use Anktx\Kafka\Client\KafkaProducer;
use Anktx\Kafka\Client\PollStrategy\PollStrategy;
use Anktx\Kafka\Client\Tests\Support\InMemoryLogger;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use RdKafka\Exception;
use RdKafka\Producer;
use RdKafka\ProducerTopic;

/**
 * Юнит-тесты {@see KafkaProducer::produce()} на mock'е RdKafka\Producer.
 *
 * Фиксируют контракт drain-цикла delivery-report'ов: drain выполняется только
 * при разрешении PollStrategy, ограничен бюджетом poll()-вызовов (раньше при
 * недоступных брокерах очередь не дренировалась и цикл крутился вхолостую на
 * 100% CPU) и логирует недренжированный остаток очереди.
 */
final class KafkaProducerTest extends TestCase
{
    private const int MAX_DRAIN_POLLS = 100;

    #[AllowMockObjectsWithoutExpectations]
    public function testProduceDrainsDeliveryReportsWhenPollStrategyAllowsPolling(): void
    {
        $topic = $this->createMock(ProducerTopic::class);
        $topic->expects($this->once())->method('producev')
            ->with(\RD_KAFKA_PARTITION_UA, 0, 'hello', null, null, 0)
        ;

        $producer = $this->createMock(Producer::class);
        $producer->method('newTopic')->willReturn($topic);
        $producer->method('getOutQLen')->willReturnOnConsecutiveCalls(1, 0, 0);
        $producer->expects($this->once())->method('poll')->with(0);

        $this->buildProducer($producer, shouldPoll: true)->produce(self::message());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testProduceStopsDrainingAfterPollBudgetExhausted(): void
    {
        $topic = $this->createMock(ProducerTopic::class);
        $topic->expects($this->once())->method('producev');

        $logger = new InMemoryLogger();

        $producer = $this->createMock(Producer::class);
        $producer->method('newTopic')->willReturn($topic);
        $producer->method('getOutQLen')->willReturn(3);
        $producer->expects($this->exactly(self::MAX_DRAIN_POLLS))->method('poll')->with(0);

        $this->buildProducer($producer, shouldPoll: true, logger: $logger)->produce(self::message());

        $warnings = $logger->findByMessage('Delivery report queue not fully drained');
        self::assertCount(1, $warnings);
        self::assertSame('warning', $warnings[0]['level']);
        self::assertSame(self::MAX_DRAIN_POLLS, $warnings[0]['context']['max_polls']);
        self::assertSame(3, $warnings[0]['context']['remaining_messages']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testProduceSkipsDrainingWhenPollStrategyDeclinesPolling(): void
    {
        $topic = $this->createMock(ProducerTopic::class);
        $topic->expects($this->once())->method('producev');

        $producer = $this->createMock(Producer::class);
        $producer->method('newTopic')->willReturn($topic);
        $producer->expects($this->never())->method('getOutQLen');
        $producer->expects($this->never())->method('poll');

        $this->buildProducer($producer, shouldPoll: false)->produce(self::message());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testProduceDrainsWithoutWarningWhenQueueIsEmpty(): void
    {
        $topic = $this->createMock(ProducerTopic::class);
        $topic->expects($this->once())->method('producev');

        $logger = new InMemoryLogger();

        $producer = $this->createMock(Producer::class);
        $producer->method('newTopic')->willReturn($topic);
        $producer->method('getOutQLen')->willReturn(0);
        $producer->expects($this->never())->method('poll');

        $this->buildProducer($producer, shouldPoll: true, logger: $logger)->produce(self::message());

        self::assertSame([], $logger->findByMessage('Delivery report queue not fully drained'));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testProduceWrapsTopicCreationFailure(): void
    {
        $logger = new InMemoryLogger();

        $producer = $this->createMock(Producer::class);
        $producer->method('newTopic')->willThrowException(new Exception('invalid topic name'));

        $kafkaProducer = $this->buildProducer($producer, logger: $logger);

        try {
            $kafkaProducer->produce(self::message());
            self::fail('Expected KafkaProducerException');
        } catch (KafkaProducerException $e) {
            self::assertSame('invalid topic name', $e->getMessage());
        }

        $errorRecords = $logger->findByMessage('Failed to produce message');
        self::assertCount(1, $errorRecords);
        self::assertSame('test-topic', $errorRecords[0]['context']['topic']);
        self::assertSame(\RD_KAFKA_PARTITION_UA, $errorRecords[0]['context']['partition']);
        self::assertNull($errorRecords[0]['context']['key']);
        self::assertSame('invalid topic name', $errorRecords[0]['context']['error']);
        self::assertSame(0, $errorRecords[0]['context']['error_code']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testProduceWrapsProducevFailure(): void
    {
        $logger = new InMemoryLogger();

        $topic = $this->createMock(ProducerTopic::class);
        $topic->method('producev')->willThrowException(new Exception('local queue full'));

        $producer = $this->createMock(Producer::class);
        $producer->method('newTopic')->willReturn($topic);

        $kafkaProducer = $this->buildProducer($producer, logger: $logger);

        try {
            $kafkaProducer->produce(self::message());
            self::fail('Expected KafkaProducerException');
        } catch (KafkaProducerException $e) {
            self::assertSame('local queue full', $e->getMessage());
        }

        $errorRecords = $logger->findByMessage('Failed to produce message');
        self::assertCount(1, $errorRecords);
        self::assertSame('local queue full', $errorRecords[0]['context']['error']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testFlushSucceedsOnFirstCallWithoutRetries(): void
    {
        $producer = $this->createMock(Producer::class);
        $producer->expects($this->once())->method('flush')->willReturn(\RD_KAFKA_RESP_ERR_NO_ERROR);

        $logger = new InMemoryLogger();

        $this->buildProducer($producer, logger: $logger)->flush(1000);

        $infoRecords = $logger->findByMessage('Producer flushed successfully');
        self::assertCount(1, $infoRecords);
        self::assertSame(1000, $infoRecords[0]['context']['timeout_ms']);
        self::assertSame(1, $infoRecords[0]['context']['attempts']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testFlushRetriesTransientTimeoutUntilSuccess(): void
    {
        // Один вызов RdKafka\Producer::flush() может вернуть TIMED_OUT
        // транзитно (установка соединения): до истечения суммарного дедлайна
        // вызов повторяется, исключение — только после исчерпания бюджета.
        $producer = $this->createMock(Producer::class);
        $producer->expects($this->exactly(3))->method('flush')->willReturnOnConsecutiveCalls(
            \RD_KAFKA_RESP_ERR__TIMED_OUT,
            \RD_KAFKA_RESP_ERR__TIMED_OUT,
            \RD_KAFKA_RESP_ERR_NO_ERROR,
        );

        $logger = new InMemoryLogger();

        $this->buildProducer($producer, logger: $logger)->flush(10_000);

        $infoRecords = $logger->findByMessage('Producer flushed successfully');
        self::assertCount(1, $infoRecords);
        self::assertSame(3, $infoRecords[0]['context']['attempts']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testFlushThrowsAfterTotalDeadlineExhausted(): void
    {
        // Мгновенные TIMED_OUT от мока крутят retry-цикл ровно до дедлайна —
        // раньше единственный таймаут сразу превращался в исключение.
        $producer = $this->createMock(Producer::class);
        $producer->method('flush')->willReturn(\RD_KAFKA_RESP_ERR__TIMED_OUT);
        $producer->method('getOutQLen')->willReturn(7);

        $logger = new InMemoryLogger();

        try {
            $this->buildProducer($producer, logger: $logger)->flush(25);
            self::fail('Expected KafkaConnectionException');
        } catch (KafkaConnectionException $e) {
            self::assertSame('Flush timed out in 25ms', $e->getMessage());
        }

        $warnings = $logger->findByMessage('Flush timed out');
        self::assertCount(1, $warnings);
        self::assertSame('warning', $warnings[0]['level']);
        self::assertSame(25, $warnings[0]['context']['timeout_ms']);
        self::assertSame(\RD_KAFKA_RESP_ERR__TIMED_OUT, $warnings[0]['context']['error_code']);
        self::assertSame(7, $warnings[0]['context']['out_queue_len']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testFlushThrowsImmediatelyOnHardError(): void
    {
        // Ошибка, отличная от таймаута, не ретраится.
        $producer = $this->createMock(Producer::class);
        $producer->expects($this->once())->method('flush')->willReturn(\RD_KAFKA_RESP_ERR__INVALID_ARG);
        $producer->method('getOutQLen')->willReturn(2);

        $logger = new InMemoryLogger();

        try {
            $this->buildProducer($producer, logger: $logger)->flush(1000);
            self::fail('Expected KafkaProducerException');
        } catch (KafkaProducerException $e) {
            self::assertSame(\sprintf(
                'Flush failed: %s (%d)',
                rd_kafka_err2str(\RD_KAFKA_RESP_ERR__INVALID_ARG),
                \RD_KAFKA_RESP_ERR__INVALID_ARG,
            ), $e->getMessage());
        }

        $errorRecords = $logger->findByMessage('Flush failed');
        self::assertCount(1, $errorRecords);
        self::assertSame(1000, $errorRecords[0]['context']['timeout_ms']);
        self::assertSame(1, $errorRecords[0]['context']['attempts']);
        self::assertSame(2, $errorRecords[0]['context']['out_queue_len']);
    }

    private static function message(): KafkaProducerMessage
    {
        return new KafkaProducerMessage(topic: 'test-topic', body: 'hello');
    }

    private function buildProducer(
        Producer $producer,
        bool $shouldPoll = false,
        ?InMemoryLogger $logger = null,
    ): KafkaProducer {
        $pollStrategy = $this->createMock(PollStrategy::class);
        $pollStrategy->method('shouldPoll')->willReturn($shouldPoll);

        $kafkaProducer = (new \ReflectionClass(KafkaProducer::class))->newInstanceWithoutConstructor();

        (new \ReflectionProperty(KafkaProducer::class, 'producer'))->setValue($kafkaProducer, $producer);
        (new \ReflectionProperty(KafkaProducer::class, 'pollStrategy'))->setValue($kafkaProducer, $pollStrategy);
        (new \ReflectionProperty(KafkaProducer::class, 'logger'))->setValue($kafkaProducer, $logger ?? new InMemoryLogger());

        return $kafkaProducer;
    }
}
