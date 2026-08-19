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
PHPStan level 8 + strict-rules, PHP-CS-Fixer, Infection

## Основные компоненты

1. **Конфигурация** (`src/Config/`)
   - `ConsumerConfig`, `ProducerConfig` — immutable readonly-объекты значений,
     настраиваются именованными аргументами конструктора
   - Валидация в конструкторе: пустые `brokers`/`groupId`, отрицательные
     интервалы, инвертированный диапазон reconnect-backoff → `InvalidConfigException`
   - Содержит enum'ы для `CompressionType` и `OffsetReset`

2. **Продюсер** (`src/KafkaProducer.php`)
   - Отправка сообщений асинхронно (через локальную очередь)
   - Поддержка сжатия (snappy, gzip, lz4, zstd)
   - Метод `produce()` для отправки сообщения в топик
   - Метод `flush()` для принудительной отправки
   - Delivery reports через `setDrMsgCb`: успешная доставка — debug-лог,
     сбой — error-лог (отчёты доезжают только при poll()/flush())

3. **Консьюмер** (`src/KafkaConsumer.php`)
   - Подписка на топики через `TopicSubscription`/`TopicSubscriptionList`
   - Чтение сообщений через `consume()` с таймаутом
   - Ручной коммит обработанных сообщений через `commit()`
   - Возврат union-типа `KafkaConsumerMessage|KafkaConsumeTimeout|KafkaPartitionEof`

4. **PollStrategy** (`src/PollStrategy/`)
   - Стратегии опроса очереди для оптимизации производительности
   - `NeverPollStrategy` — не вызывать poll() (по умолчанию)
   - `TimeoutPollStrategy` — опрос с фиксированным интервалом
   - `ProbabilityPollStrategy` — опрос с заданной вероятностью (инъекция `\Random\Randomizer`)

5. **Исключения** (`src/Exception/`)
   - Два семейства по природе сбоя: `Kafka\` — рантайм-сбои инфраструктуры
     (база наследует `RdKafka\Exception`), `Logic\` — детерминированные
     ошибки программиста (база наследует `\LogicException`: невалидный
     конфиг, неверное использование API, пустые подписки)
   - Маркерный интерфейс `KafkaClientException` (`extends \Throwable`)
     реализован обеими базами — единая точка поимки всех исключений
     библиотеки одним catch
   - Статические factory-методы (например, `KafkaException::fromKafkaException()`)

6. **ConsumeResult** (`src/ConsumeResult/`)
   - Union-типы для разных результатов потребления
   - Типобезопасная обработка результатов

## Ключевые паттерны проектирования

- **Immutable Value Objects**: конфигурационные классы — readonly, без сеттеров
- **Strategy Pattern**: полиморфные стратегии опроса (PollStrategy)
- **Dependency Injection**: внедрение зависимостей через конструкторы (логгер, Randomizer)
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

## Стандарты кодирования

Проект использует **PER-CS2.0** стандарт с дополнительными risky правилами:

- `declare(strict_types=1)` во всех файлах
- Final классы/методы там, где наследование не предполагается
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
   - Граничные тайминговые мутанты `KafkaProducer::flush()` (точность
     пересчёта наносекунд в миллисекунды, границы бюджета retry-цикла)
     игнорируются через `global-ignoreSourceCodeByRegex` в `infection.json.dist`
   - Для ослабленных проверок используйте `make infection-relaxed`

3. **Static Analysis** (PHPStan level 8 + strict-rules) требует 512MB памяти.

4. **Совместимость**: работает как с Apache Kafka, так и с RedPanda.

5. **Ленивая инициализация**: топики создаются по требованию
   при отправке/потреблении сообщений.

## Полный список команд

### Локальная разработка (без Docker)

```bash
composer qa               # Полный цикл QA (validate + style check + static analysis + unit tests)
composer tests            # Только unit-тесты (PHPUnit testsuite "Unit")
composer tests-integration # Integration-тесты (брокер из KAFKA_BROKERS, default localhost:9092)
composer analyse          # PHPStan статический анализ (level 8 + strict-rules)
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
- **PHP-CS-Fixer**: `.php-cs-fixer.dist.php` — PER preset с risky rules и custom настройками
- **PHPStan**: `phpstan.neon.dist` — level 8 + strict-rules + deprecation-rules + phpstan-phpunit
- **Infection**: `infection.json.dist` — MSI 100% / Covered MSI 100%, 10 threads,
  только testsuite Unit, тайминговые мутанты `flush()` в ignore
- **CI**: `.github/workflows/ci.yml` — QA + Infection + Integration (RedPanda
  через `services:`) на PHP 8.4, concurrency-группа и кэш composer
