<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\ConsumeResult;

use Anktx\Kafka\Client\ConsumeResult\ConsumeResult;
use Anktx\Kafka\Client\ConsumeResult\ConsumeResultKind;
use Anktx\Kafka\Client\ConsumeResult\KafkaConsumeTimeout;
use Anktx\Kafka\Client\ConsumeResult\KafkaPartitionEof;
use Anktx\Kafka\Client\KafkaMessage\KafkaConsumerMessage;
use PHPUnit\Framework\TestCase;

final class ConsumeResultTest extends TestCase
{
    public function testEveryConsumeResultVariantImplementsContractWithOwnKind(): void
    {
        $message = new KafkaConsumerMessage(topic: 'test-topic');
        $timeout = new KafkaConsumeTimeout();
        $eof = new KafkaPartitionEof(topic: 'test-topic', partition: 1, offset: 100);

        self::assertInstanceOf(ConsumeResult::class, $message);
        self::assertInstanceOf(ConsumeResult::class, $timeout);
        self::assertInstanceOf(ConsumeResult::class, $eof);

        self::assertSame(ConsumeResultKind::Message, $message->kind());
        self::assertSame(ConsumeResultKind::Timeout, $timeout->kind());
        self::assertSame(ConsumeResultKind::PartitionEof, $eof->kind());
    }

    public function testKindDictionaryMirrorsConsumeUnion(): void
    {
        self::assertSame(
            ['Message', 'Timeout', 'PartitionEof'],
            array_map(static fn(ConsumeResultKind $kind): string => $kind->name, ConsumeResultKind::cases()),
        );
    }
}
