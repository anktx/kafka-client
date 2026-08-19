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
use Anktx\Kafka\Client\Config\ProducerConfig;
use Anktx\Kafka\Client\Config\Enum\CompressionType;
use Anktx\Kafka\Client\KafkaProducer;
use Anktx\Kafka\Client\KafkaMessage\KafkaProducerMessage;

$producer = new KafkaProducer(
    new ProducerConfig(
        brokers: 'kafka:9092',
        compressionType: CompressionType::snappy,
    )
);

$producer->produce(
    new KafkaProducerMessage(
        topic: 'events',
        body: json_encode(['event' => 'order_created', 'id' => 123]),
        key: 'order-123',
        headers: ['source' => 'api'],
    )
);

$producer->flush();
```

### Consumer

```php
use Anktx\Kafka\Client\Config\ConsumerConfig;
use Anktx\Kafka\Client\Config\Enum\OffsetReset;
use Anktx\Kafka\Client\ConsumeResult\KafkaConsumeTimeout;
use Anktx\Kafka\Client\KafkaConsumer;
use Anktx\Kafka\Client\KafkaMessage\KafkaConsumerMessage;
use Anktx\Kafka\Client\TopicSubscription\TopicSubscription;
use Anktx\Kafka\Client\TopicSubscription\TopicSubscriptionList;

$consumer = new KafkaConsumer(
    new ConsumerConfig(
        brokers: 'kafka:9092',
        groupId: 'order-processor',
        instanceId: 'worker-1',
        offsetReset: OffsetReset::latest,
    )
);

$consumer->subscribe(
    new TopicSubscriptionList(
        new TopicSubscription(topic: 'events'),
    )
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

## Стратегии опроса (Poll Strategies)

При отправке сообщений они попадают в локальную очередь, а затем асинхронно отправляются в Kafka. Метод `poll()` обслуживает эту очередь — обрабатывает отчёты о доставке и освобождает память. Если не вызывать `poll()`, очередь может переполниться.

Стратегии определяют, когда вызывать `poll()`:

```php
use Anktx\Kafka\Client\PollStrategy\TimeoutPollStrategy;
use Anktx\Kafka\Client\PollStrategy\ProbabilityPollStrategy;

// Опрос каждые N секунд
$producer = new KafkaProducer(
    $config,
    new TimeoutPollStrategy(pollIntervalSec: 1),
);

// Опрос с вероятностью N (0.0 - 1.0)
$producer = new KafkaProducer(
    $config,
    new ProbabilityPollStrategy(probability: 0.1),
);
```

**Доступные стратегии:**
- `NeverPollStrategy` — не вызывать `poll()` (по умолчанию, подходит для низкой нагрузки)
- `TimeoutPollStrategy` — вызывать `poll()` каждые N секунд
- `ProbabilityPollStrategy` — вызывать `poll()` с вероятностью N (например, 10% вызовов)

Ошибки доставки сообщений (delivery reports) продюсер логирует через PSR-3
на уровне error. Отчёты доставляются callback'ам только при вызове `poll()`:
со стратегиями опроса — в фоне, с `NeverPollStrategy` — только в момент `flush()`.

## Конфигурация

### ProducerConfig

```php
$config = new ProducerConfig(
    brokers: string,                    // Обязательно
    queueBufferingMaxKBytes: int,       // По умолчанию: 20480
    batchSize: int,                     // По умолчанию: 102400
    lingerMs: int,                      // По умолчанию: 10
    compressionType: CompressionType,   // По умолчанию: snappy
    isDebug: bool,                      // По умолчанию: false
);
```

### ConsumerConfig

```php
$config = new ConsumerConfig(
    brokers: string,                    // Обязательно
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

Невалидные значения (пустые `brokers`/`groupId`, отрицательные интервалы,
`reconnectBackoffMaxMs < reconnectBackoffMs`) отбрасываются в конструкторе
исключением `InvalidConfigException`.

### Логирование

PSR-3 логгер передаётся напрямую в конструкторы клиентов (по умолчанию `NullLogger`):

```php
use Psr\Log\LoggerInterface;

$producer = new KafkaProducer($config, logger: $logger);
$consumer = new KafkaConsumer($config, logger: $logger);
```

## Типы возвращаемых значений

Метод `consume()` возвращает union type:
- `KafkaConsumerMessage` — успешно полученное сообщение
- `KafkaConsumeTimeout` — таймаут (нет новых сообщений)
- `KafkaPartitionEof` — достигнут конец партиции

Пример обработки:

```php
$result = $consumer->consume(1000);

if ($result instanceof KafkaConsumerMessage) {
    // Обработка сообщения
    $consumer->commit($result);
} elseif ($result instanceof KafkaConsumeTimeout) {
    // Нет сообщений, можно продолжить работу
}
```

## Структура проекта

```
src/
├── Config/                          # Конфигурация
│   ├── ConsumerConfig.php           # Конфигурация консьюмера
│   ├── ProducerConfig.php           # Конфигурация продюсера
│   └── Enum/                        # Перечисления
│       ├── CompressionType.php      # Типы компрессии (snappy, gzip, lz4, zstd)
│       └── OffsetReset.php          # Стратегия сброса оффсета (latest, earliest)
│
├── ConsumeResult/                   # Результаты консьюминга
│   ├── KafkaConsumeTimeout.php      # Таймаут (нет сообщений)
│   └── KafkaPartitionEof.php        # Достигнут конец партиции
│
├── Exception/                       # Исключения
│   ├── Business/                    # Бизнес-логика
│   ├── Kafka/                       # Ошибки Kafka
│   └── Logic/                       # Логические ошибки
│
├── KafkaMessage/                    # Сообщения
│   ├── AbstractMessage.php          # Базовый класс
│   ├── KafkaConsumerMessage.php     # Сообщение консьюмера
│   └── KafkaProducerMessage.php     # Сообщение продюсера
│
├── PollStrategy/                    # Стратегии опроса очереди
│   ├── PollStrategy.php             # Интерфейс стратегии
│   ├── NeverPollStrategy.php        # Не вызывать poll()
│   ├── ProbabilityPollStrategy.php  # Вызывать с вероятностью N
│   └── TimeoutPollStrategy.php      # Вызывать каждые N секунд
│
├── TopicSubscription/               # Подписки на топики
│   ├── TopicSubscription.php        # Одна подписка
│   └── TopicSubscriptionList.php    # Список подписок
│
├── KafkaConsumer.php                # Главный класс консьюмера
├── KafkaProducer.php                # Главный класс продюсера
└── KafkaMessageStream.php           # Генератор для стриминга сообщений
```

## Обработка исключений

Библиотека использует иерархию исключений:

```
Exception
├── KafkaException                    # Ошибки Kafka (наследует RdKafka\Exception)
│   ├── KafkaConnectionException      # Потеряно соединение
│   ├── KafkaConsumerException        # Ошибка консьюмера
│   ├── KafkaProducerException        # Ошибка продюсера
│   ├── KafkaUnavailableException     # Kafka недоступен дольше порога
│   └── InvalidConfigException        # Невалидная конфигурация
├── LogicException                    # Логические ошибки
│   ├── ClientClosedException         # Операция после close() клиента
│   ├── NotSubscribedException        # Не подписан на топики
│   └── InvalidMessageException       # Сообщение без offset и т.п.
└── BusinessException                 # Бизнес-логика
    ├── EmptySubscriptionsException   # Пустой список подписок
    ├── InvalidSubscriptionException  # Неверные topic/partition/offset в подписке
    └── TopicHasNoPartitionException  # Топик не имеет партиций
```

Пример обработки:

```php
try {
    $producer->produce($message);
    $producer->flush();
} catch (KafkaConnectionException $e) {
    // Потеряно соединение с Kafka
} catch (KafkaProducerException $e) {
    // Ошибка отправки сообщения
}
```

### Жизненный цикл консьюмера

`KafkaConsumer::close()` идемпотентен; операции после закрытия бросают
`ClientClosedException` (ошибка программирования, не ретраить).
Подробно, с обоснованием и примером шаблона:
[docs/lifecycle.md](docs/lifecycle.md).
