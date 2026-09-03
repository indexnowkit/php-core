# IndexNow-клиент для PHP — `indexnowkit/core`

Сообщайте Яндексу, Bing и остальным поисковикам с поддержкой [IndexNow](https://yandex.ru/support/webmaster/ru/indexing-options/index-now),
какие URL изменились, из любого PHP-приложения. Батчи, дебаунс, троттлинг, политика повторов, файл ключа и чтение
sitemap поверх PSR-18 / PSR-17 / PSR-3 / PSR-16, без привязки к фреймворку. Адаптеры ([Symfony](../symfony-bundle),
[Doctrine](../doctrine)) построены на этом пакете; напрямую он нужен для чистого PHP, плагинов CMS и своих фреймворков.

[English version](README.md)

## Кто получит уведомление

**Яндекс, Bing (и DuckDuckGo через Bing), Naver, Seznam, Yep** — все поисковики, реализующие IndexNow. Один запрос на
общий endpoint `api.indexnow.org` доходит до всех; перечислять движки явно нужно только чтобы отправить в один.

**Google: нет.** Google не поддерживает IndexNow, ping-endpoint для sitemap закрыт, а Indexing API ограничен
`JobPosting` / `BroadcastEvent`. Для Google остаётся sitemap; библиотека не будет делать вид, что это не так.

## Установка

```bash
composer require indexnowkit/core symfony/http-client nyholm/psr7   # подойдёт любой PSR-18 клиент с PSR-17 фабриками
```

Если у вас фреймворк, берите адаптер: `indexnowkit/symfony-bundle`, `indexnowkit/doctrine`. Они собирают всё описанное
ниже через контейнер и подключаются к изменениям сущностей.

## Быстрый старт

```php
use IndexNowKit\Config;
use IndexNowKit\IndexNow;

$indexNow = IndexNow::create(Config::fromEnv());   // INDEXNOW_KEY, INDEXNOW_BASE_URL, ...

foreach ($indexNow->submit(['/posts/hello', 'https://www.example.com/about']) as $result) {
    printf("%s %s %d %s\n", $result->engine, $result->status->value, $result->httpCode ?? 0, $result->error ?? '');
}
```

```dotenv
INDEXNOW_KEY=6f3c9a...          # 8–128 символов, [A-Za-z0-9-]
INDEXNOW_BASE_URL=https://www.example.com
```

`submit()` никогда не бросает исключений из-за удалённых проблем: на каждую комбинацию движок × host × батч
возвращается `Result` и пишется строка в лог, а неотправленные URL (дебаунс, `enabled: false`, dry-run, чужой host)
дают результат `skipped` с причиной.

## Файл ключа

Поисковики подтверждают владение сайтом, запрашивая `https://{host}/{key}.txt`; тело файла — ровно ключ.

```php
use IndexNowKit\Key\KeyGenerator;

$key = KeyGenerator::generate();                       // 32 hex-символа, CSPRNG
file_put_contents("public/$key.txt", $key);            // или отдавайте из роута: $indexNow->keys->isKnownKey($key)
```

Файл должен отдаваться с `200 OK`, `text/plain`, без редиректов. Если он лежит в другом месте того же host, укажите
`key_location`. Затем проверьте:

```php
use IndexNowKit\Check\Checker;

$report = (new Checker($indexNow->config, $indexNow->keys, $transport))->run(liveProbe: false);
foreach ($report->items() as ['level' => $level, 'message' => $message]) {
    echo "[$level] $message\n";
}
```

`Checker` находит ошибки конфигурации, скачивает каждый файл ключа и с `liveProbe: true` шлёт настоящий пробный запрос
в каждый движок. `403` от движка всегда означает «файл ключа недоступен или не совпадает».

## Конфигурация

`Config::fromArray()` принимает вложенную структуру ниже (ту же, что показывают адаптеры), `Config::fromEnv()` читает
переменные `INDEXNOW_*`, конструктор принимает именованные аргументы. Все значения проверяются при создании: плохая
конфигурация падает на старте, а не при первой отправке.

| Опция | Env | По умолчанию | Смысл |
|---|---|---|---|
| `enabled` | `INDEXNOW_ENABLED` | `true` | `false` отбрасывает все отправки (debug в логе) |
| `key` | `INDEXNOW_KEY` | — | ключ по умолчанию для всех host, не перечисленных в `hosts` |
| `hosts` | `INDEXNOW_HOSTS` (`a.com=KEY1,b.com=KEY2`) | `[]` | ключи по host; в форме массива `{key, key_location}` |
| `key_location` | `INDEXNOW_KEY_LOCATION` | `null` | абсолютный URL файла ключа, если это не `/{key}.txt` |
| `base_url` | `INDEXNOW_BASE_URL` | `null` | база для относительных URL; обязателен вне HTTP-запроса |
| `engines` | `INDEXNOW_ENGINES` (`api` или `yandex,bing`) | `['api']` | имена движков или свои `https://` endpoint'ы |
| `dispatch` | `INDEXNOW_DISPATCH` | `sync` | режим доставки, определяется адаптером; ядро его только показывает |
| `batch.max_urls` | `INDEXNOW_BATCH_MAX_URLS` | `10000` | URL в одном запросе (максимум протокола 10 000) |
| `debounce.per_url` | `INDEXNOW_DEBOUNCE_PER_URL` | `600` | секунд до повторной отправки того же URL (`0` = выкл.) |
| `throttle.max_requests_per_minute` | `INDEXNOW_THROTTLE_PER_MINUTE` | `60` | запросов в минуту на процесс (`0` = без лимита) |
| `http.timeout` | `INDEXNOW_HTTP_TIMEOUT` | `10.0` | секунды; применяется к клиентам, созданным discovery |
| `http.user_agent` | `INDEXNOW_USER_AGENT` | `indexnowkit-php/x.y.z` | |
| `serve_key_file` | `INDEXNOW_SERVE_KEY_FILE` | `true` | для адаптеров, отдающих `/{key}.txt` |
| `dry_run` | `INDEXNOW_DRY_RUN` | `false` | писать запрос в лог вместо отправки |
| `environment` | `INDEXNOW_ENV` / `APP_ENV` | — | всё кроме `prod`/`production` без ключа включает `dry_run` вместо ошибки |

```php
$config = Config::fromArray([
    'key' => $_ENV['INDEXNOW_KEY'],
    'base_url' => 'https://www.example.com',
    'engines' => ['api'],
    'debounce' => ['per_url' => 600],
]);
$config = $config->with(dryRun: true);   // неизменяемые копии по именам аргументов конструктора
```

## Что происходит с URL

1. **Нормализация** — `UrlNormalizer`: относительные пути дополняются `base_url`, схема и host приводятся к нижнему
   регистру, IDN-хосты переводятся в punycode, убираются порты по умолчанию и фрагменты, схлопываются dot-сегменты.
   Всё, что не является публичным `http(s)`-URL (другие схемы, логин/пароль, управляющие символы, слишком длинные
   хосты), отбрасывается с warning в логе.
2. **Дедупликация** внутри вызова.
3. **Дебаунс** — URL, успешно отправленные за последние `debounce.per_url` секунд, пропускаются. По умолчанию
   `MemoryDebounceStore` (на процесс); `Psr16DebounceStore` с любым PSR-16 кешем делает дебаунс общим для процессов.
   Отказ хранилища не блокирует доставку: отправка идёт без дедупликации, в лог пишется warning.
4. **Группировка по host** и подбор ключа (`KeyProviderInterface`). Host без ключа попадает в результат со статусом
   `skipped` и никогда не отправляется под чужим ключом.
5. **Нарезка** на батчи не больше `batch.max_urls`.
6. **Троттлинг** (`TokenBucket`, один токен на HTTP-запрос) и **POST** одного батча на каждый endpoint:
   `{"host", "key", "keyLocation"?, "urlList"}` как `application/json; charset=utf-8`.
7. **Интерпретация** ответа в `Result` и отметка успешных URL в хранилище дебаунса.

## Результаты

| `status` | HTTP | `retryable` | Смысл |
|---|---|---|---|
| `ok` | 200 | нет | принято |
| `pending` | 202 | нет | принято, проверка ключа отложена; считается успехом |
| `failed` | 400 | нет | некорректный запрос (это баг, сообщите) |
| `failed` | 403 | нет | файл ключа недоступен или не совпадает |
| `failed` | 422 | нет | URL не принадлежат host / `keyLocation` некорректен |
| `failed` | 429, 5xx, сеть | да | временно; `retryAfter` заполнен, если движок его прислал |
| `skipped` | — | нет | ничего не отправлено: `dry_run`, `disabled`, `debounced` или нет ключа для host (`error` объясняет) |

В `Result` также есть `engine`, `endpoint`, `host`, `urls`, `error`. `Result::urlsOf($results)` собирает URL
повторяемых результатов. Слушатели пригодятся для метрик и аудита; исключение в слушателе логируется и не мешает
остальным:

```php
$indexNow->submitter->addListener(fn (IndexNowKit\Result $r) => $metrics->increment("indexnow.{$r->status->value}"));
```

Логи идут в PSR-3 логгер, переданный в `IndexNow::create()`: `debug` для успеха, `info` для 202 и dry-run,
`warning` для 422/429/5xx/сети, `error` для 400/403. Пятый подряд 403 для host один раз пишется как `critical`
(повод для алерта: ничего не индексируется), дальше `warning`, пока успех не сбросит счётчик. Ключ везде маскируется.

## Повторы и очереди

Внутри веб-запроса ядро само не повторяет отправку. Два варианта:

```php
use IndexNowKit\Retry\{RetryPolicy, RetryingSubmitter};

// CLI, cron, воркеры: повтор в том же процессе с backoff (Retry-After; иначе 60 с → 120 с после 429, 5 с → 10 с после 5xx/сети; 3 попытки)
$submitter = new RetryingSubmitter($indexNow->submitter, new RetryPolicy(maxAttempts: 3, baseDelay: 60));
$submitter->submit($urls);

// Очереди: кладите список URL в очередь, воркер вызывает submit(), а Result::urlsOf($results)
// ставится обратно через (new RetryPolicy())->delayAfter($results, $attempt) секунд.
```

Копите URL в рамках единицы работы и отправляйте один раз в конце через `Collector` + `DispatcherInterface`:

```php
$indexNow->collect(['/posts/1', '/posts/2']);   // в любом месте запроса
$indexNow->flush();                              // в конце: SyncDispatcher шлёт, CallableDispatcher кладёт в очередь
```

## Сущности и атрибут `#[IndexNow]`

Пометьте классы, у которых есть публичная страница; атрибут один для всех PHP-адаптеров.

```php
use IndexNowKit\Attribute\IndexNow;

#[IndexNow(route: 'post_show', params: ['slug' => 'slug'], when: 'isPublished', fields: ['slug', 'title'])]
final class Post { ... }

#[IndexNow(resolver: PostUrls::class)]          // что угодно: несколько страниц, локали, внешний фронтенд
final class Product { ... }
```

| Опция | Смысл |
|---|---|
| `route` / `params` | имя маршрута и `param => свойство, геттер, "self" или dotted.path`; нужен `RouteUrlResolverInterface` (его дают адаптеры) |
| `resolver` | класс или id сервиса `UrlResolverInterface`, возвращающего URL объекта |
| `when` | bool-свойство/метод; неопубликованное пропускается, `published → draft` отправляется как удаление |
| `events` | подмножество `created`, `updated`, `deleted` (по умолчанию все) |
| `fields` | для обновлений: отправлять только если изменилось одно из этих полей (проверяют адаптеры) |
| `locales` | `current`, `all` или список — для локализованных маршрутов |

```php
$indexNow = IndexNow::create($config, resolver: new AttributeUrlResolver(new AttributeReader(), $router, $locator));
$indexNow->submitEntity($post, IndexNowKit\Event::Updated);
$urls = $indexNow->urlsFor($post, Event::Deleted);     // вычислить без отправки
```

`urlsFor()`/`submitEntity()` проходят через `GuardedUrlResolver`: подписка на событие, проверка `when` и резолвер
в одном месте, которое никогда не бросает исключений (ошибка уходит в лог, URL не возвращаются). ORM-хуки адаптеров
используют тот же объект, поэтому опечатка в атрибуте не сломает flush.

## Sitemap

```php
use IndexNowKit\Sitemap\SitemapReader;

$reader = new SitemapReader($transport, logger: $logger);
$urls = [];
foreach ($reader->read('https://www.example.com/sitemap.xml', changedSince: new DateTimeImmutable('-1 day')) as $entry) {
    $urls[] = $entry->url;                                // $entry->lastmod — DateTimeImmutable или null
}
$indexNow->submit($urls);
```

Индексы sitemap раскрываются (только те же схема, host и порт, 3 уровня, 1000 документов), документы и распакованный
`.gz` ограничены 50 МиБ (параметр конструктора `maxXmlBytes`; пик памяти примерно вдвое больше документа),
документы читаются потоково через `XMLReader`, внешние сущности отключены. Сломанный вложенный sitemap пропускается
с warning; сломанный корневой бросает `TransportException`.

## Несколько сайтов

```php
Config::fromArray([
    'hosts' => [
        'www.example.com' => 'KEY-FOR-EXAMPLE',
        'shop.example.com' => ['key' => 'KEY-FOR-SHOP', 'key_location' => 'https://shop.example.com/keys/indexnow.txt'],
    ],
]);
```

Поддомены для IndexNow — отдельные host, каждому нужен свой файл ключа. С `key` без `hosts` один ключ используется для
всех отправляемых host (файл ключа всё равно нужен на каждом), поэтому не передавайте в `submit()` URL из недоверенного
ввода: чужой host уйдёт под вашим ключом (поисковик его отклонит, но запрос состоится). Реализуйте `KeyProviderInterface`,
чтобы брать ключи из базы или реестра тенантов.

## Ограничения

- Один и тот же URL не отправляется повторно в течение `debounce.per_url` (по умолчанию 10 минут): так просит Яндекс.
  URL, изменившийся дважды за минуту, уйдёт один раз; поисковики всё равно перечитывают текущую версию.
- Внутри веб-запроса повторов нет: `429`/`5xx` возвращаются как `retryable`. Повторяйте из очереди или через
  `RetryingSubmitter` в CLI и воркерах.
- Только `http(s)`-URL на host, для которых есть ключ. Поддомены — отдельные host.
- `TokenBucket` ограничивает частоту на процесс; лимиты между процессами — задача очереди.
- Google через IndexNow недоступен.

## Точки расширения

| Интерфейс | По умолчанию | Замените, чтобы |
|---|---|---|
| `Http\TransportInterface` | `Psr18Transport::discover()` | использовать свой HTTP-стек (`Psr18Transport` принимает любой PSR-18 клиент) |
| `Key\KeyProviderInterface` | `StaticKeyProvider` | брать ключи из базы, по тенантам |
| `Url\UrlNormalizerInterface` | `UrlNormalizer` | вырезать трекинг-параметры, навязать слэш в конце, мапить host |
| `Url\UrlResolverInterface` | `AttributeUrlResolver` | превращать объекты в URL по-своему |
| `Debounce\DebounceStoreInterface` | `MemoryDebounceStore` | `Psr16DebounceStore` или своё хранилище |
| `Throttle\ThrottleInterface` | `TokenBucket` | `NullThrottle`, общий лимитер |
| `Dispatch\DispatcherInterface` | `SyncDispatcher` | `CallableDispatcher` для очереди, `NullDispatcher` |
| `SubmitterInterface` | `Submitter` | декорировать (`RetryingSubmitter`), записывать, мокать |
| `Attribute\AttributeReaderInterface` | `AttributeReader` | кешировать атрибуты в метаданных фреймворка |

Всё передаётся в `IndexNow::create()` или собирается вручную: `Client` → `Submitter` → `Collector` +
`DispatcherInterface` → `IndexNow`.

## Исключения

Все исключения реализуют `IndexNowKit\Exception\IndexNowException`:

- `ConfigurationException` — некорректный `Config`, атрибут или настройка резолвера (бросается при создании);
- `InvalidUrlException` — URL, который нельзя отправить (`Submitter` ловит его и отбрасывает URL с warning);
- `InvalidArgumentException` — ошибки программиста (пустой батч, длина ключа);
- `Http\Exception\TransportException` — сетевой сбой; `Client` превращает его в `failed` и `retryable` результат,
  наружу его отдают только `SitemapReader` и `Checker` для корневого документа.

## Требования

PHP 8.2+, `ext-json`, `ext-filter`. Опционально: `ext-intl` (IDN по UTS #46; иначе pure-PHP punycode), `ext-xmlreader`
и `ext-zlib` для `SitemapReader`. PSR-18 клиент с PSR-17 фабриками: `symfony/http-client` и Guzzle настраиваются
автоматически (таймаут, без редиректов), остальные клиенты используются как есть.

## Версионирование

SemVer. До 1.0 минорные версии могут содержать ломающие изменения; они перечислены в [CHANGELOG.md](CHANGELOG.md).
Классы с пометкой `@internal` не входят в обещание обратной совместимости.

## Другие пакеты

| | |
|---|---|
| PHP | [symfony-bundle](../symfony-bundle), [doctrine](../doctrine), laravel (в планах) |
| JS/TS | `@indexnowkit/core`, `next`, `prisma` (в планах) |
| Python | `indexnowkit`, `indexnowkit-django` (в планах) |

Спецификация и conformance-набор: [indexnowkit/spec](https://github.com/indexnowkit/spec).

MIT.
