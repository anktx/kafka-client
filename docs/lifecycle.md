# Жизненный цикл KafkaConsumer: `close()` и guard'ы закрытого состояния

Этот документ фиксирует контракт закрытия консьюмера и точный смысл
guard-проверок: это не «защита от вызывающего, который сам не знает, что
закрыл клиента», а сохранение контракта самой обёртки.

## Контракт

1. `KafkaConsumer::close()` оборачивает нативный
   `RdKafka\KafkaConsumer::close()` и **идемпотентен**: повторные вызовы —
   no-op (debug-лог `… already closed`). Они не могут затереть исходное
   исключение, которое летело в момент teardown'а.
2. Любая операция после `close()` (`subscribe()`, `unsubscribe()`,
   `consume()`, `commit()`) бросает
   `Anktx\Kafka\Client\Exception\Logic\ClientClosedException` **до единого
   вызова RdKafka** и пишет warning с именем метода в лог.
3. Ошибки нативного `close()` оборачиваются в `KafkaConsumerException` —
   как у всех остальных методов обёртки.
4. `ClientClosedException` наследует `LogicException` — это ошибка
   программирования (баг жизненного цикла), а не проблема брокера. Её не
   нужно ретраить и не нужно ловить общим `catch` рядом с Kafka-ошибками.

## Смысл guard'а

Guard — это один `if` поверх флага `$closed`, который и так нужен для
идемпотентности `close()`. Он решает три конкретные задачи.

### 1. Сохранение типизированного контракта исключений

Каждый метод `KafkaConsumer` декларирует `@throws KafkaConsumerException`
(и специфичные подтипы) и оборачивает `RdKafka\Exception` в наши типы.
Но use-after-close в ext-rdkafka бросает **голый `\Exception`** — не
`RdKafka\Exception`! — который пролетает мимо всех наших `catch` и
утекает из обёртки в неизменном виде. Типизированный контракт нарушается
именно на этом пути. Guard замыкает дыру: нарушитель получает наш
исключение из нашей иерархии.

### 2. Честная диагностика

Сообщение нативного исключения — «`RdKafka\KafkaConsumer::__construct()`
has not been called, or `close()` was already called» — вводит в
заблуждение: разработчик, у которого оно вылетело, конструктор вызывал.
Копать он пойдёт не туда. Guard выдаёт точную причину — `Cannot call
Anktx\Kafka\Client\KafkaConsumer::commit(): the client is closed` — плюс
warning в лог с полным именем метода ещё до обращения к RdKafka.

### 3. Гонки жизненного цикла — это не «сам дурак»

Типичный сценарий отказа — не незнание, а конкурентность: signal-handler
или teardown DI-контейнера закрывает консьюмера, а worker-цикл в этот
момент дорабатывает итерацию и вызывает `commit()`. В supervisor-логах
это выглядело бы загадочным `\Exception` из недр C-расширения.
`ClientClosedException` с именем метода указывает на баг жизненного
цикла напрямую — чинить надо порядок shutdown'а, и это видно сразу.

## Нативное поведение ext-rdkafka, которое guard обходит

Воспроизводимый пример (ext-rdkafka 6.x, PHP 8.4):

```php
$conf = new RdKafka\Conf();
$conf->set('group.id', 'test');
$conf->set('metadata.broker.list', 'localhost:1');

$consumer = new RdKafka\KafkaConsumer($conf);
$consumer->subscribe(['t']);
$consumer->close();

$consumer->commit([...]);     // \Exception: __construct() has not been called,
                              //   or close() was already called
$consumer->consume(200);      // то же \Exception
$consumer->getSubscription(); // то же \Exception
$consumer->close();           // то же \Exception — нативный close НЕ идемпотентен
```

Ключевые факты:

- **утекает голый `\Exception`**, а не `RdKafka\Exception` — наши
  `catch` его не перехватывают (см. пункт 1 выше);
- **сообщение вводит в заблуждение** (см. пункт 2);
- **нативный `close()` не идемпотентен**, а API вида `isClosed()` у
  ext-rdkafka нет — собственный флаг `$closed` является единственным
  способом обеспечить идемпотентность;
- **до брокера ничего не доходит**: соединений уже нет, исключение
  генерирует локально само PHP-расширение, отличить его от ошибки брокера
  по типу или коду невозможно.

## Смещение `readonly`

Для флага `$closed` класс `KafkaConsumer` перестал быть `readonly`:
идемпотентность close() требует мутируемого состояния, а у ext-rdkafka
нет API «спросить» нативного клиента о закрытости. Это осознанный
trade-off: детерминированный жизненный цикл важнее маркера
иммутабельности класса, который и так управляет мутабельным нативным
ресурсом.

## Рекомендуемый шаблон worker-цикла

```php
$consumer->subscribe($subscriptions);

try {
    while ($running) {
        $result = $consumer->consume(1000);
        // …обработка и commit()
    }
} finally {
    $consumer->close(); // безопасно вызвать и повторно — no-op
}
```

`ClientClosedException` здесь ловить не нужно: если он вылетел, значит
порядок shutdown'а построен неправильно, и это чинят в коде, а не
обрабатывают в рантайме.
