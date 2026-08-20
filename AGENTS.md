# AGENTS.md

Инструкции для AI-агентов при работе с кодом этого репозитория.

## Проект

PHP-обёртка над Apache Kafka / RedPanda на расширении `ext-rdkafka`:
продюсер, консьюмер, типобезопасный API, сжатие сообщений, стратегии опроса.

Стек: PHP 8.4+, ext-rdkafka ^6.0, PHPUnit 12, PHPStan level 9 + strict-rules
+ deprecation-rules + phpstan-phpunit (treatPhpDocTypesAsCertain: false,
стабы ext-rdkafka в phpstan/stubs/), PHP-CS-Fixer (PER-CS2.0), Infection.

## Команды

```bash
composer qa               # validate + cs-check + analyse + coverage (unit + гейт line coverage) — запускать перед завершением задачи
composer tests            # unit-тесты (suite "Unit"), без coverage-драйвера
composer tests-integration # integration-тесты (KAFKA_BROKERS, default localhost:9092;
                            # без брокера тесты помечаются skipped)
composer coverage         # unit-тесты с coverage-отчётом + гейт 100% line coverage
                           # (PHPUnit не умеет пороги, гейт — bin/coverage-check.php)
composer analyse          # PHPStan (нужен memory_limit 512M)
composer cs-check         # проверка стиля (dry-run)
composer cs-fix           # исправление стиля
composer infection        # мутационное тестирование (threads + пороги в infection.json5.dist)
```

Docker-аналоги — в `Makefile`: `make qa`, `make test`, `make test-integration`,
`make coverage`, `make infection`, `make cs-dry`, `make cs-fix`, `make analyse`.
Один файл: `make test-file FILE=tests/...`.

## Обязательные правила кода

- `declare(strict_types=1)` во всех PHP-файлах
- Final классы/методы, если наследование не предусмотрено
- Без трейтов в `src/` и `tests/`
- Конфиги (`src/Config/`) — immutable readonly Value Objects,
  валидация в конструкторе → `InvalidConfigException`
- Ordered imports и class elements
- Nullable-типы для значений по умолчанию `null`
- `ClassName::CONSTANT` вместо `self::CONSTANT`, без Yoda style
- Зависимости (PSR-3 логгер, `Randomizer`) — только через конструктор

## Тесты

- Unit: `tests/` кроме `tests/Integration/`; неймспейс `Anktx\Kafka\Client\Tests\`
- Integration: `tests/Integration/` — адрес брокера из `KAFKA_BROKERS`
  (default `localhost:9092`), без брокера — skipped; в CI гоняются против
  RedPanda-сервис-контейнера (job `integration`), в `composer tests`/`make qa` не входят
- Тест-двойники RdKafka — моки PHPUnit + reflection-инъекция
  в readonly-свойства (`newInstanceWithoutConstructor()`)
- Line coverage 100% по всем executable-строкам `src/` — гейт
  в `composer qa`/`make coverage` (`bin/coverage-check.php`): непокрытый
  код генерирует 0 мутантов, поэтому без порога minMsi: 100 в Infection
  проходит впустую; PHPUnit 12 порогов coverage не поддерживает.
  Единственные исключения — `@codeCoverageIgnore` с обоснованием в
  комментарии: catch-блоки создания RdKafka-клиентов (недостижимы через
  публичный API при валидном конфиге) и пустой приватный конструктор
  `RdKafkaLogLevel`
- Пороги Infection: MSI 100%, Covered MSI 100% (10 threads); все мутаторы
  профиля `@default` включены (включая `MethodCallRemoval`). Точечные
  `global-ignoreSourceCodeByRegex` в `infection.json5.dist` (каждый с
  обоснованием в комментарии) — только для мутантов, принципиально не
  убиваемых юнит-тестами: тайминговые границы `flush()`, два
  ненаблюдаемых `Conf::set()` в `ConsumerConfig`, wiring `attach*()`
  в конструкторах клиентов. Ослабленный режим: `make infection-relaxed`

## Подробнее

Архитектура компонентов, паттерны проектирования и конфигурация
инструментов: [docs/architecture.md](docs/architecture.md)
