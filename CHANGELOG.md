# Changelog

Все заметные изменения этого проекта документируются в этом файле.

Формат основан на [Keep a Changelog](https://keepachangelog.com/ru/1.1.0/),
и этот проект следует [Semantic Versioning](https://semver.org/lang/ru/).

## [Unreleased]

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

### Removed

- Проверка доступности брокеров перед подпиской (`assertBrokersAreAlive()` на
  основе `getMetadata()`) удалена из `KafkaConsumer::subscribe()`. Подписка —
  локальная операция: подключение и запрос метаданных librdkafka выполняет
  асинхронно в фоновых потоках, а гарантий проверка не давала (брокеры могли
  упасть сразу после неё). Недоступность брокеров и так наблюдаема через
  `consume()` (возврат `KafkaConsumeTimeout`) и error-callback в логах;
  fail-fast health-check при старте — зона ответственности приложения.
- Параметр `int $timeoutMs` (default `5000`) удалён из конструктора
  `KafkaConsumer` вместе с приватным свойством `$connectTimeoutMs`:
  использовался только удалённой проверкой. Сигнатура конструктора снова
  DI-дружелюбна: `__construct(ConsumerConfig $config)`.
- Класс `BrokerHealthState` и пространство имён `Connection` удалены. После
  устранения `assertKafkaAvailable()` класс стал write-only: состояние записывалось
  через error callback и `consume()`, но не читалось ни одним production-кодом.
  Классификация ошибок соединения (`__ALL_BROKERS_DOWN`, `__TRANSPORT`, `__RESOLVE`)
  перенесена в приватную константу `KafkaConsumer::CONNECTION_ERROR_CODES`.
- `ConsumerConfig::$unavailableThresholdSec` удалён из конструктора. Параметр
  использовался только удалённым `assertKafkaAvailable()` и не влиял на поведение
  librdkafka.

### Changed

- `KafkaConsumer::consume()` больше не бросает `KafkaUnavailableException`.
  Класс сохранён для обратной совместимости, но не инстанцируется.
- `RD_KAFKA_RESP_ERR__ALL_BROKERS_DOWN` теперь обрабатывается в `consume()`
  как `KafkaConsumeTimeout` вместо попадания в `default` arm с бросанием
  `KafkaConsumerException`.
- `socket.keepalive.enable` теперь по умолчанию `true` (раньше не выставлялся,
  librdkafka использует `false`). Это улучшает детектирование half-open
  соединений в Kubernetes.

### Added

- `ConsumerConfig`: новые опциональные параметры для управления переподключением
  librdkafka:
  - `reconnectBackoffMs` (`?int`, default `null` — используется дефолт librdkafka)
  - `reconnectBackoffMaxMs` (`?int`, default `null`)
  - `socketKeepaliveEnable` (`bool`, default `true`)
