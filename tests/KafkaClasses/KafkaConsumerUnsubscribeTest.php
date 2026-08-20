<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\KafkaClasses;

use Anktx\Kafka\Client\Exception\Kafka\KafkaConsumerException;
use Anktx\Kafka\Client\KafkaConsumer;
use Anktx\Kafka\Client\Tests\Support\InMemoryLogger;
use Anktx\Kafka\Client\Tests\Support\KafkaConsumers;
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

        KafkaConsumers::build($rdKafka, $logger)->unsubscribe();

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
            KafkaConsumers::build($rdKafka, $logger)->unsubscribe();
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
}
