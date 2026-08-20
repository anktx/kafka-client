<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\Support;

use Anktx\Kafka\Client\KafkaConsumer;

/**
 * Фабрика KafkaConsumer без вызова конструктора (конструктор создаёт
 * живого RdKafka-клиента): mock RdKafka\KafkaConsumer и логгер
 * инъектируются в приватные readonly-свойства через reflection.
 */
final class KafkaConsumers
{
    private function __construct() {}

    public static function build(\RdKafka\KafkaConsumer $rdKafka, ?InMemoryLogger $logger = null): KafkaConsumer
    {
        $consumer = new \ReflectionClass(KafkaConsumer::class)->newInstanceWithoutConstructor();

        new \ReflectionProperty(KafkaConsumer::class, 'consumer')->setValue($consumer, $rdKafka);
        new \ReflectionProperty(KafkaConsumer::class, 'logger')->setValue($consumer, $logger ?? new InMemoryLogger());

        return $consumer;
    }
}
