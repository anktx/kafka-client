<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\KafkaClasses;

use Anktx\Kafka\Client\ConsumeResult\KafkaConsumeTimeout;
use Anktx\Kafka\Client\ConsumeResult\KafkaPartitionEof;
use Anktx\Kafka\Client\Exception\Logic\NotSubscribedException;
use Anktx\Kafka\Client\KafkaConsumer;
use Anktx\Kafka\Client\KafkaMessage\KafkaConsumerMessage;
use Anktx\Kafka\Client\Tests\Support\InMemoryLogger;
use PHPUnit\Framework\TestCase;
use RdKafka\Message;

/**
 * Юнит-тесты для {@see KafkaConsumer::consumeMatch()}: dispatch по классу
 * результата consume() должен вызвать ровно тот callback, который
 * соответствует результату, и передать ему сам результат.
 *
 * Каждый arm match покрывается отдельным тестом (регрессия для
 * MatchArmRemoval), guard'ы consume() пробрасываются без изменений.
 */
final class KafkaConsumerConsumeMatchTest extends TestCase
{
    public function testConsumeMatchPassesMessageToOnMessage(): void
    {
        $rdKafka = $this->createMock(\RdKafka\KafkaConsumer::class);
        $rdKafka->method('getSubscription')->willReturn(['test-topic']);
        $rdKafka->expects($this->once())
            ->method('consume')
            ->with(150)
            ->willReturn(self::message([
                'err' => \RD_KAFKA_RESP_ERR_NO_ERROR,
                'topic_name' => 'test-topic',
                'partition' => 3,
                'offset' => 42,
                'payload' => 'hello',
                'key' => 'k',
                'headers' => ['h' => 'v'],
                'timestamp' => 1234,
            ]))
        ;

        $result = $this->buildConsumer($rdKafka)->consumeMatch(
            onMessage: static fn(KafkaConsumerMessage $message): string => $message->body ?? 'null-body',
            onTimeout: static fn(KafkaConsumeTimeout $_): string => 'timeout',
            onEof: static fn(KafkaPartitionEof $_): string => 'eof',
            timeoutMs: 150,
        );

        self::assertSame('hello', $result);
    }

    public function testConsumeMatchPassesTimeoutResultToOnTimeout(): void
    {
        $rdKafka = $this->createMock(\RdKafka\KafkaConsumer::class);
        $rdKafka->method('getSubscription')->willReturn(['test-topic']);
        $rdKafka->expects($this->once())
            ->method('consume')
            ->willReturn(self::message([
                'err' => \RD_KAFKA_RESP_ERR__TIMED_OUT,
                'partition' => 0,
                'offset' => 0,
            ]))
        ;

        $result = $this->buildConsumer($rdKafka)->consumeMatch(
            onMessage: static fn(KafkaConsumerMessage $_): string => 'message',
            onTimeout: static fn(KafkaConsumeTimeout $timeout): string => $timeout::class,
            onEof: static fn(KafkaPartitionEof $_): string => 'eof',
        );

        self::assertSame(KafkaConsumeTimeout::class, $result);
    }

    public function testConsumeMatchPassesPartitionEofResultToOnEof(): void
    {
        $rdKafka = $this->createMock(\RdKafka\KafkaConsumer::class);
        $rdKafka->method('getSubscription')->willReturn(['test-topic']);
        $rdKafka->expects($this->once())
            ->method('consume')
            ->willReturn(self::message([
                'err' => \RD_KAFKA_RESP_ERR__PARTITION_EOF,
                'topic_name' => 'test-topic',
                'partition' => 1,
                'offset' => 7,
            ]))
        ;

        $result = $this->buildConsumer($rdKafka)->consumeMatch(
            onMessage: static fn(KafkaConsumerMessage $_): string => 'message',
            onTimeout: static fn(KafkaConsumeTimeout $_): string => 'timeout',
            onEof: static fn(KafkaPartitionEof $eof): string => \sprintf('%s:%d:%d', $eof->topic, $eof->partition, $eof->offset),
        );

        self::assertSame('test-topic:1:7', $result);
    }

    public function testConsumeMatchUsesDefaultTimeoutWhenOmitted(): void
    {
        $rdKafka = $this->createMock(\RdKafka\KafkaConsumer::class);
        $rdKafka->method('getSubscription')->willReturn(['test-topic']);
        $rdKafka->expects($this->once())
            ->method('consume')
            // KafkaConsumer::DEFAULT_CONSUME_TIMEOUT_MS = 1000: литерал пинсует
            // дефолт, мутации константы (999/1001) детектируются здесь.
            ->with(1000)
            ->willReturn(self::message([
                'err' => \RD_KAFKA_RESP_ERR__TIMED_OUT,
                'partition' => 0,
                'offset' => 0,
            ]))
        ;

        $result = $this->buildConsumer($rdKafka)->consumeMatch(
            onMessage: static fn(KafkaConsumerMessage $_): string => 'message',
            onTimeout: static fn(KafkaConsumeTimeout $_): string => 'timeout',
            onEof: static fn(KafkaPartitionEof $_): string => 'eof',
        );

        self::assertSame('timeout', $result);
    }

    public function testConsumeMatchPropagatesNotSubscribedGuard(): void
    {
        $rdKafka = $this->createMock(\RdKafka\KafkaConsumer::class);
        $rdKafka->method('getSubscription')->willReturn([]);
        $rdKafka->expects($this->never())->method('consume');

        $this->expectException(NotSubscribedException::class);

        $this->buildConsumer($rdKafka)->consumeMatch(
            onMessage: static fn(KafkaConsumerMessage $_): string => 'message',
            onTimeout: static fn(KafkaConsumeTimeout $_): string => 'timeout',
            onEof: static fn(KafkaPartitionEof $_): string => 'eof',
        );
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

    /**
     * Собирает KafkaConsumer без вызова конструктора (чтобы избежать реального
     * подключения к брокерам) и инжектит mock RdKafka\KafkaConsumer в приватные
     * свойства.
     */
    private function buildConsumer(
        \RdKafka\KafkaConsumer $rdKafka,
        ?InMemoryLogger $logger = null,
    ): KafkaConsumer {
        $consumer = (new \ReflectionClass(KafkaConsumer::class))->newInstanceWithoutConstructor();

        (new \ReflectionProperty(KafkaConsumer::class, 'consumer'))->setValue($consumer, $rdKafka);
        (new \ReflectionProperty(KafkaConsumer::class, 'logger'))->setValue($consumer, $logger ?? new InMemoryLogger());

        return $consumer;
    }
}
