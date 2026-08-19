<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client\ConsumeResult;

use Anktx\Kafka\Client\StreamObserver\BrokersDownBudgetStreamObserver;

/**
 * Маркер полной потери соединения со всеми брокерами (ALL_BROKERS_DOWN).
 *
 * Не ошибка: librdkafka продолжает переподключение в фоновых потоках,
 * при восстановлении связи потребление продолжится с закоммиченных
 * смещений. Отличается от {@see KafkaConsumeTimeout} тем, что это диагноз
 * «прямо сейчас нет ни одного живого соединения», а не «за окно опроса
 * не пришло сообщений». «Вечная» ли потеря — изнутри клиента неопределимо:
 * порог остановки — политика вызывающего кода (см. {@see BrokersDownBudgetStreamObserver}),
 * а не факт, известный библиотеке.
 */
final readonly class KafkaBrokersDown implements ConsumeResult {}
