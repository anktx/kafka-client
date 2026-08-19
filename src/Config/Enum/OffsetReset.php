<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\Config\Enum;

enum OffsetReset: string
{
    case Earliest = 'earliest';
    case Latest = 'latest';

    /**
     * Запрещает автоматический сброс смещения (strict-режим).
     *
     * Политика активируется только если у группы нет валидного
     * закоммиченного смещения — новая группа (в т.ч. опечатка в groupId),
     * офсет удалён retention-политикой или истёк offsets.retention.minutes.
     * В этом случае партиция переводится в состояние ошибки
     * RD_KAFKA_RESP_ERR__AUTO_OFFSET_RESET, и consume() бросает
     * KafkaConsumerException вместо молчаливого пропуска истории (Latest)
     * или повторного чтения с начала (Earliest).
     *
     * Бэкинг-значение `error` — канон для librdkafka; в терминологии
     * Kafka-протокола и Java-клиента та же политика называется `none`,
     * но librdkafka значение `none` отвергает как невалидное.
     */
    case Error = 'error';
}
