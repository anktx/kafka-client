<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Config\Enum;

enum OffsetReset: string
{
    case earliest = 'earliest';
    case latest = 'latest';

    /**
     * Семантика Kafka-протокола none: без сохранённого смещения — ошибка.
     * librdkafka называет это значение `error`.
     */
    case none = 'error';
}
