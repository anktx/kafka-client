<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Tests\Support;

use RdKafka\Message;

/**
 * Фабрика двойников RdKafka\Message: ext-класс с нативно типизированными
 * свойствами без дефолтов, поэтому двойник собирается присваиванием
 * нужного подмножества полей.
 */
final class RdKafkaMessages
{
    private function __construct() {}

    /**
     * @param array<string, mixed> $values Значения свойств по имени
     */
    public static function fromValues(array $values): Message
    {
        $message = new Message();
        foreach ($values as $name => $value) {
            // @phpstan-ignore property.dynamicName
            $message->{$name} = $value;
        }

        return $message;
    }
}
