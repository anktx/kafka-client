<?php

declare(strict_types=1);

namespace Anktx\Kafka\Client;

use Anktx\Kafka\Client\ConsumeResult\KafkaBrokersDown;
use Anktx\Kafka\Client\ConsumeResult\KafkaConsumeTimeout;
use Anktx\Kafka\Client\ConsumeResult\KafkaPartitionEof;
use Anktx\Kafka\Client\Exception\Kafka\KafkaConsumerException;
use Anktx\Kafka\Client\Exception\Logic\ClientClosedException;
use Anktx\Kafka\Client\Exception\Logic\EmptySubscriptionsException;
use Anktx\Kafka\Client\Exception\Logic\InvalidConfigException;
use Anktx\Kafka\Client\Exception\Logic\NotSubscribedException;
use Anktx\Kafka\Client\KafkaMessage\KafkaConsumerMessage;
use Anktx\Kafka\Client\Topic\TopicList;

/**
 * Контракт консьюмера Apache Kafka.
 *
 * Выделен из final-класса {@see KafkaConsumer}: сигнатуры методов оперируют
 * только типами библиотеки (`TopicList`, `KafkaConsumerMessage`,
 * `ConsumeResult\*`) и не содержат RdKafka-типов, поэтому контракт (а с ним
 * и моки PHPUnit) загружается в окружениях без ext-rdkafka. Downstream-код
 * тайп-хинтит интерфейс и подменяет его тест-двойниками, не загружая
 * реализацию.
 */
interface KafkaConsumerInterface
{
    /** Таймаут consume() по умолчанию, мс. */
    public const int DEFAULT_CONSUME_TIMEOUT_MS = 1000;

    /**
     * Подписывается на топики.
     *
     * Операция локальная: недоступность брокеров здесь не видна — она
     * проявится позже через consume(). Партиции и смещения назначает сам
     * брокер через rebalance, поэтому подписка задаётся только именем
     * топика. Повторный вызов не объединяется со старым — он заменяет
     * подписку полным списком.
     *
     * @param TopicList $subscriptionList Список подписок на топики
     *
     * @throws ClientClosedException       Если консьюмер закрыт через close()
     * @throws EmptySubscriptionsException Если список подписок пуст
     * @throws KafkaConsumerException      Если librdkafka не принял подписку
     */
    public function subscribe(TopicList $subscriptionList): void;

    /**
     * Отписывается от всех топиков.
     *
     * @throws ClientClosedException  Если консьюмер закрыт через close()
     * @throws KafkaConsumerException Если не удалось отписаться
     */
    public function unsubscribe(): void;

    /**
     * Читает одно сообщение, блокируясь до его получения или истечения таймаута.
     *
     * Возможные результаты:
     * - {@see KafkaConsumerMessage} - сообщение;
     * - {@see KafkaConsumeTimeout} - таймаут (за окно опроса не пришло
     *   сообщений);
     * - {@see KafkaBrokersDown} - полная потеря соединения со всеми
     *   брокерами: не ошибка, переподключение продолжается в фоне;
     * - {@see KafkaPartitionEof} - достигнут конец партиции.
     *
     * @param int $timeoutMs Таймаут ожидания в миллисекундах (по умолчанию 1000 мс)
     *
     * @return KafkaBrokersDown|KafkaConsumerMessage|KafkaConsumeTimeout|KafkaPartitionEof Результат чтения
     *
     * @throws ClientClosedException  Если консьюмер закрыт через close()
     * @throws InvalidConfigException Если таймаут отрицательный
     * @throws NotSubscribedException Если консьюмер не подписан на топики
     * @throws KafkaConsumerException Если чтение завершилось ошибкой
     */
    public function consume(int $timeoutMs = self::DEFAULT_CONSUME_TIMEOUT_MS): KafkaBrokersDown|KafkaConsumerMessage|KafkaConsumeTimeout|KafkaPartitionEof;

    /**
     * Коммитит смещение обработанного сообщения.
     *
     * Фиксирует в Kafka offset, следующий за сообщением, — после этого группа
     * не получит его повторно. Коммит синхронный: вызов блокируется до
     * подтверждения брокера.
     *
     * @param KafkaConsumerMessage $message Обработанное сообщение
     *
     * @throws ClientClosedException  Если консьюмер закрыт через close()
     * @throws KafkaConsumerException Если коммит не удался
     */
    public function commit(KafkaConsumerMessage $message): void;

    /**
     * Закрывает консьюмер и освобождает ресурсы.
     *
     * Идемпотентен: повторные вызовы — no-op. После закрытия все методы
     * (кроме close()) бросают {@see ClientClosedException}.
     *
     * @throws KafkaConsumerException Если librdkafka не смог закрыть консьюмера
     *                                (консьюмер остаётся открытым — вызов можно повторить)
     */
    public function close(): void;
}
