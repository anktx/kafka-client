<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Exception\Logic;

use Anktx\Kafka\Client\KafkaMessage\KafkaConsumerMessage;

final class InvalidMessageException extends LogicException
{
    public static function noOffset(KafkaConsumerMessage $message): self
    {
        return new self(\sprintf(
            'Message from topic "%s" (partition %d) has no offset and cannot be committed',
            $message->topic,
            $message->partition,
        ));
    }
}
