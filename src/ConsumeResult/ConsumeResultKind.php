<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\ConsumeResult;

enum ConsumeResultKind
{
    case Message;
    case Timeout;
    case PartitionEof;
}
