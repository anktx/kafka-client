<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\ConsumeResult;

/**
 * Общий тип результатов consume().
 *
 * Полезная нагрузка различается между вариантами, поэтому consume()
 * возвращает точный union — сужение через match ($result::class) или
 * instanceof. Интерфейс даёт именованный supertype для хелперов,
 * логгеров и метрик, а kind() — стабильный словарь вариантов.
 */
interface ConsumeResult
{
    public function kind(): ConsumeResultKind;
}
