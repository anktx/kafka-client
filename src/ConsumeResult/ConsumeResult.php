<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\ConsumeResult;

/**
 * Общий supertype результатов consume().
 *
 * Полезная нагрузка различается между вариантами, поэтому consume()
 * возвращает точный union, а единственный механизм дискриминации —
 * сужение типа через match ($result::class) или instanceof. Интерфейс
 * даёт именованный supertype для хелперов, логгеров и метрик.
 */
interface ConsumeResult {}
