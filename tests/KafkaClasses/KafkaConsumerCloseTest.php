<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\KafkaClasses;

use Anktx\Kafka\Client\Exception\Kafka\KafkaConsumerException;
use Anktx\Kafka\Client\Exception\Logic\ClientClosedException;
use Anktx\Kafka\Client\KafkaConsumer;
use Anktx\Kafka\Client\KafkaMessage\KafkaConsumerMessage;
use Anktx\Kafka\Client\Tests\Support\InMemoryLogger;
use Anktx\Kafka\Client\TopicSubscription\TopicSubscriptionList;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use RdKafka\Exception;

/**
 * Юнит-тесты жизненного цикла {@see KafkaConsumer::close()}: close()
 * идемпотентен и оборачивает ошибки RdKafka, а любые операции после
 * закрытия отвергаются до вызовов RdKafka (раньше use-after-close
 * молча делегировался librdkafka).
 */
final class KafkaConsumerCloseTest extends TestCase
{
    #[AllowMockObjectsWithoutExpectations]
    public function testCloseDelegatesToRdKafkaOnceAndIsIdempotent(): void
    {
        $logger = new InMemoryLogger();

        $rdKafka = $this->createMock(\RdKafka\KafkaConsumer::class);
        $rdKafka->expects($this->once())->method('close');

        $consumer = $this->buildConsumer($rdKafka, $logger);

        $consumer->close();
        $consumer->close();

        self::assertCount(1, $logger->findByMessage('Closing KafkaConsumer'));
        self::assertCount(1, $logger->findByMessage('KafkaConsumer closed'));
        self::assertCount(1, $logger->findByMessage('KafkaConsumer already closed'));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testCloseWrapsRdKafkaException(): void
    {
        $logger = new InMemoryLogger();

        $rdKafka = $this->createMock(\RdKafka\KafkaConsumer::class);
        $rdKafka->method('close')->willThrowException(new Exception('close failed'));

        try {
            $this->buildConsumer($rdKafka, $logger)->close();
            self::fail('Expected KafkaConsumerException');
        } catch (KafkaConsumerException $e) {
            self::assertSame('close failed', $e->getMessage());
        }

        $errorRecords = $logger->findByMessage('Failed to close KafkaConsumer');
        self::assertCount(1, $errorRecords);
        self::assertSame('close failed', $errorRecords[0]['context']['error']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSubscribeAfterCloseThrowsClientClosed(): void
    {
        $this->assertMethodRejectedAfterClose('subscribe', static fn(KafkaConsumer $c) => $c->subscribe(
            TopicSubscriptionList::create('test-topic'),
        ));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testUnsubscribeAfterCloseThrowsClientClosed(): void
    {
        $this->assertMethodRejectedAfterClose('unsubscribe', static fn(KafkaConsumer $c) => $c->unsubscribe());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testConsumeAfterCloseThrowsClientClosed(): void
    {
        $this->assertMethodRejectedAfterClose('consume', static fn(KafkaConsumer $c) => $c->consume(100));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testCommitAfterCloseThrowsClientClosed(): void
    {
        $message = new KafkaConsumerMessage(
            topic: 'test-topic',
            body: 'hello',
            partition: 0,
            offset: 42,
        );

        $this->assertMethodRejectedAfterClose('commit', static fn(KafkaConsumer $c) => $c->commit($message));
    }

    /**
     * Фиксирует контракт use-after-close: warning-лог + ClientClosedException
     * до единого вызова RdKafka (проверяется на getSubscription/consume и др.
     * через ->never() expectations в моке).
     *
     * @param \Closure(KafkaConsumer): mixed $operation
     */
    private function assertMethodRejectedAfterClose(string $method, \Closure $operation): void
    {
        $fullMethod = KafkaConsumer::class . '::' . $method;
        $logger = new InMemoryLogger();

        $rdKafka = $this->createMock(\RdKafka\KafkaConsumer::class);
        $rdKafka->expects($this->once())->method('close');
        $rdKafka->expects($this->never())->method('subscribe');
        $rdKafka->expects($this->never())->method('unsubscribe');
        $rdKafka->expects($this->never())->method('getSubscription');
        $rdKafka->expects($this->never())->method('consume');
        $rdKafka->expects($this->never())->method('commit');

        $consumer = $this->buildConsumer($rdKafka, $logger);

        $consumer->close();

        try {
            $operation($consumer);
            self::fail('Expected ClientClosedException');
        } catch (ClientClosedException $e) {
            self::assertSame(\sprintf('Cannot call %s(): the client is closed', $fullMethod), $e->getMessage());
        }

        $warnings = $logger->findByMessage('Attempted to use a closed KafkaConsumer');
        self::assertCount(1, $warnings);
        self::assertSame($fullMethod, $warnings[0]['context']['method']);
    }

    /**
     * Собирает KafkaConsumer без вызова конструктора и инжектит mock
     * RdKafka\KafkaConsumer в приватное свойство.
     */
    private function buildConsumer(\RdKafka\KafkaConsumer $rdKafka, ?InMemoryLogger $logger = null): KafkaConsumer
    {
        $consumer = (new \ReflectionClass(KafkaConsumer::class))->newInstanceWithoutConstructor();

        (new \ReflectionProperty(KafkaConsumer::class, 'consumer'))->setValue($consumer, $rdKafka);
        (new \ReflectionProperty(KafkaConsumer::class, 'logger'))->setValue($consumer, $logger ?? new InMemoryLogger());

        return $consumer;
    }
}
