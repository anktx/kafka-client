<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Config\Enum;

enum OffsetReset
{
    case earliest;
    case latest;
    case none;
}
