# Changelog

Все заметные изменения этого проекта документируются в этом файле.

Формат основан на [Keep a Changelog](https://keepachangelog.com/ru/1.1.0/),
и этот проект следует [Semantic Versioning](https://semver.org/lang/ru/).

## [Unreleased]

### Changed

- **BC:** `KafkaProducer::flush()` переписан как retry-цикл с суммарным
  дедлайном `$timeoutMs`: транзитный `RD_KAFKA_RESP_ERR__TIMED_OUT` от
  отдельного вызова `RdKafka\Producer::flush()` больше не превращается сразу
  в `KafkaConnectionException` — вызов повторяется с остатком бюджета и
  бросает исключение только после исчерпания дедлайна. Ошибки, отличные от
  таймаута, по-прежнему фейлят сразу. В контексты логов добавлены
  `out_queue_len` (остаток очереди) и `attempts`.
- **BC:** стратегии `TimeoutPollStrategy`/`ProbabilityPollStrategy` валидируют
  параметры через `InvalidConfigException` (единый контракт с конфигами)
  вместо голого `\InvalidArgumentException`: сообщения
  `Config parameter "pollIntervalSec" must not be negative, …` и
  `Config parameter "probability" must be between 0 and 1, …`
  (новая фабрика `InvalidConfigException::probability()`).
- **BC:** `TopicSubscription` валидирует аргументы конструктора: пустой
  `topic`, отрицательные `partition`/`offset` и `offset` без `partition`
  отвергаются новым `InvalidSubscriptionException` (раньше мусорные значения
  молча игнорировались при подписке).

### Added

- Жизненный цикл закрытия консьюмера: повторный `KafkaConsumer::close()` —
  no-op (раньше делегировался в RdKafka повторно и падал), а
  `subscribe()`/`unsubscribe()`/`consume()`/`commit()` после закрытия
  бросают новый `ClientClosedException` до любых обращений к RdKafka —
  вместо утекающего из ext-rdkafka голого `\Exception` с вводящим в
  заблуждение сообщением. Ошибки `RdKafka\KafkaConsumer::close()`
  оборачиваются в `KafkaConsumerException` (подробнее —
  docs/lifecycle.md).
- Валидация `ConsumerConfig::$instanceId`: пустая строка отвергается
  `InvalidConfigException` (раньше молча уходила в `group.instance.id`).

## [0.8.0] - 2026-08-19

### Changed

- **BC:** PSR-3 логгер передаётся в конструкторы клиентов: вторым параметром в
  `KafkaConsumer::__construct(ConsumerConfig $config, LoggerInterface $logger = new NullLogger())`
  и третьим в `KafkaProducer` (после `PollStrategy`). Колбэки librdkafka
  (`setLogCb`, `setErrorCb`) навешиваются самими клиентами — вся политика
  логирования живёт в одном месте. Логи librdkafka продюсера теперь тоже
  содержат `facility` в контексте.
- Дублированные колбэки `onLog`/`onBrokerError` (и producer-only
  `onDeliveryReport`) вынесены из `KafkaProducer`/`KafkaConsumer` в
  `Anktx\Kafka\Client\Log\RdKafkaCallbacks`: attach-методы
  `attachLogCallback()`/`attachErrorCallback()`/`attachDeliveryReportCallback()`
  навешивают политику логирования на `RdKafka\Conf`. Публичный API клиентов
  не изменился.
- **BC:** в конструкторе `ConsumerConfig` параметр `isDebug` перенесён в конец
  сигнатуры (после `$socketKeepaliveEnable`) — позиционные аргументы после
  `$sessionTimeoutMs` «съезжают», при именованных аргументах проблем нет.

### Removed

- Параметр `logger` удалён из конструкторов `ConsumerConfig` и `ProducerConfig`:
  конфиги снова чистые value object'ы настроек, `asKafkaConfig()` — чистый
  маппинг в `Conf::set()` без навешивания колбэков.

## [0.7.2] - 2026-08-19

### Changed

- Источником истины о состоянии подписки в `KafkaConsumer::consume()` теперь
  librdkafka (`RdKafka\KafkaConsumer::getSubscription()`), а не внутренний
  флаг: без подписки librdkafka бесконечно возвращает таймауты, неотличимые
  от пустого топика. Контракт `NotSubscribedException` сохранён без изменений.
- `KafkaConsumer` объявлен как `readonly class`.

## [0.7.1] - 2026-08-19

### Removed

- Проверка доступности брокеров перед созданием консьюмера
  (`assertBrokersAreAlive()` на основе `getMetadata()`) удалена из конструктора
  `KafkaConsumer`. Конструктор больше не выполняет сетевых вызовов и не бросает
  `KafkaConnectionException`: инициализация полностью ленивая, сеть трогается
  только фоновыми потоками librdkafka после первого `subscribe()` — объект
  безопасен для ленивого резолва в DI-контейнере. Недоступность брокеров и так
  наблюдаема через `consume()` (возврат `KafkaConsumeTimeout`) и error-callback
  в логах; fail-fast health-check при старте — зона ответственности приложения.
- Параметр `int $timeoutMs` (default `5000`) удалён из конструктора
  `KafkaConsumer`: он использовался только удалённой проверкой. Сигнатура
  конструктора снова DI-дружелюбна.

## [0.7.0] - 2026-08-06

### Added

- `ConsumerConfig`: новые опциональные параметры для управления переподключением
  librdkafka:
  - `reconnectBackoffMs` (`?int`, default `null` — используется дефолт librdkafka)
  - `reconnectBackoffMaxMs` (`?int`, default `null`)
  - `socketKeepaliveEnable` (`bool`, default `true`)

### Changed

- **BC:** `KafkaConsumer::consume()` и `consumeMatch()` больше не бросают
  `KafkaUnavailableException`. Класс сохранён для обратной совместимости,
  но не инстанцируется. Код, ловивший это исключение для рестарта
  (например, в Kubernetes), больше его не получит — вместо него при полной
  потере связи возвращается `KafkaConsumeTimeout`.
- `RD_KAFKA_RESP_ERR__ALL_BROKERS_DOWN` теперь обрабатывается в `consume()`
  как `KafkaConsumeTimeout` вместо попадания в `default` arm с бросанием
  `KafkaConsumerException`.
- `socket.keepalive.enable` теперь по умолчанию `true` (раньше не выставлялся,
  librdkafka использует `false`). Это улучшает детектирование half-open
  соединений в Kubernetes.
- Error-callback в `KafkaConsumer` теперь только логирует ошибки соединения
  (warning с `error_code`/`reason`); классификация кодов (`__ALL_BROKERS_DOWN`,
  `__TRANSPORT`, `__RESOLVE`) перенесена в приватную константу
  `KafkaConsumer::CONNECTION_ERROR_CODES`.

### Removed

- **BC:** класс `BrokerHealthState` и пространство имён `Connection` удалены.
  Классификация ошибок соединения перенесена в `KafkaConsumer::CONNECTION_ERROR_CODES`.
- **BC:** `ConsumerConfig::$unavailableThresholdSec` (int, default `30`) удалён
  из конструктора. Параметр использовался только проверкой, устранённой
  в этом релизе, и не влиял на поведение librdkafka.

### Fixed

- **Критический баг: зависание консьюмера при потере связи с брокером.** Раньше
  `KafkaConsumer::consume()` проверял порог недоступности (`unavailableThresholdSec`)
  **до** вызова librdkafka `consume()`. После превышения порога метод бесконечно
  бросал `KafkaUnavailableException`, не доходя до librdkafka. Это блокировало
  rebalance-протокол (JoinGroup/SyncGroup), который прогрессирует только через
  вызовы `consume()`/`poll()`, и приводило к необратимому зависанию consumer-group
  в состоянии `Empty` с растущим lag. Теперь `consume()` всегда делегирует чтение
  librdkafka: при полной потере связи возвращается `RD_KAFKA_RESP_ERR__ALL_BROKERS_DOWN`,
  который обрабатывается как `KafkaConsumeTimeout`. Переподключение и восстановление
  происходят автоматически в фоновых потоках librdkafka — консьюмер
  самовосстанавливается без перезапуска процесса.

## [0.6.0] - 2026-07-27

### Changed

- Управление партициями полностью делегировано librdkafka: `subscribe()` не
  вызывает `assign()` даже при подписке с явными партициями в
  `TopicSubscription` — назначения делает внутренний rebalance-callback.

### Fixed

- **Критический баг: «выпадение» партиций из потребления после подписки.**
  `KafkaConsumer::subscribe()` больше не вызывает `assign()` со снимком
  закоммиченных офсетов: раньше это переключало консьюмер в manual mode
  и затирало назначения партиций, выставленные rebalance-callback'ом
  librdkafka, — часть партиций молча выпадала из потребления. Назначение
  партиций и восстановление смещений выполняет librdkafka.
- Подписка ускорена: `subscribe()` больше не делает блокирующий запрос
  `getCommittedOffsets()` (таймаут 1000 мс) и не требует живого брокера
  на этом шаге. Путь ошибки «Failed to assign offsets»
  (`KafkaConsumerException` из шага `assign()`) устранён.

## [0.5.0] - 2026-07-25

### Added

- Детекция длительной недоступности Kafka в консьюмере: если брокеры
  недоступны дольше `unavailableThresholdSec` (новый параметр конструктора
  `ConsumerConfig`, `int`, default `30`), `KafkaConsumer::consume()` и
  `consumeMatch()` бросают новое исключение `KafkaUnavailableException`
  (наследует `KafkaException`) вместо бесконечного цикла таймаутов.
  Рекомендуемый сценарий — завершить приложение и перезапуститься
  (например, оркестратором Kubernetes). Обратите внимание: параметр вставлен
  в середину сигнатуры конструктора — позиционные аргументы после
  `$sessionTimeoutMs` «съезжают», при именованных аргументах проблем нет.
- Класс `Connection\BrokerHealthState` — конечный автомат состояния
  соединения: фиксация потери/восстановления соединения из error-callback
  librdkafka (`Conf::setErrorCb()`, логирует warning «Kafka broker connection
  error»), распознавание кодов `__ALL_BROKERS_DOWN`/`__TRANSPORT`/`__RESOLVE`.
  Восстановление фиксируется только по получению сообщения или EOF партиции.

## [0.4.0] - 2026-07-02

### Changed

- Параметр `ConsumerConfig::$instanceId` стал опциональным: `?string`,
  default `null` (раньше — обязательный `string`). Статическое членство
  в группе (KIP-345, `group.instance.id`) теперь включается только при явной
  передаче: rdkafka-опция выставляется лишь когда `instanceId !== null`.
  Обратно совместимо — существующий код, передающий `instanceId`, работает
  без изменений.

[0.8.0]: https://git.anom.ru/anktx/kafka-client/compare/0.7.2...master
[0.7.2]: https://git.anom.ru/anktx/kafka-client/compare/0.7.1...0.7.2
[0.7.1]: https://git.anom.ru/anktx/kafka-client/compare/0.7.0...0.7.1
[0.7.0]: https://git.anom.ru/anktx/kafka-client/compare/0.6.0...0.7.0
[0.6.0]: https://git.anom.ru/anktx/kafka-client/compare/0.5.0...0.6.0
[0.5.0]: https://git.anom.ru/anktx/kafka-client/compare/0.4.0...0.5.0
[0.4.0]: https://git.anom.ru/anktx/kafka-client/commits/0.4.0
