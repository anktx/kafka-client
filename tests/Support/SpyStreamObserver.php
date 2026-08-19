<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\Support;

use Anktx\Kafka\Client\ConsumeResult\KafkaBrokersDown;
use Anktx\Kafka\Client\ConsumeResult\KafkaConsumeTimeout;
use Anktx\Kafka\Client\ConsumeResult\KafkaPartitionEof;
use Anktx\Kafka\Client\KafkaMessage\KafkaConsumerMessage;
use Anktx\Kafka\Client\KafkaMessageStream;
use Anktx\Kafka\Client\StreamObserver\StreamObserver;

/**
 * Записывающий наблюдатель для проверки диспетчеризации результатов
 * в {@see KafkaMessageStream}: сохраняет каждый
 * полученный объект, ничего не бросает.
 */
final class SpyStreamObserver implements StreamObserver
{
    /** @var list<KafkaConsumerMessage> */
    public array $messages = [];

    /** @var list<KafkaConsumeTimeout> */
    public array $timeouts = [];

    /** @var list<KafkaBrokersDown> */
    public array $brokersDown = [];

    /** @var list<KafkaPartitionEof> */
    public array $eofs = [];

    public function onMessage(KafkaConsumerMessage $message): void
    {
        $this->messages[] = $message;
    }

    public function onTimeout(KafkaConsumeTimeout $timeout): void
    {
        $this->timeouts[] = $timeout;
    }

    public function onBrokersDown(KafkaBrokersDown $brokersDown): void
    {
        $this->brokersDown[] = $brokersDown;
    }

    public function onEof(KafkaPartitionEof $eof): void
    {
        $this->eofs[] = $eof;
    }
}
