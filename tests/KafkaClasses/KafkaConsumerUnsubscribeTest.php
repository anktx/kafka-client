<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\KafkaClasses;

use Anktx\Kafka\Client\Exception\Kafka\KafkaConsumerException;
use Anktx\Kafka\Client\KafkaConsumer;
use Anktx\Kafka\Client\Tests\Support\InMemoryLogger;
use PHPUnit\Framework\TestCase;
use RdKafka\Exception;

/**
 * Юнит-тесты {@see KafkaConsumer::unsubscribe()}: happy path делегирует
 * RdKafka ровно один раз и пишет info-лог; ошибка RdKafka оборачивается
 * в KafkaConsumerException с сохранением предыдущего исключения и
 * контекстом в лог. (Ранее был закрыт только use-after-close в
 * KafkaConsumerCloseTest, а сам happy path не покрывался ничем.).
 */
final class KafkaConsumerUnsubscribeTest extends TestCase
{
    public function testUnsubscribeDelegatesToRdKafkaOnceAndWritesInfoLog(): void
    {
        $logger = new InMemoryLogger();

        $rdKafka = $this->createMock(\RdKafka\KafkaConsumer::class);
        $rdKafka->expects($this->once())->method('unsubscribe');

        $this->buildConsumer($rdKafka, $logger)->unsubscribe();

        $infoRecords = $logger->findByMessage('Unsubscribed from all topics');
        self::assertCount(1, $infoRecords);
        self::assertSame('info', $infoRecords[0]['level']);
    }

    public function testUnsubscribeWrapsRdKafkaExceptionAndLogsContext(): void
    {
        $logger = new InMemoryLogger();

        $rdKafka = $this->createMock(\RdKafka\KafkaConsumer::class);
        $rdKafka->expects($this->once())
            ->method('unsubscribe')
            ->willThrowException($failure = new Exception('unsubscribe failed'))
        ;

        try {
            $this->buildConsumer($rdKafka, $logger)->unsubscribe();
            self::fail('Expected KafkaConsumerException');
        } catch (KafkaConsumerException $e) {
            self::assertSame('unsubscribe failed', $e->getMessage());
            self::assertSame($failure, $e->getPrevious());
        }

        $errorRecords = $logger->findByMessage('Failed to unsubscribe');
        self::assertCount(1, $errorRecords);
        self::assertSame('unsubscribe failed', $errorRecords[0]['context']['reason']);
        self::assertSame($failure, $errorRecords[0]['context']['exception']);
    }

    /**
     * Собирает KafkaConsumer без вызова конструктора (чтобы избежать реального
     * подключения к брокерам) и инжектит mock RdKafka\KafkaConsumer в приватные
     * свойства.
     */
    private function buildConsumer(\RdKafka\KafkaConsumer $rdKafka, InMemoryLogger $logger): KafkaConsumer
    {
        $consumer = (new \ReflectionClass(KafkaConsumer::class))->newInstanceWithoutConstructor();

        (new \ReflectionProperty(KafkaConsumer::class, 'consumer'))->setValue($consumer, $rdKafka);
        (new \ReflectionProperty(KafkaConsumer::class, 'logger'))->setValue($consumer, $logger);

        return $consumer;
    }
}
