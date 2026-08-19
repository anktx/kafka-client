<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\PollStrategy;

interface PollStrategy
{
    /**
     * Пора ли клиенту сейчас опросить очередь delivery-report'ов.
     *
     * Чистый запрос без побочных эффектов: повторный вызов без предшествующего
     * markPolled() возвращает тот же ответ.
     */
    public function shouldPoll(): bool;

    /**
     * Зафиксировать факт опроса (команда): до следующего опроса shouldPoll()
     * отсчитывает паузу от этого момента. Вызывается клиентом сразу после
     * опроса очереди.
     */
    public function markPolled(): void;
}
