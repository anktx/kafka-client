<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Topic;

use Anktx\Kafka\Client\Exception\Logic\InvalidTopicException;

/**
 * Имя топика Kafka как value object.
 *
 * Инвариант «непустое имя» проверяется в конструкторе и замещает
 * одинаковые inline-проверки в KafkaProducerMessage и
 * KafkaConsumerMessage: тип Topic гарантирует валидное имя везде,
 * где используется, — без конвенции «не забыть проверить».
 */
final readonly class Topic
{
    /**
     * @throws InvalidTopicException Если имя — пустая строка
     */
    public function __construct(
        public string $name,
    ) {
        if ($this->name === '') {
            throw InvalidTopicException::emptyName();
        }
    }
}
