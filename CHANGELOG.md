# Changelog

Все заметные изменения этого проекта документируются в этом файле.

Формат основан на [Keep a Changelog](https://keepachangelog.com/ru/1.1.0/),
и этот проект следует [Semantic Versioning](https://semver.org/lang/ru/).

## [Unreleased]

### Added

- `CompressionType::None` (`compression.type=none`): типизированный API
  теперь позволяет отключить сжатие сообщений продюсера (до этого enum
  содержал только компрессирующие кодеки).
- Маркерный интерфейс `ConsumeResult`: три результата `consume()` получили
  общий supertype для хелперов, логгеров и метрик. Сигнатура `consume()` не
  изменилась: точный union остаётся единственным словарём вариантов —
  дискриминация через `match ($result::class)`/`instanceof` (параллельный
  enum-дискриминатор `kind()` в релиз не вошёл: вызовов в библиотеке у него
  не было, а список вариантов дублировал). Соответствие union ↔ реализации
  интерфейса фиксируется рефлексионным тестом без дублирования списка.
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

### Changed

- **BC:** контракт `PollStrategy` приведён к CQS: `shouldPoll()` стал чистым
  запросом без побочных эффектов (раньше `TimeoutPollStrategy::shouldPoll()`
  неявно обновлял отметку последнего опроса), фиксация факта опроса вынесена
  в новую команду `markPolled(): void`. `KafkaProducer::produce()` вызывает
  `markPolled()` после дренирования очереди delivery-report'ов. Внешние
  реализации `PollStrategy` обязаны добавить `markPolled()` (для
  stateless-стратегий — no-op, как в `NeverPollStrategy`/
  `ProbabilityPollStrategy`).
- **BC:** `TimeoutPollStrategy`: параметр `pollIntervalSec` переименован в
  `pollIntervalMs` и переведён в миллисекунды — единый суффикс `*Ms` с
  остальными таймингами библиотеки (`lingerMs`, `sessionTimeoutMs`, …) и
  субсекундные интервалы. Время инъектируется через PSR-20
  `Psr\Clock\ClockInterface` (новая зависимость `psr/clock`; по умолчанию —
  новая `Clock\SystemClock`), граничные случаи интервала (ровно граница/−1 мс)
  покрыты детерминированными тестами на управляемых часах вместо заглушек.

- **BC:** кейсы `CompressionType` и `OffsetReset` переименованы в
  PascalCase согласно конвенции PHP (`snappy` → `Snappy`, `lz4` → `Lz4`,
  `earliest` → `Earliest` и т.д.); бэкинг-значения — стабильные
  идентификаторы Kafka-протокола — не изменились, кроме случая
  «без сброса» у `OffsetReset` (см. Fixed). В test-комплект добавлены
  ассерты бэкинг-значений и маппинга `asKafkaConfig()` в термины librdkafka.

- **BC:** иерархия исключений переработана в два семейства + маркерный
  интерфейс `Anktx\Kafka\Client\Exception\KafkaClientException`
  (`extends \Throwable`), реализуемый обеими базами: один
  `catch (KafkaClientException)` ловит всё, что кидает библиотека.
  `InvalidConfigException` переехал из `Exception\Kafka` в `Exception\Logic`
  и наследует `\LogicException`, а не `RdKafka\Exception` (все его throw-сайты
  — валидация: опечатки в конфиге больше не ловятся как «Kafka упал»);
  обёртка сбоев `Conf::set()` сохранена через
  `InvalidConfigException::fromKafkaException()`. Ветка `Exception\Business`
  удалена: `EmptySubscriptionsException` и `InvalidSubscriptionException`
  переехали в `Exception\Logic` — та же природа ошибки, что у
  `NotSubscribedException`.
- **BC:** удалён `KafkaUnavailableException`: с версии 0.7.0 не выбрасывался
  нигде (`consume()` при `RD_KAFKA_RESP_ERR__ALL_BROKERS_DOWN` возвращает
  `KafkaConsumeTimeout`), класс удерживался только ради обратной совместимости.
- **BC:** `KafkaProducer::flush()` переписан как retry-цикл с суммарным
  дедлайном `$timeoutMs`: транзитный `RD_KAFKA_RESP_ERR__TIMED_OUT` от
  отдельного вызова `RdKafka\Producer::flush()` больше не превращается сразу
  в `KafkaFlushTimeoutException` — вызов повторяется с остатком бюджета и
  бросает исключение только после исчерпания дедлайна. Ошибки, отличные от
  таймаута, по-прежнему фейлят сразу. В контексты логов добавлены
  `out_queue_len` (остаток очереди) и `attempts`.
- **BC:** `KafkaConnectionException` переименован в `KafkaFlushTimeoutException`:
  единственный throw-сайт — исчерпание суммарного дедлайна
  `KafkaProducer::flush()`, к состоянию соединения исключение отношения
  не имеет — старое имя навязывало неверную семантику обработчикам
  (комментарий README-примера «Потеряно соединение с Kafka» исправлен на
  «часть сообщений могла остаться в локальной очереди»). Фабрики
  `KafkaFlushTimeoutException::flushTimeout()` и
  `KafkaProducerException::flushFailed()` теперь проставляют `code`
  (`RD_KAFKA_RESP_ERR__TIMED_OUT` и код ошибки librdkafka соответственно) —
  раньше код был доступен только парсингом `message`.
- **BC:** стратегии `TimeoutPollStrategy`/`ProbabilityPollStrategy` валидируют
  параметры через `InvalidConfigException` (единый контракт с конфигами)
  вместо голого `\InvalidArgumentException`: сообщения
  `Config parameter "pollIntervalSec" must not be negative, …` и
  `Config parameter "probability" must be between 0 and 1, …`
  (новая фабрика `InvalidConfigException::probability()`).
- **BC:** `TopicSubscription` сокращён до одного поля `topic`: после
  удаления ручного assign() из `subscribe()` поля `partition`/`offset`
  молча игнорировались (партиции и смещения назначает rebalance
  librdkafka), поэтому удалены вместе с мёртвыми преобразованиями
  `fromKafkaTopicPartition()`/`asKafkaTopicPartition()`/
  `asKafkaTopicPartitionArray()` и исключением
  `TopicHasNoPartitionException`; `InvalidSubscriptionException`
  остаётся для пустого `topic`.
- **BC:** иерархия сообщений переработана: базовый `AbstractMessage`
  удалён — `KafkaConsumerMessage` и `KafkaProducerMessage` стали
  самостоятельными `final readonly`-классами с валидацией в конструкторе
  (пустой `topic`, отрицательные `partition`/`offset`/`timestampMs`
  бросают `InvalidMessageException` вместо молчаливого приёма).
  `KafkaConsumerMessage` требует `topic`/`partition`/`offset` как
  обязательные параметры — прочитанное сообщение всегда знает своё
  положение; порядок параметров изменён (`partition`/`offset` идут
  после `topic`, перед `body`), `timestampMs` стал `?int`, где `null`
  означает «брокер не передал время» (ext-rdkafka не задаёт timestamp
  при null-payload, а `-1` — сентинел). Из конструктора
  `KafkaProducerMessage` параметр `offset` удалён намеренно: офсет
  назначает брокер и продюсеру не известен; `partition` (default
  `RD_KAFKA_PARTITION_UA`) и `timestampMs = 0` по-прежнему означают
  «значение выставит librdkafka». Как следствие,
  `KafkaConsumer::commit()` больше не бросает `InvalidMessageException`
  за сообщение без смещения (состояние невозможно by-construction):
  проверка и фабрика `InvalidMessageException::noOffset()` удалены,
  у исключения новые фабрики `emptyString()`/`nonNegativeInt()`/
  `partitionBelowUnassigned()`.
- **BC:** сырые `\RdKafka\Exception` больше не утекают из публичного API:
  сбои `asKafkaConfig()` оборачиваются в `InvalidConfigException`, конструкторов
  клиентов и `produce()` (включая `newTopic()`) — в
  `KafkaConsumerException`/`KafkaProducerException` с контекстом в логе.
- Инструментарий: Infection обновлён до `^0.35` (kwn/php-rdkafka-stubs
  удалён); пороги Infection подняты до MSI 100% / Covered MSI 100%
  (10 threads; граничные тайминговые мутанты `flush()` — в
  `global-ignoreSourceCodeByRegex`); добавлены `phpstan-deprecation-rules`
  и `phpstan-phpunit`; `composer validate --strict` включён в `composer qa`
  (добавлены `scripts-descriptions`); зависимость `ext-rdkafka` зафиксирована
  как `^6.0` вместо `*`; починен `.PHONY` Makefile и унифицирован запуск
  Infection (`make infection` = `composer infection`, без предварительного
  coverage-прогона); CI: concurrency-группы, кэш composer, артефакт с логом
  Infection и новый job `integration` против RedPanda-сервис-контейнера.
- Интеграционные тесты: адрес брокера читается из `KAFKA_BROKERS`
  (default `localhost:9092`), при недоступности брокера тесты помечаются
  skipped; неймспейсы приведены к PSR-4 (`Tests\Integration\…` вместо
  `Tests\Kafka`); убраны пустые catch-заглушки, маскировавшие регрессии
  («вечно-зелёные» тесты) — тесты без брокера честно skipped, с брокером —
  падают при сбоях; `group.instance.id` уникальны per-test (фикс фенсинга
  статических членов между тестами).

### Fixed

- `OffsetReset` с политикой «без сброса» передавал в librdkafka значение
  `none`, которое тот не принимает (`Invalid value "none" for configuration
  property "auto.offset.reset"`) — любой `ConsumerConfig` с этим кейсом
  бросал `InvalidConfigException` из `asKafkaConfig()`. Кейс `none`
  (бэкинг `'none'`) заменён на `Error = 'error'` — каноничное имя
  librdkafka для этой политики (в терминологии Kafka-протокола — `none`):
  при отсутствии валидного закоммиченного смещения партиция уходит в
  ошибку `RD_KAFKA_RESP_ERR__AUTO_OFFSET_RESET`, и `consume()` бросает
  `KafkaConsumerException` вместо молчаливого сброса; поведение
  `earliest`/`latest` не изменилось.
- Busy-loop в `KafkaProducer::produce()`: drain очереди delivery-report'ов
  (`while (getOutQLen() > 0) { poll(0); }`) при недоступных брокерах крутился
  на 100% CPU до `message.timeout.ms` (5 минут) — теперь бюджет
  `MAX_DRAIN_POLLS = 100` poll()-вызовов с warning'ом о недренжированном
  остатке.
- Ошибочные исключения из `RdKafka\KafkaConsumer::getSubscription()` в
  `consume()` (вызов был вне try-блока) оборачиваются в
  `KafkaConsumerException` вместо утечки сырого `RdKafka\Exception`.
- `consumeMatch()` использует константу `DEFAULT_CONSUME_TIMEOUT_MS` вместо
  дублирующего литерала `1000`.
- Error-callback librdkafka логирует все ошибки клиента (SASL/SSL/
  авторизация — warning с `error_code`/`reason`, фатальные — error): раньше
  молча глотались всё, кроме кодов соединения.

## [0.8.0] - 2026-08-19

### Changed

- **BC:** PSR-3 логгер передаётся в конструкторы клиентов: вторым параметром в
  `KafkaConsumer::__construct(ConsumerConfig $config, LoggerInterface $logger = new NullLogger())`
  и третьим в `KafkaProducer` (после `PollStrategy`). Колбэки librdkafka
  (`setLogCb`, `setErrorCb`) навешиваются самими клиентами — вся политика
  логирования живёт в одном месте. Логи librdkafka продюсера теперь тоже
  содержат `facility` в контексте.
- **BC:** `NeverPoolStrategy` переименован в `NeverPollStrategy` (фикс опечатки
  в названии стратегии).
- **BC:** `ConsumerConfig` и `ProducerConfig` валидируют параметры в
  конструкторе и отвергают некорректные значения `InvalidConfigException`
  вместо сырой ошибки librdkafka в момент `asKafkaConfig()` (или вовсе
  молчаливого приёма).
- **BC:** `KafkaConsumer::commit()` сообщения без смещения теперь бросает
  `InvalidMessageException` вместо фиктивного коммита `offset = null + 1`.
- **BC:** в конструкторе `ConsumerConfig` параметр `isDebug` перенесён в конец
  сигнатуры (после `$socketKeepaliveEnable`) — позиционные аргументы после
  `$sessionTimeoutMs` «съезжают», при именованных аргументах проблем нет.
- Дублированные колбэки `onLog`/`onBrokerError` (и producer-only
  `onDeliveryReport`) вынесены из `KafkaProducer`/`KafkaConsumer` в
  `Anktx\Kafka\Client\Log\RdKafkaCallbacks`: attach-методы
  `attachLogCallback()`/`attachErrorCallback()`/`attachDeliveryReportCallback()`
  навешивают политику логирования на `RdKafka\Conf`. Публичный API клиентов
  не изменился.
- Доменные Kafka-исключения обогащены кодами ошибок librdkafka
  и детальными сообщениями (`rd_kafka_err2str()`).
- Poll-стратегии упрочнены: отрицательный интервал `TimeoutPollStrategy`
  отвергается, `ProbabilityPollStrategy` получает `Random\Randomizer` через
  конструктор (детерминированные тесты) и сравнивает вероятность через
  `getFloat()`.

### Added

- Продюсер логирует delivery-report каждого сообщения через
  `Conf::setDrMsgCb()`: успешная доставка — debug, сбой — error
  с `topic`/`partition`/`error_code`.
- Error-callback логирует ошибки брокера warning'ом с `error_code`/`reason`,
  syslog-уровни librdkafka маппятся в PSR-3 (`Log\RdKafkaLogLevel`).
- CI-воркфлоу (php-cs-fixer + PHPStan strict-rules + unit-тесты + Infection)
  и composer-скрипты `tests`/`tests-integration` по suites.

### Fixed

- В librdkafka передаются backing values enum'ов `compression.type` и
  `auto.offset.reset` (например, `gzip`) вместо имён кейсов (`GZIP`) —
  ранее конфигурация сжатия отвергалась librdkafka.

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
