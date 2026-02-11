<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Config\Enum;

enum OffsetReset: string
{
    case earliest = 'earliest';
    case latest = 'latest';
    case none = 'none';
}
