<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\KafkaMessage;

use Anktx\Kafka\Client\ConsumeResult\ConsumeResult;
use Anktx\Kafka\Client\ConsumeResult\ConsumeResultKind;

final class KafkaConsumerMessage extends AbstractMessage implements ConsumeResult
{
    public function kind(): ConsumeResultKind
    {
        return ConsumeResultKind::Message;
    }
}
