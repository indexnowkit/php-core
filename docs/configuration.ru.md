# Конфигурация

[English version](configuration.md) — там же генерируемая таблица «One concept, three keys» (ключи трёх адаптеров).

`IndexNowKit\Config` — неизменяемый value object, общий для всех адаптеров. Строится одним из трёх способов и
валидируется в конструкторе, поэтому сломанная настройка падает при загрузке, а не при первой отправке.

```php
use IndexNowKit\Config;

$config = Config::fromArray([...]);                 // конфиг-файлы фреймворка
$config = Config::fromEnv();                        // переменные окружения INDEXNOW_*
$config = new Config(key: '...', baseUrl: '...');   // именованные аргументы
$config = $config->with(dryRun: true);              // неизменяемая копия
```

## Опции

`fromArray()` принимает вложенную форму ниже; это каноническая схема, которую повторяет каждый языковой адаптер.

```php
Config::fromArray([
    'enabled' => true,
    'key' => $_ENV['INDEXNOW_KEY'],
    'hosts' => [
        'www.example.com' => 'KEY-FOR-EXAMPLE',
        'shop.example.com' => [
            'key' => 'KEY-FOR-SHOP',
            'key_location' => 'https://shop.example.com/keys/indexnow.txt',
            'base_url' => 'https://shop.example.com',
        ],
    ],
    'strict_hosts' => true,
    'key_location' => null,
    'base_url' => 'https://www.example.com',
    'engines' => ['api'],
    'dispatch' => 'sync',
    'batch' => ['max_urls' => 10000],
    'debounce' => ['per_url' => 600],
    'throttle' => ['max_requests_per_minute' => 60],
    'http' => ['timeout' => 10.0, 'user_agent' => null],
    'key_file' => ['enabled' => true],
    'dry_run' => false,
    'environment' => $_ENV['APP_ENV'] ?? null,
]);
```

| Опция | Аргумент конструктора | Дефолт | Смысл |
|---|---|---|---|
| `enabled` | `enabled` | `true` | `false` отбрасывает каждую отправку; URL возвращаются как `skipped` с причиной `disabled`, лог на `info` |
| `key` | `key` | `null` | ключ по умолчанию, 8–128 символов `[A-Za-z0-9-]`, для каждого хоста, не перечисленного в `hosts` |
| `hosts` | `hosts` | `[]` | `host => key` или `host => {key, key_location?, base_url?, engines?, previous_key?}` |
| `strict_hosts` | `strictHosts` | `false` | применять ключ по умолчанию **только** к хосту `base_url`; любому другому хосту нужна запись в `hosts`, иначе его URL пропускаются |
| `key_location` | `keyLocation` | `null` | абсолютный URL файла ключа, если он не `https://{host}/{key}.txt` |
| `base_url` | `baseUrl` | `null` | абсолютный URL сайта; разрешает относительные URL, обязателен вне HTTP-запроса |
| `engines` | `engines` | `['api']` | имена движков (`api`, `yandex`, `bing`, `naver`, `seznam`, `yep`, `internetarchive`, `amazon`) или полные URL endpoint'ов |
| `dispatch` | `dispatch` | `'sync'` | режим доставки, определяемый адаптером; ядро проверяет идентификатор и печатает его |
| `batch.max_urls` | `batchMaxUrls` | `10000` | URL на запрос; `Config::MAX_BATCH_URLS` — максимум протокола, потолок, а не цель: меньшие батчи принимаются так же |
| `debounce.per_url` | `debouncePerUrl` | `600` | секунды, в течение которых тот же URL не отправляется повторно; `0` выключает дебаунс |
| `throttle.max_requests_per_minute` | `throttleMaxRequestsPerMinute` | `60` | исходящих запросов в минуту на процесс; `0` = без лимита |
| `http.timeout` | `httpTimeout` | `10.0` | секунды, только для клиентов, которые библиотека создаёт сама |
| `http.user_agent` | `userAgent` | `null` | заменяет `indexnowkit-php/<version> (+https://github.com/indexnowkit/php)` |
| `key_file.enabled` | `serveKeyFile` | `true` | должен ли адаптер отвечать на `GET /{key}.txt`; `serve_key_file` — устаревшее имя, побеждает, если заданы оба |
| `key_file.cache_max_age` | `keyFileMaxAge` / `keyFileHeaders()` | `300` | `Cache-Control: max-age` ответа с файлом ключа; короткий нарочно — закэшированный старый файл после ротации превращает каждую отправку в 403 |
| `debounce.store` | `debounceStore` | `null` | `memory` (на процесс), `none` или id, который адаптер разрешает в свой общий кэш; `null` = дефолт адаптера (Laravel `cache`, бандл `cache.app`, Yii2 `cache`, чистый PHP `memory`) |
| `http.client` | `httpClient` | `null` | id или класс PSR-18 клиента, который разрешает адаптер; `null` = discovery |
| `dry_run` | `dryRun` | `false` | логировать запрос вместо отправки; вне production незаданный `dry_run` при настроенном ключе делает `check` красным |
| `environment` | `environment` | `null` | окружение приложения; управляет страховкой ниже |
| `production_environments` | `productionEnvironments` | `['prod', 'production']` | имена окружений (без учёта регистра), считающихся production; заменяет список по умолчанию |
| `previous_key` | `previousKey` | `null` | ключ до ротации: файл ключа его ещё принимает, отправки под ним не идут; также `hosts.<host>.previous_key` |
| `hosts.<host>.engines` | `hostEngines` / `endpointsFor()` | наследует `engines` | движки только для одного хоста |
| `engine_aliases` | `engineAliases` / `resolveEngine()` | `{}` | короткие имена для своих endpoint'ов, применимы везде, где называется движок |
| `locale_hosts` | `localeHosts` / `hostForLocale()` | `{}` | локаль => хост; правила с `locales` и без `host` генерируют каждую локаль на её хосте |
| `logging.max_body` | `logBody` | `300` | байт тела ответа движка, сохраняемых в строке лога об ошибке |
| `max_url_length` | `maxUrlLength` | `2048` | URL длиннее пропускаются как `invalid_url` |
| `debounce.key_prefix` | `debounceKeyPrefix` | `'indexnowkit_'` | префикс ключей общего debounce store |
| `logging.max_urls` | `logUrls` / `logSample()` | `20` | URL, перечисляемых в одной строке лога; `0` = только счётчики |
| `logging.forbidden_escalation` | `forbiddenEscalation` | `5` | подряд идущих 403 на хост до эскалации лога в `critical` |
| `logging.levels` | `logLevels` / `logLevel()` | `{}` | переопределение PSR-3 уровня по исходу; события и дефолты в `Config::LOG_EVENTS` |
| `retry.max_attempts`, `retry.base_delay`, `retry.multiplier`, `retry.max_delay`, `retry.server_error_delay` | `retryPolicy()` | `3`, `60`, `2.0`, `3600`, `5` | `RetryPolicy` для обработчиков очередей и `RetryingSubmitter` |
| `resolver.max_via_depth`, `resolver.max_via_fanout` | `resolverMaxViaDepth`, `resolverMaxViaFanout` | `3`, `100` | лимиты обхода `via:` в `AttributeUrlResolver`; `IndexNowKit::create()` этот резолвер не строит — адаптер, который строит, передаёт их вместе с `localeHosts` |
| `collector.max_urls` | `collectorMaxUrls` | `0` | `IndexNowKit::collect()` сбрасывает буфер досрочно при этом размере; `0` = только на `flush()` |
| `collector.detect_leaks` | `collectorDetectLeaks` | `true` | warning при завершении о собранных, но не сброшенных URL |

Константы, на которые стоит ссылаться вместо чисел: `Config::MAX_BATCH_URLS` (10000), `Config::DEFAULT_BATCH_MAX_URLS`,
`Config::DEFAULT_DEBOUNCE_PER_URL` (600), `Config::DEFAULT_THROTTLE_PER_MINUTE` (60), `Config::DEFAULT_HTTP_TIMEOUT` (10.0),
`Config::PRODUCTION_ENVIRONMENTS` (`['prod', 'production']`), `Config::DEFAULT_MAX_URL_LENGTH`, `Config::DEFAULT_LOG_URLS`,
`Config::DEFAULT_FORBIDDEN_ESCALATION`, `Config::DEFAULT_RETRY_*`, `Config::DEFAULT_RESOLVER_MAX_VIA_*`, `Config::LOG_EVENTS`.

## Одно понятие — три ключа

Адаптеры разделяют ключи ядра под теми же именами и добавляют несколько своих; у некоторых понятий ключ (или набор
значений) свой в каждом фреймворке. Таблицы генерируются из кода (`bin/config-table`) и проверяются в CI — см.
раздел «One concept, three keys» в [английской версии](configuration.md#one-concept-three-keys). Коротко:

| Понятие | Symfony | Laravel | Yii2 |
|---|---|---|---|
| Режим доставки `dispatch` | `auto` \| `messenger` \| `sync` \| `none` | `queue` \| `sync` \| `none` (без `auto`) | `auto` \| `queue` \| `sync` \| `none` |
| Очередь / транспорт | `messenger.transport` | `queue.connection` | `queue.component` |
| Локали для `locales: all` | `framework.enabled_locales` | `router.locales` | `router.languages` |
| Переключатель ORM-хуков | `doctrine.enabled` | `eloquent.enabled` | `active_record.enabled` |
| Маршрут файла ключа | `key_file.path` | `key_file.path` | `key_file.pattern` |
| Куда логировать | `logging.channel` | `logging.channel` | `logging.category` |

## Переменные окружения

`Config::fromEnv()` читает `getenv()`, слитый с `$_SERVER` и `$_ENV`. Первый аргумент — свой массив вместо них, второй —
другой префикс вместо `INDEXNOW_`. Пустые строки считаются незаданными.

| Переменная | Опция |
|---|---|
| `INDEXNOW_ENABLED` | `enabled` (любой булев литерал, который принимает `filter_var`) |
| `INDEXNOW_KEY` | `key` |
| `INDEXNOW_PREVIOUS_KEY` | `previous_key` |
| `INDEXNOW_HOSTS` | `hosts` в виде `host=key,host2=key2`; `key_location`/`base_url` по хостам — только через `fromArray()` |
| `INDEXNOW_STRICT_HOSTS` | `strict_hosts` |
| `INDEXNOW_KEY_LOCATION` | `key_location` |
| `INDEXNOW_BASE_URL` | `base_url` |
| `INDEXNOW_ENGINES` | `engines`, через запятую (`api` или `yandex,bing`) |
| `INDEXNOW_DISPATCH` | `dispatch` |
| `INDEXNOW_BATCH_MAX_URLS` | `batch.max_urls` |
| `INDEXNOW_DEBOUNCE_PER_URL` | `debounce.per_url` |
| `INDEXNOW_THROTTLE_PER_MINUTE` | `throttle.max_requests_per_minute` |
| `INDEXNOW_HTTP_TIMEOUT` | `http.timeout` |
| `INDEXNOW_USER_AGENT` | `http.user_agent` |
| `INDEXNOW_KEY_FILE_ENABLED` (`INDEXNOW_SERVE_KEY_FILE` всё ещё побеждает) | `key_file.enabled` |
| `INDEXNOW_KEY_FILE_CACHE_MAX_AGE` | `key_file.cache_max_age` |
| `INDEXNOW_DEBOUNCE_STORE` | `debounce.store` |
| `INDEXNOW_HTTP_CLIENT` | `http.client` |
| `INDEXNOW_DRY_RUN` | `dry_run` |
| `INDEXNOW_ENV`, иначе `APP_ENV` | `environment` |
| `INDEXNOW_PRODUCTION_ENVIRONMENTS` | `production_environments`, через запятую |
| `INDEXNOW_MAX_URL_LENGTH` | `max_url_length` |
| `INDEXNOW_LOG_URLS`, `INDEXNOW_FORBIDDEN_ESCALATION` | `logging.max_urls`, `logging.forbidden_escalation` |
| `INDEXNOW_RETRY_MAX_ATTEMPTS`, `INDEXNOW_RETRY_BASE_DELAY`, `INDEXNOW_RETRY_MULTIPLIER`, `INDEXNOW_RETRY_MAX_DELAY`, `INDEXNOW_RETRY_SERVER_ERROR_DELAY` | `retry.*` |

## Хосты, ключи и `strict_hosts`

Поддомены для IndexNow — отдельные хосты: каждому нужен свой файл ключа. Три схемы:

- **Один сайт.** Задайте `key` и `base_url`. Каждый отправляемый хост использует этот ключ.
- **Несколько сайтов, у каждого ключ.** Заполните `hosts`. Хосты вне карты всё ещё падают на `key`.
- **Несколько сайтов и ничего лишнего.** Задайте `strict_hosts: true`. Ключ по умолчанию тогда применяется только к
  хосту `base_url`; URL любого другого неперечисленного хоста пропускаются с причиной `no_key`, а не объявляются под
  чужим ключом. Рекомендуется всегда, когда URL могут прийти из пользовательского ввода или мультитенантной базы.

`hosts.<host>.key_location` переопределяет URL файла ключа только для этого хоста и должен быть на нём.
`hosts.<host>.base_url` даёт хосту свою абсолютную базу для генерации URL вне запроса — у консольной команды или
воркера очереди нет контекста запроса, и без него все сайты генерировались бы на едином `base_url`.
`Config::baseUrlFor($host)` возвращает эту базу, падает на `base_url`, когда хост — базовый, и `null` иначе.

Ключи перечисляются через `Config::$hosts`, `Config::$keyLocations` и `Config::$hostBaseUrls` (карты по хостам в нижнем
регистре). Чтобы брать ключи из базы или реестра тенантов, реализуйте `Key\KeyProviderInterface`.

## Страховка dry-run

`Config::fromArray()` сам включает `dry_run`, когда выполняется **всё** из: нет `key`, нет `hosts`, задан
`environment`, и он не в `production_environments` (по умолчанию `Config::PRODUCTION_ENVIRONMENTS`). Разработчик, не
задавший `INDEXNOW_KEY` локально, получает логирование вместо падения при загрузке и никогда не доходит до реального API.

Обратный случай стоит алерта: `dry_run` включён при `environment` production — не отправляется ничего.
`Config::isProduction()` сообщает об этом, а `Check\Checker` поднимает это как **ошибку**, а не warning. И зеркально:
вне production настроенный ключ и незаданный `dry_run` — ошибка `check` (стейджинг отправлял бы боевые URL); явный
`dry_run: false` говорит, что окружение отправляет нарочно, и даёт warning.

## Валидация

Конструктор бросает `Exception\ConfigurationException` при:

- `enabled` без `key`, `hosts` или `dry_run`;
- `key` (или ключе хоста) вне `[A-Za-z0-9-]{8,128}`;
- ключе `hosts`, который не голое имя хоста (есть схема, порт или путь);
- `base_url`, который не абсолютный `http(s)` URL или несёт credentials;
- `key_location`, который не абсолютный `http(s)` URL с путём или не на хосте `base_url` — движки принимают файл
  ключа только с отправляемого хоста;
- `hosts.<host>.key_location` или `hosts.<host>.base_url`, указывающих на другой хост;
- `batch.max_urls` вне `1..10000`, отрицательных `debounce.per_url` или `throttle.max_requests_per_minute`,
  `http.timeout` не больше нуля, пустом списке `engines`;
- `dispatch`, который не короткий идентификатор, `http.user_agent` с переводами строк;
- `strict_hosts` без единого известного хоста;
- имени движка, которое не известный движок, не алиас и не `https` endpoint (простой `http` разрешён только на
  loopback-хостах, для mock-серверов).

Каждое сообщение называет факт, допустимое и как исправить. `Config::fromArray()` дополнительно отклоняет нечисловые
значения числовых опций вместо тихого дефолта.

## Производные конфигурации

`with()` принимает имена аргументов конструктора и возвращает проверенную копию; неизвестное имя бросает исключение.
Смена `dryRun` делает копию «явной» (`$dryRunExplicit`), прочие изменения флаг сохраняют.

```php
$probe = $config->with(dryRun: false, engines: ['yandex']);
$config->withDryRun(true);                 // сокращение
$config->userAgent();                      // действующая строка User-Agent
$config->baseHost();                       // хост base_url в нижнем регистре или null
```

## Опечатки в конфигурации адаптера

`Config::OPTIONS` перечисляет каждый ключ, который понимает `fromArray()`, в форме через точку.
`Config::unknownOptions($data, $allowed)` возвращает ключи массива, которые ни опции ядра, ни в `$allowed`, так что
адаптер предупреждает о `debounce.per_urls`, а не молча игнорирует. Вложенные ключи перечисляйте как `block.key`,
никогда как голый `block`: голое имя останавливает проверку внутри блока. Адаптеры получают это через
`Adapter\ConfigFactory::load()` (`ownedOptions:`), который также сливает дефолты адаптера, разрешает `dispatch: auto`
и превращает невалидное значение в `critical`-строку лога и выключенный `Config` вместо исключения.

```php
$unknown = Config::unknownOptions($userConfig, ['messenger', 'messenger.bus', 'doctrine.enabled']);
if ($unknown !== []) {
    $logger->warning('indexnow: unknown option(s): {options}', ['options' => implode(', ', $unknown)]);
}
```

Вложенные массивы проверяются на один уровень по пути через точку; `hosts` принимается всегда, потому что его ключи —
имена хостов. Имя блока в `$allowed` (например `messenger`) разрешает весь блок, так что адаптер перечисляет либо имя
блока, либо отдельные пути, которыми владеет.
