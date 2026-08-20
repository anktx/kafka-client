# PHP Kafka Client

Обёртка над `ext-rdkafka` для работы с Apache Kafka на PHP. Библиотека предоставляет простой и удобный интерфейс для продюсирования и консьюминга сообщений.

## Требования

- PHP 8.4+
- ext-rdkafka

## Установка

```bash
composer require anktx/kafka-client
```

## Быстрый старт

### Producer

```php
use Anktx\Kafka\Client\Config\Brokers;
use Anktx\Kafka\Client\Config\ProducerConfig;
use Anktx\Kafka\Client\Config\Enum\CompressionType;
use Anktx\Kafka\Client\KafkaProducer;
use Anktx\Kafka\Client\KafkaMessage\KafkaProducerMessage;
use Anktx\Kafka\Client\Topic\Topic;

$producer = new KafkaProducer(
    new ProducerConfig(
        brokers: new Brokers('kafka:9092'),
        compressionType: CompressionType::Snappy,
    )
);

$producer->produce(
    new KafkaProducerMessage(
        topic: new Topic('events'),
        body: json_encode(['event' => 'order_created', 'id' => 123]),
        key: 'order-123',
        headers: ['source' => 'api'],
    )
);

$producer->flush();
```

### Consumer

```php
use Anktx\Kafka\Client\Config\Brokers;
use Anktx\Kafka\Client\Config\ConsumerConfig;
use Anktx\Kafka\Client\Config\Enum\OffsetReset;
use Anktx\Kafka\Client\KafkaConsumer;
use Anktx\Kafka\Client\KafkaMessage\KafkaConsumerMessage;
use Anktx\Kafka\Client\Topic\Topic;
use Anktx\Kafka\Client\Topic\TopicList;

$consumer = new KafkaConsumer(
    new ConsumerConfig(
        brokers: new Brokers('kafka:9092'),
        groupId: 'order-processor',
        instanceId: 'worker-1',
        offsetReset: OffsetReset::Latest,
    )
);

$consumer->subscribe(
    TopicList::create(new Topic('events'))
);

while (true) {
    $result = $consumer->consume();

    if ($result instanceof KafkaConsumerMessage) {
        echo $result->body . "\n";
        // ... обработка сообщения ...

        $consumer->commit($result);
    }
}
```

### Message Stream

Для более чистого кода используйте генератор:

```php
use Anktx\Kafka\Client\KafkaMessageStream;

$stream = new KafkaMessageStream($consumer);

foreach ($stream->stream() as $message) {
    // Только сообщения, без обработки таймаутов/EOF
    echo $message->body . "\n";
    $consumer->commit($message);
}
```

По умолчанию поток переживает полную потерю брокеров (librdkafka
переподключается в фоне). Реакция на нештатные ситуации — инжектируемый
наблюдатель `StreamObserver`: каждый результат `consume()` (сообщение,
таймаут, потеря всех брокеров, EOF) передаётся его хукам
`onMessage`/`onTimeout`/`onBrokersDown`/`onEof` до выдачи сообщения
наружу, исключение из хука прерывает генератор. Дефолт
`SilentStreamObserver` поглощает всё — прежнее поведение.

Готовая fail-fast реализация — `BrokersDownBudgetStreamObserver`: если
брокеры недоступны непрерывно дольше `maxBrokersDownMs` (wall-clock,
источник времени — PSR-20 `Psr\Clock\ClockInterface`, по умолчанию
системные часы), генератор выбрасывает `KafkaBrokersDownException` —
воркер падает, супервизор пересоздаёт процесс (restart-политика Docker,
restartPolicy Kubernetes):

```php
use Anktx\Kafka\Client\StreamObserver\BrokersDownBudgetStreamObserver;

$stream = new KafkaMessageStream(
    $consumer,
    new BrokersDownBudgetStreamObserver(maxBrokersDownMs: 30_000),
);
```

Сообщение и EOF доказывают живое соединение и сбрасывают бюджет;
таймаут — нет (не отличает тишину в топике от сетевой проблемы).
Свои сценарии реакции — реализуйте интерфейс `StreamObserver`.

## Стратегии опроса (Poll Strategies)

При отправке сообщений они попадают в локальную очередь, а затем асинхронно отправляются в Kafka. Метод `poll()` обслуживает эту очередь — обрабатывает отчёты о доставке и освобождает память. Если не вызывать `poll()`, очередь может переполниться.

Стратегии определяют, когда вызывать `poll()`:

```php
use Anktx\Kafka\Client\PollStrategy\TimeoutPollStrategy;
use Anktx\Kafka\Client\PollStrategy\ProbabilityPollStrategy;

// Опрос не чаще, чем раз в N миллисекунд
$producer = new KafkaProducer(
    $config,
    new TimeoutPollStrategy(pollIntervalMs: 1000),
);

// Опрос с вероятностью N (0.0 - 1.0)
$producer = new KafkaProducer(
    $config,
    new ProbabilityPollStrategy(probability: 0.1),
);
```

**Доступные стратегии:**
- `NeverPollStrategy` — не вызывать `poll()` (по умолчанию, подходит для низкой нагрузки)
- `TimeoutPollStrategy` — вызывать `poll()` с фиксированным интервалом в миллисекундах (источник времени — PSR-20 `Psr\Clock\ClockInterface`, по умолчанию системные часы)
- `ProbabilityPollStrategy` — вызывать `poll()` с вероятностью N (например, 10% вызовов)

Ошибки доставки сообщений (delivery reports) продюсер логирует через PSR-3
на уровне error. Отчёты доставляются callback'ам только при вызове `poll()`:
со стратегиями опроса — в фоне, с `NeverPollStrategy` — только в момент `flush()`.

## Конфигурация

### ProducerConfig

```php
$config = new ProducerConfig(
    brokers: Brokers,                   // Обязательно (VO: host[:port][,...] )
    queueBufferingMaxKBytes: int,       // По умолчанию: 20480
    batchSize: int,                     // По умолчанию: 102400
    lingerMs: int,                      // По умолчанию: 10
    compressionType: CompressionType,   // По умолчанию: snappy; none — отключить сжатие
    isDebug: bool,                      // По умолчанию: false
);
```

### ConsumerConfig

```php
$config = new ConsumerConfig(
    brokers: Brokers,                   // Обязательно (VO: host[:port][,...])
    groupId: string,                    // Обязательно
    instanceId: ?string,                // По умолчанию: null
    offsetReset: OffsetReset,           // По умолчанию: earliest
    autoCommitMs: ?int,                 // По умолчанию: null (ручной коммит)
    sessionTimeoutMs: ?int,             // По умолчанию: null
    reconnectBackoffMs: ?int,           // По умолчанию: null
    reconnectBackoffMaxMs: ?int,        // По умолчанию: null
    socketKeepaliveEnable: bool,        // По умолчанию: true
    isDebug: bool,                      // По умолчанию: false
);
```

Невалидные значения отбрасываются в конструкторах `Brokers` (пустой
список или запись вне формата `host[:port]`), конфигов (пустой `groupId`,
отрицательные интервалы, `reconnectBackoffMaxMs < reconnectBackoffMs`)
и сообщений/подписок (пустое имя топика — `Topic`) исключением
`InvalidConfigException` / `InvalidTopicException`.

#### OffsetReset: политика при отсутствии закоммиченного смещения

`offsetReset` отвечает на вопрос «с чего начать чтение, если у группы
**нет** валидного закоммиченного смещения». Такое бывает, когда:

- группа новая и коммитов ещё не было (частный случай — опечатка в `groupId`);
- закоммиченный офсет устарел: данные под ним удалены retention-политикой
  или истёк срок хранения офсетов группы (`offsets.retention.minutes`).

Если валидный офсет есть — политика не активируется вовсе, все три
значения работают одинаково.

| Кейс | Значение для librdkafka | Поведение |
|---|---|---|
| `OffsetReset::Earliest` | `earliest` | сброс на начало партиции (перечитает всю историю) |
| `OffsetReset::Latest` | `latest` | сброс на конец (молча пропустит всю историю) |
| `OffsetReset::Error` | `error` | сброс запрещён: партиция переходит в ошибку `RD_KAFKA_RESP_ERR__AUTO_OFFSET_RESET`, `consume()` бросает `KafkaConsumerException` |

`OffsetReset::Error` — это strict-режим: опечатка в `groupId`, потерянная
история или невалидный офсет не проходят молча, а останавливают цикл
потребления исключением.

**Почему `Error`, а не `None`.** Одна и та же политика в разных клиентах
называется по-разному: Java-клиент и документация Kafka называют её `none`,
а librdkafka (и ext-rdkafka) — `error`; значение `none` librdkafka
отвергает как невалидное (`Invalid value "none" for configuration
property "auto.offset.reset"`). Библиотека работает через librdkafka,
поэтому кейс назван по его канону: `Error = 'error'`. Отображение имён —
в таблице выше.

### Логирование

PSR-3 логгер передаётся напрямую в конструкторы клиентов (по умолчанию `NullLogger`):

```php
use Psr\Log\LoggerInterface;

$producer = new KafkaProducer($config, logger: $logger);
$consumer = new KafkaConsumer($config, logger: $logger);
```

## Типы возвращаемых значений

Метод `consume()` возвращает union type (все варианты реализуют интерфейс
`ConsumeResult`):
- `KafkaConsumerMessage` — успешно полученное сообщение
- `KafkaConsumeTimeout` — таймаут (нет новых сообщений)
- `KafkaBrokersDown` — полная потеря соединения со всеми брокерами
  (ALL_BROKERS_DOWN): не ошибка — librdkafka переподключается в фоновых
  потоках; отдельный результат для метрик и watchdog'ов
- `KafkaPartitionEof` — достигнут конец партиции

Пример обработки — `match` по классу даёт исчерпывающую диспетчеризацию
(при появлении нового варианта union будет `UnhandledMatchError`, а не
молчаливый пропуск) и сужение типа внутри веток:

```php
$result = $consumer->consume(1000);

match ($result::class) {
    KafkaConsumerMessage::class => $consumer->commit($result),
    KafkaConsumeTimeout::class => null, // нет сообщений, можно продолжить работу
    KafkaBrokersDown::class => null,    // все брокеры недоступны, librdkafka переподключается
    KafkaPartitionEof::class => null,   // достигнут конец партиции
};
```

`ConsumeResult` — именованный supertype для хелперов, логгеров и метрик,
принимающих любой результат `consume()` целиком. Если нужна только
обработка сообщений без ветвления — см. [Message Stream](#message-stream).

## Структура проекта

```
src/
├── Clock/                           # Время
│   └── SystemClock.php              # Системные часы PSR-20 (по умолчанию)
│
├── Config/                          # Конфигурация
│   ├── Brokers.php                  # Список брокеров (VO: host[:port][,...] )
│   ├── ConsumerConfig.php           # Конфигурация консьюмера
│   ├── ProducerConfig.php           # Конфигурация продюсера
│   └── Enum/                        # Перечисления
│       ├── CompressionType.php      # Типы компрессии (none, snappy, gzip, lz4, zstd)
│       └── OffsetReset.php          # Стратегия сброса оффсета (earliest, latest, error)
│
├── ConsumeResult/                   # Результаты консьюминга
│   ├── KafkaBrokersDown.php        # Полная потеря всех брокеров (ALL_BROKERS_DOWN)
│   ├── KafkaConsumeTimeout.php      # Таймаут (нет сообщений)
│   └── KafkaPartitionEof.php        # Достигнут конец партиции
│
├── Exception/                       # Исключения
│   ├── KafkaClientException.php     # Маркерный интерфейс всех исключений библиотеки
│   ├── Kafka/                       # Сбои инфраструктуры Kafka (runtime)
│   └── Logic/                       # Детерминированные ошибки программиста
│
├── KafkaMessage/                    # Сообщения
│   ├── KafkaConsumerMessage.php     # Сообщение консьюмера (topic/partition/offset обязательны)
│   └── KafkaProducerMessage.php     # Сообщение продюсера (валидация в конструкторе)
│
├── Log/                             # Логирование
│   ├── RdKafkaCallbacks.php         # Колбэки librdkafka + единая политика логирования в PSR-3
│   └── RdKafkaLogLevel.php          # Маппинг syslog-severity librdkafka в уровни PSR-3
│
├── PollStrategy/                    # Стратегии опроса очереди
│   ├── PollStrategy.php             # Интерфейс стратегии
│   ├── NeverPollStrategy.php        # Не вызывать poll()
│   ├── ProbabilityPollStrategy.php  # Вызывать с вероятностью N
│   └── TimeoutPollStrategy.php      # Вызывать с фиксированным интервалом (мс)
│
├── StreamObserver/                  # Реакция на результаты consume() в потоке сообщений
│   ├── StreamObserver.php           # Интерфейс наблюдателя (onMessage/onTimeout/onBrokersDown/onEof)
│   ├── SilentStreamObserver.php     # Молчаливая реакция (по умолчанию)
│   └── BrokersDownBudgetStreamObserver.php # Fail-fast: брокеры недоступны дольше maxBrokersDownMs
│
├── Topic/                           # Топики
│   ├── Topic.php                    # Имя топика (VO: непустая строка)
│   └── TopicList.php                # Список топиков для подписки
│
├── KafkaConsumer.php                # Главный класс консьюмера
├── KafkaProducer.php                # Главный класс продюсера
└── KafkaMessageStream.php           # Генератор для стриминга сообщений
```

## Обработка исключений

Библиотека использует иерархию из двух семейств и маркерного интерфейса:

```
KafkaClientException                 # Маркер: всё, что кидает библиотека (interface, extends \Throwable)
├── KafkaException                   # Сбои Kafka/окружения (наследует RdKafka\Exception)
│   ├── KafkaBrokersDownException    # Брокеры недоступны дольше maxBrokersDownMs (BrokersDownBudgetStreamObserver)
│   ├── KafkaConsumerException        # Ошибка консьюмера
│   ├── KafkaFlushTimeoutException    # Таймаут flush: очередь не отправлена за $timeoutMs
│   └── KafkaProducerException        # Ошибка продюсера
└── LogicException                   # Ошибки программиста (наследует \LogicException)
    ├── ClientClosedException         # Операция после close() клиента
    ├── EmptySubscriptionsException   # Пустой список подписок
    ├── InvalidConfigException        # Невалидная конфигурация или параметры
    ├── InvalidMessageException       # Невалидные свойства сообщения (partition, timestampMs)
    ├── InvalidTopicException         # Пустое имя топика (Topic VO)
    └── NotSubscribedException        # Не подписан на топики
```

Точки поимки:
- `catch (KafkaClientException)` — всё, что кидает библиотека;
- `catch (KafkaException)` / `catch (RdKafka\Exception)` — только сбои Kafka,
  без опечаток в конфиге;
- `catch (\LogicException)` — детерминированные ошибки использования
  (невалидный конфиг, неверный порядок вызовов).

Пример обработки:

```php
try {
    $producer->produce($message);
    $producer->flush();
} catch (KafkaFlushTimeoutException $e) {
    // Flush не успел за $timeoutMs: часть сообщений могла остаться
    // в локальной очереди продюсера — статус доставки неизвестен
} catch (KafkaProducerException $e) {
    // Ошибка отправки сообщения
} catch (KafkaClientException $e) {
    // Всё остальное от библиотеки
}
```

### Жизненный цикл консьюмера

`KafkaConsumer::close()` идемпотентен; операции после закрытия бросают
`ClientClosedException` (ошибка программирования, не ретраить).
Подробно, с обоснованием и примером шаблона:
[docs/lifecycle.md](docs/lifecycle.md).
