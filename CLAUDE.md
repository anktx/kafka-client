# CLAUDE.md

Этот файл содержит рекомендации для Claude Code (claude.ai/code) при работе с кодом этого репозитория.

## Обзор проекта

Это **PHP-обёртка для Apache Kafka**, построенная на расширении `ext-rdkafka`. Библиотека предоставляет удобный интерфейс для продюсеров и консьюмеров с типобезопасным API, поддержкой сжатия сообщений и настраиваемыми стратегиями опроса.

**Технический стек:** PHP 8.4+, ext-rdkafka, PHPUnit 12, PHPStan level 8, PHP-CS-Fixer, Infection

## Команды разработки

### Локальная разработка (без Docker)
```bash
# Полный цикл QA (style check + static analysis + tests)
composer qa

# Отдельные этапы
composer tests           # Запуск тестов PHPUnit
composer analyse         # PHPStan статический анализ (level 8)
composer cs-check        # Проверка стиля кода (dry-run)
composer cs-fix          # Исправление стиля кода
composer coverage        # Тесты с покрытием
composer infection       # Мутационное тестирование
```

### Docker-среда (через Makefile)
```bash
# Запуск всех тестов
make test-all            # Unit + integration тесты
make test                # Только unit тесты
make test-integration    # Только integration тесты (требует запущенный Kafka)

# Запуск конкретного теста
make test-file FILE=tests/Unit/KafkaProducerTest.php

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
   - `ConsumerConfig`, `ProducerConfig` - builder-паттерн для настройки
   - Использует fluent interface с методами-сеттерами
   - Содержит enum'ы для `CompressionType` и `OffsetReset`

2. **Продюсер** (`src/KafkaProducer.php`)
   - Отправка сообщений асинхронно (через очередь)
   - Поддержка сжатия (snappy, gzip, lz4, zstd)
   - Метод `send()` для отправки сообщения в топик
   - Метод `flush()` для принудительной отправки

3. **Консьюмер** (`src/KafkaConsumer.php`)
   - Подписка на топики через `TopicSubscription`
   - Чтение сообщений через `consume()` с таймаутом
   - Ручной коммит (commitSync/commitAsync)
   - Возврат `ConsumeResult` с информацией о сообщении

4. **PollStrategy** (`src/PollStrategy/`)
   - Стратегии опроса очереди для оптимизации производительности
   - `TimeoutPollStrategy` - опрос с таймаутом
   - Используется для управления обработкой ошибок и повторных попыток

5. **Исключения** (`src/Exception/`)
   - Иерархия исключений для разных сценариев ошибок
   - Статические factory методы (например, `KafkaException::fromRdKafka()`)
   - Чёткое разделение на business-exceptions и kafka-exceptions

6. **ConsumeResult** (`src/ConsumeResult/`)
   - Union типы для разных результатов потребления
   - Типобезопасная обработка результатов

### Ключевые паттерны проектирования

- **Builder Pattern**: Конфигурационные классы с fluent interface
- **Strategy Pattern**: Полиморфные стратегии опроса (PollStrategy)
- **Dependency Injection**: Внедрение зависимостей через конструкторы
- **Type Safety**: Строгая типизация, enum'ы, union types
- **PSR-3 Logging**: Структурированное логирование с контекстом
- **Final classes/methods**: Запрет наследования там, где это уместно

## Организация тестов

- **Unit тесты**: `tests/Unit/` - изолированные тесты без внешних зависимостей
- **Integration тесты**: `tests/Integration/` - требуют запущенный Kafka broker
- Структура тестов зеркалирует структуру исходного кода
- PHPUnit конфигурация в `phpunit.dist.xml`
- Для integration тестов необходим контейнер с Kafka/RedPanda

## Стандарты кодирования

Проект использует **PER-CS2.0** стандарт с дополнительными risky правилами:

- `declare(strict_types=1)` во всех файлах
- Final классы/методы там, где наследование не предполагается
- Ordered imports и class elements
- Nullable type declarations для значений по умолчанию `null`
- Native constant invocation (`ClassName::CONSTANT` вместо `self::CONSTANT`)
- Отключён Yoda style

## Важные детали

1. **Integration тесты** требуют локальный Kafka broker. Они не запускаются по умолчанию в GitHub Actions из-за отсутствия контейнера.

2. **Мутационное тестирование** (Infection) имеет пороги:
   - MSI (Mutation Score Indicator): 70%
   - Covered MSI: 80%
   - Для ослабленных проверок используйте `make infection-relaxed`

3. **Static Analysis** (PHPStan level 8) требует 512MB памяти для больших кодовых баз.

4. **Совместимость**: Работает как с Apache Kafka, так и с RedPanda.

5. **Ленивая инициализация**: Топики создаются по требованию при отправке/потреблении сообщений.

## Конфигурация инструментов

- **PHPUnit**: `phpunit.dist.xml` - деактивирована кодировка, включены backups globals
- **PHP-CS-Fixer**: `.php-cs-fixer.dist.php` - PER preset с risky rules и custom настройками
- **PHPStan**: `phpstan.neon.dist` - level 8, проверка параметров, неявные cast'ы
- **Infection**: `infection.json.dist` - 70% MSI, 80% Covered MSI, текстовый формат
