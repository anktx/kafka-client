# Архитектура и процесс разработки

Детальное описание компонентов библиотеки, паттернов проектирования
и конфигурации инструментов. Краткие правила для работы с кодом —
в [AGENTS.md](../AGENTS.md).

## Обзор проекта

Это **PHP-обёртка для Apache Kafka**, построенная на расширении `ext-rdkafka`.
Библиотека предоставляет удобный интерфейс для продюсеров и консьюмеров
с типобезопасным API, поддержкой сжатия сообщений и настраиваемыми
стратегиями опроса. Работает как с Apache Kafka, так и с RedPanda.

**Технический стек:** PHP 8.4+, ext-rdkafka, PHPUnit 12,
PHPStan level 9 + strict-rules, PHP-CS-Fixer, Infection

## Основные компоненты

1. **Конфигурация** (`src/Config/`)
   - `ConsumerConfig`, `ProducerConfig` — immutable readonly-объекты значений,
      настраиваются именованными аргументами конструктора
    - `Brokers` — value object списка брокеров (`host[:port]` через запятую):
      валидация в собственном конструкторе (`InvalidConfigException`), тип
      гарантирует валидный список без дублирования проверок в конфигах
    - Валидация в конструкторе: пустой `groupId`, отрицательные интервалы,
      инвертированный диапазон reconnect-backoff → `InvalidConfigException`
   - Содержит enum'ы для `CompressionType` и `OffsetReset`

2. **Продюсер** (`src/KafkaProducer.php`)
   - Отправка сообщений асинхронно (через локальную очередь)
   - Поддержка сжатия (snappy, gzip, lz4, zstd)
   - Метод `produce()` для отправки сообщения в топик
   - Метод `flush()` для принудительной отправки
   - Delivery reports через `setDrMsgCb`: успешная доставка — debug-лог,
     сбой — error-лог (отчёты доезжают только при poll()/flush())

3. **Консьюмер** (`src/KafkaConsumer.php`)
   - Подписка на топики через `TopicList` (список `Topic`)
   - Чтение сообщений через `consume()` с таймаутом
   - Ручной коммит обработанных сообщений через `commit()`
   - Возврат union-типа `KafkaConsumerMessage|KafkaConsumeTimeout|KafkaBrokersDown|KafkaPartitionEof`
     (`KafkaBrokersDown` — полная потеря брокеров, различима с таймаутом
     для метрик/watchdog'а; реакция на неё в `KafkaMessageStream` —
     инжектируемый `StreamObserver`)

4. **PollStrategy** (`src/PollStrategy/`)
   - Стратегии опроса очереди для оптимизации производительности
   - Контракт по CQS: `shouldPoll()` — чистый запрос, `markPolled()` —
     команда, фиксирующая факт опроса (вызывается клиентом после дренирования)
   - `NeverPollStrategy` — не вызывать poll() (по умолчанию)
   - `TimeoutPollStrategy` — опрос с фиксированным интервалом в мс
     (инъекция PSR-20 `Psr\Clock\ClockInterface`, дефолт — `Clock\SystemClock`)
   - `ProbabilityPollStrategy` — опрос с заданной вероятностью (инъекция `\Random\Randomizer`)

5. **Исключения** (`src/Exception/`)
    - Два семейства по природе сбоя: `Kafka\` — рантайм-сбои инфраструктуры
      (база наследует `\RuntimeException`, а не `RdKafka\Exception` — исключения
      библиотеки не должны ловиться чужим `catch (RdKafka\Exception)`),
      `Logic\` — детерминированные ошибки программиста (база наследует
      `\LogicException`: невалидный конфиг, неверное использование API,
      пустые подписки)
    - Маркерный интерфейс `KafkaClientException` (`extends \Throwable`)
      реализован обеими базами — единая точка поимки всех исключений
      библиотеки одним catch
    - Статические factory-методы (например, `KafkaException::fromKafkaException()`);
      исключения операций несут контекст позиции (topic/partition/offset,
      `out_queue_len`) прямо в сообщении

6. **ConsumeResult** (`src/ConsumeResult/`)
   - Union-типы для разных результатов потребления
   - Типобезопасная обработка результатов
   - Маркерный интерфейс `ConsumeResult` — общий supertype результатов
     `consume()` для хелперов/логов/метрик (реализован и `KafkaConsumerMessage`);
     единственный механизм дискриминации — narrowing через
     `match ($result::class)`/`instanceof` по точному union в сигнатуре
     `consume()`; соответствие union ↔ реализаций интерфейса закреплено
     рефлексионным тестом (список вариантов не дублируется)

7. **Логирование** (`src/Log/`)
   - `RdKafkaCallbacks` — колбэки librdkafka (`setLogCb`/`setErrorCb`/
     `setDrMsgCb`) и единая политика их логирования в PSR-3; общая точка
     переиспользования для продюсера и консьюмера (клиенты навешивают
     колбэки на `RdKafka\Conf` в конструкторах через attach-методы)
   - `RdKafkaLogLevel` — маппинг syslog-severity librdkafka (0–7)
     в строковые уровни PSR-3

8. **StreamObserver** (`src/StreamObserver/`)
   - Реакция на результаты consume() в потоке сообщений
      (`KafkaMessageStream`): хуки `onMessage`/`onTimeout`/`onBrokersDown`/
      `onEof` вызываются по каждому результату до yield; исключение
      из хука прерывает генератор
   - `SilentStreamObserver` — null-object, поглощает всё (дефолт,
     полная BC со старым поведением стрима)
   - `BrokersDownBudgetStreamObserver` — fail-fast бюджет
     `maxBrokersDownMs` непрерывной потери всех брокеров: wall-clock
     (PSR-20 clock, сбрасывается сообщением/EOF, не таймаутом), по
     исчерпании — `KafkaBrokersDownException` (воркер падает, супервизор
     пересоздаёт процесс)

## Ключевые паттерны проектирования

- **Immutable Value Objects**: конфигурационные классы — readonly, без сеттеров;
  доменные скаляры оборачиваются в VO с валидацией в конструкторе
  (`Config\Brokers` — список брокеров, `Topic` — имя топика): инвариант
  гарантируется типом, а не конвенцией «не забыть проверить»
- **Strategy Pattern**: полиморфные стратегии опроса (PollStrategy)
- **Observer**: реакция на результаты consume() в потоке сообщений (StreamObserver)
- **Null Object**: `SilentStreamObserver` — дефолт без реакции
- **Dependency Injection**: внедрение зависимостей через конструкторы (логгер, Randomizer, PSR-20 clock)
- **Type Safety**: строгая типизация, enum'ы, union types
- **PSR-3 Logging**: структурированное логирование с контекстом
- **Final classes/methods**: запрет наследования там, где это уместно
- **Без трейтов**: в `src/` и `tests/` трейты не используются

## Организация тестов

- **Unit-тесты**: `tests/` (кроме `tests/Integration/`) — изолированные тесты
  без внешних зависимостей
- **Integration-тесты**: `tests/Integration/` — адрес брокера берётся из
  переменной окружения `KAFKA_BROKERS` (default `localhost:9092`, формат как
  в `metadata.broker.list`); без доступного брокера тесты помечаются skipped
- Сьюты `Unit` и `Integration` объявлены в `phpunit.dist.xml`;
  неймспейс всех тестов — `Anktx\Kafka\Client\Tests\`
- Тест-двойники RdKafka — моки PHPUnit + reflection-инъекция
  в readonly-свойства (`newInstanceWithoutConstructor()`)
- Общие фабрики двойников в `tests/Support/`: `KafkaConsumers::build()`
  (KafkaConsumer + инъекция mock RdKafka), `RdKafkaMessages::fromValues()`
  (двойник `RdKafka\Message`), `FakeClock`, `InMemoryLogger`,
  `SpyStreamObserver`

## Стандарты кодирования

Проект использует **PER-CS2.0** (оба пресета, включая risky) плюс явно
перечисленные расширения сверх него (см. `.php-cs-fixer.dist.php`):

- `declare(strict_types=1)` во всех файлах
- Final классы/методы там, где наследование не предполагается
  (`final_class` не форсируется правилом: `SilentStreamObserver` —
  намеренно расширяемая база Null Object)
- Ordered imports и class elements
- Nullable type declarations для значений по умолчанию `null`
- Native constant invocation (`ClassName::CONSTANT` вместо `self::CONSTANT`)
- Отключён Yoda style

## Важные детали

1. **Integration-тесты** не входят в `composer tests` / `make qa`; в CI
   гоняются отдельным job `integration` против RedPanda-сервис-контейнера
   (job падает, если брокер не поднялся, — защита от «вечно-зелёных» прогонов).

2. **Мутационное тестирование** (Infection, 10 threads) имеет пороги:
   - MSI (Mutation Score Indicator): 100%
   - Covered MSI: 100%
   - Все мутаторы профиля `@default` включены, включая `MethodCallRemoval`
     (раньше отключался глобально — удаление `producev()`/`commit()`/`attach*()`
     нигде не детектилось, что противоречило заявленным 100%)
   - Точечные исключения `global-ignoreSourceCodeByRegex` в
     `infection.json5.dist`, каждое с обоснованием в комментарии:
     тайминговые границы `KafkaProducer::flush()`, ненаблюдаемые через
     публичный API ext-rdkafka `Conf::set()` в `ConsumerConfig`
     (`auto.offset.reset`, `enable.auto.commit='true'` — совпадает с
     дефолтом librdkafka), wiring `attach*()` в конструкторах клиентов
     (`Conf` создаётся внутри, перехват `set*Cb()` требует живого брокера)
   - Для ослабленных проверок используйте `make infection-relaxed`

3. **Static Analysis** (PHPStan level 9 + strict-rules,
   `treatPhpDocTypesAsCertain: false`) требует 512MB памяти.

4. **Совместимость**: работает как с Apache Kafka, так и с RedPanda.

5. **Ленивая инициализация**: топики создаются по требованию
   при отправке/потреблении сообщений.

## Полный список команд

### Локальная разработка (без Docker)

```bash
composer qa               # Полный цикл QA (validate + style check + static analysis + unit tests)
composer tests            # Только unit-тесты (PHPUnit testsuite "Unit")
composer tests-integration # Integration-тесты (брокер из KAFKA_BROKERS, default localhost:9092)
composer analyse          # PHPStan статический анализ (level 9 + strict-rules)
composer cs-check         # Проверка стиля кода (dry-run)
composer cs-fix           # Исправление стиля кода
composer validate         # composer validate --strict
composer coverage         # Unit-тесты с покрытием
composer infection        # Мутационное тестирование
```

### Docker-среда (через Makefile)

```bash
# Запуск всех тестов
make test-all            # Unit + integration тесты
make test                # Только unit тесты
make test-integration    # Только integration тесты (KAFKA_BROKERS, default localhost:9092)

# Запуск конкретного теста
make test-file FILE=tests/KafkaClasses/KafkaProducerTest.php

# Мутационное тестирование
make infection           # Запуск Infection (MSI: 100%, Covered MSI: 100%)
make infection-relaxed   # Сниженный порог (указать MSI=..., требует make test-coverage)

# Code quality
make cs-dry              # Проверка стиля кода
make cs-fix              # Исправление стиля кода
make analyse             # PHPStan анализ
make validate            # composer validate --strict

# Полные CI-пайплайны
make qa                  # validate + cs-dry + analyse + test
make ci                  # Полный CI pipeline (включая mutation testing)
make clean               # Очистка сгенерированных файлов
```

## Конфигурация инструментов

- **PHPUnit**: `phpunit.dist.xml` — сьюты Unit/Integration, strict-режимы
- **PHP-CS-Fixer**: `.php-cs-fixer.dist.php` — PER-CS2.0 (+risky) и явно
  перечисленные расширения сверх пресета
- **PHPStan**: `phpstan.neon.dist` — level 9 + strict-rules + deprecation-rules
  + phpstan-phpunit, `treatPhpDocTypesAsCertain: false`, стабы ext-rdkafka
  (`phpstan/stubs/RdKafka/Message.stub` — shape `$headers`)
- **Infection**: `infection.json5.dist` — MSI 100% / Covered MSI 100%, 10 threads,
  только testsuite Unit, все мутаторы `@default` (точечные ignore — с
  обоснованиями в комментариях внутри конфига)
- **CI**: `.github/workflows/ci.yml` — QA + Infection + Integration (RedPanda
  через `services:`) на PHP 8.4, concurrency-группа и кэш composer
