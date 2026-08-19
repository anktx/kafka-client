# AGENTS.md

Инструкции для AI-агентов при работе с кодом этого репозитория.

## Проект

PHP-обёртка над Apache Kafka / RedPanda на расширении `ext-rdkafka`:
продюсер, консьюмер, типобезопасный API, сжатие сообщений, стратегии опроса.

Стек: PHP 8.4+, ext-rdkafka, PHPUnit 12, PHPStan level 8 + strict-rules,
PHP-CS-Fixer (PER-CS2.0), Infection.

## Команды

```bash
composer qa               # cs-check + analyse + tests — запускать перед завершением задачи
composer tests            # unit-тесты (suite "Unit")
composer tests-integration # integration-тесты (требует запущенный Kafka)
composer analyse          # PHPStan (нужен memory_limit 512M)
composer cs-check         # проверка стиля (dry-run)
composer cs-fix           # исправление стиля
composer infection        # мутационное тестирование
```

Docker-аналоги — в `Makefile`: `make qa`, `make test`, `make test-integration`,
`make infection`, `make cs-dry`, `make cs-fix`, `make analyse`.
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
- Integration: `tests/Integration/` — требуют запущенный Kafka broker,
  не входят в `composer tests` / `make qa` / CI
- Тест-двойники RdKafka — моки PHPUnit + reflection-инъекция
  в readonly-свойства (`newInstanceWithoutConstructor()`)
- Пороги Infection: MSI 70%, Covered MSI 80% (ослабленный режим: `make infection-relaxed`)

## Подробнее

Архитектура компонентов, паттерны проектирования и конфигурация
инструментов: [docs/architecture.md](docs/architecture.md)
