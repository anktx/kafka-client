# CLAUDE.md

Этот файл содержит рекомендации для Claude Code (claude.ai/code) при работе с кодом этого репозитория.

## Обзор проекта

Это **PHP-обёртка для Apache Kafka**, построенная на расширении `ext-rdkafka`. Библиотека предоставляет удобный интерфейс для продюсеров и консьюмеров с типобезопасным API, поддержкой сжатия сообщений и настраиваемыми стратегиями опроса.

**Технический стек:** PHP 8.4+, ext-rdkafka, PHPUnit 12, PHPStan level 8 + strict-rules, PHP-CS-Fixer, Infection

## Команды разработки

### Локальная разработка (без Docker)
```bash
# Полный цикл QA (style check + static analysis + unit tests)
composer qa

# Отдельные этапы
composer tests             # Только unit-тесты (PHPUnit testsuite "Unit")
composer tests-integration # Integration-тесты (требует запущенный Kafka)
composer analyse           # PHPStan статический анализ (level 8 + strict-rules)
composer cs-check          # Проверка стиля кода (dry-run)
composer cs-fix            # Исправление стиля кода
composer coverage          # Unit-тесты с покрытием
composer infection         # Мутационное тестирование
```

### Docker-среда (через Makefile)
```bash
# Запуск всех тестов
make test-all            # Unit + integration тесты
make test                # Только unit тесты
make test-integration    # Только integration тесты (требует запущенный Kafka)

# Запуск конкретного теста
make test-file FILE=tests/KafkaClasses/KafkaProducerTest.php

# Мутационное тестирование
make infection           # Запуск Infection (MSI: 70%, Covered MSI: 80%)
make infection-show      # Детальный отчёт по мутациям
make infection-relaxed   # Сниженный порог (60% MSI)

# Code quality
make cs-dry              # Проверка стиля кода
make cs-fix              # Исправление стиля кода
make analyse             # PHPStan анализ

# Полные CI-пайплайны
make qa                  # cs-dry + analyse + test
make ci                  # Полный CI pipeline (включая mutation testing)
make clean               # Очистка сгенерированных файлов
```

## Архитектура

### Основные компоненты

1. **Конфигурация** (`src/Config/`)
   - `ConsumerConfig`, `ProducerConfig` - immutable readonly-объекты значений,
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
   - `NeverPollStrategy` - не вызывать poll() (по умолчанию)
   - `TimeoutPollStrategy` - опрос с фиксированным интервалом
   - `ProbabilityPollStrategy` - опрос с заданной вероятностью (инъекция `\Random\Randomizer`)

5. **Исключения** (`src/Exception/`)
   - Иерархия исключений для разных сценариев ошибок
   - Статические factory методы (например, `KafkaException::fromKafkaException()`)
   - Чёткое разделение на Business / Kafka / Logic exceptions

6. **ConsumeResult** (`src/ConsumeResult/`)
   - Union типы для разных результатов потребления
   - Типобезопасная обработка результатов

### Ключевые паттерны проектирования

- **Immutable Value Objects**: Конфигурационные классы — readonly, без сеттеров
- **Strategy Pattern**: Полиморфные стратегии опроса (PollStrategy)
- **Dependency Injection**: Внедрение зависимостей через конструкторы (логгер, Randomizer)
- **Type Safety**: Строгая типизация, enum'ы, union types
- **PSR-3 Logging**: Структурированное логирование с контекстом
- **Final classes/methods**: Запрет наследования там, где это уместно
- **Без трейтов**: в src/ и tests/ трейты не используются

## Организация тестов

- **Unit тесты**: `tests/` (кроме `tests/Integration/`) — изолированные тесты без внешних зависимостей
- **Integration тесты**: `tests/Integration/` - требуют запущенный Kafka broker
- Сьюты `Unit` и `Integration` объявлены в `phpunit.dist.xml`; неймспейс всех тестов — `Anktx\Kafka\Client\Tests\`
- Для integration тестов необходим контейнер с Kafka/RedPanda
- Тест-двойники для RdKafka — моки PHPUnit + reflection-инъекция в readonly-свойства (`newInstanceWithoutConstructor()`)

## Стандарты кодирования

Проект использует **PER-CS2.0** стандарт с дополнительными risky правилами:

- `declare(strict_types=1)` во всех файлах
- Final классы/методы там, где наследование не предполагается
- Ordered imports и class elements
- Nullable type declarations для значений по умолчанию `null`
- Native constant invocation (`ClassName::CONSTANT` вместо `self::CONSTANT`)
- Отключён Yoda style

## Важные детали

1. **Integration тесты** требуют локальный Kafka broker, поэтому не входят в `composer tests` / `make qa` / CI.

2. **Мутационное тестирование** (Infection) имеет пороги:
   - MSI (Mutation Score Indicator): 70%
   - Covered MSI: 80%
   - Для ослабленных проверок используйте `make infection-relaxed`

3. **Static Analysis** (PHPStan level 8 + strict-rules) требует 512MB памяти.

4. **Совместимость**: Работает как с Apache Kafka, так и с RedPanda.

5. **Ленивая инициализация**: Топики создаются по требованию при отправке/потреблении сообщений.

## Конфигурация инструментов

- **PHPUnit**: `phpunit.dist.xml` - сьюты Unit/Integration, strict-режимы
- **PHP-CS-Fixer**: `.php-cs-fixer.dist.php` - PER preset с risky rules и custom настройками
- **PHPStan**: `phpstan.neon.dist` - level 8 + phpstan-strict-rules
- **Infection**: `infection.json.dist` - 70% MSI, 80% Covered MSI, только testsuite Unit
- **CI**: `.github/workflows/ci.yml` - QA + Infection на PHP 8.4 (ubuntu, ext-rdkafka из PECL)
