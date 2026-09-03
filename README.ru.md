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
use IndexNowKit\IndexNowKit;

$indexNow = IndexNowKit::create(Config::fromEnv());   // INDEXNOW_KEY, INDEXNOW_BASE_URL, ...

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
$key = IndexNowKit\Key\KeyGenerator::generate();       // 32 hex-символа, CSPRNG
file_put_contents("public/$key.txt", $key);            // либо отвечайте на запрос сами:
$body = (new KeyFileResponder($indexNow->keys))->bodyForPath($path, $host);   // null -> 404
```

Файл должен отдаваться с `200 OK`, `text/plain`, без редиректов; нужные заголовки даёт
`KeyFileResponder::headers()`. Если он лежит в другом месте того же host, укажите `key_location`. `Check\Checker`
проверяет конфигурацию, скачивает каждый файл ключа и с `liveProbe: true` отправляет настоящий пробный запрос.
`403` всегда означает проблему с файлом ключа; про ротацию — в [docs/operations.md](docs/operations.md).

## Что происходит с URL

1. **Нормализация** — относительные пути раскрываются от `base_url`, схема и host приводятся к нижнему регистру,
   IDN-хосты переводятся в punycode, порт по умолчанию и фрагмент убираются, точечные сегменты схлопываются. Всё,
   что не является публичным `http(s)`-URL, отбрасывается с предупреждением.
2. **Дедупликация** внутри вызова, затем **дебаунс**: URL, успешно отправленные за последние `debounce.per_url`
   секунд, пропускаются. Отказавшее хранилище не блокирует доставку — просто перестаёт дедуплицировать и пишет
   предупреждение.
3. **Группировка по host** и поиск ключа. Хосты без ключа дают `skipped` и никогда не уходят под чужим ключом.
4. **Нарезка** на батчи не больше `batch.max_urls`, **троттлинг** (один токен на HTTP-запрос) и **POST** одного
   батча на каждый endpoint: `{"host", "key", "keyLocation"?, "urlList"}` как `application/json; charset=utf-8`.
5. **Интерпретация** ответа в `Result` и отметка успешных URL в хранилище дебаунса.

## Результаты

| `status` | HTTP | `reason` | `retryable` | Смысл |
|---|---|---|---|---|
| `ok` | 200 | — | нет | принято |
| `pending` | 202 | — | нет | принято, проверка ключа отложена; считается успехом |
| `failed` | 400 | `invalid_request` | нет | некорректный запрос (баг: сообщите нам) |
| `failed` | 403 | `invalid_key` | нет | файл ключа недоступен или не совпадает |
| `failed` | 422 | `unprocessable` | нет | URL не принадлежат host / неверный `keyLocation` |
| `failed` | 429 | `rate_limited` | да | `retryAfter` заполнен, если движок его прислал |
| `failed` | 5xx | `server_error` | да | |
| `failed` | — | `transport` | да | сетевой сбой или таймаут |
| `failed` | — или другой | `unexpected` | см. ниже | некорректный HTTP-клиент (повторяемо) или статус, которого движок отдавать не должен (нет) |
| `skipped` | — | `disabled` `dry_run` `debounced` `no_key` `invalid_url` | нет | ничего не отправлено |

`Reason` — стабильный идентификатор для метрик и алертов, `Result::$error` — человеческая формулировка. Решение о
повторе принимайте по `Result::$retryable`, а не по причине. `Result` также несёт `engine`, `endpoint`, `host`,
`urls`, `httpCode` и `metricLabels()`; `Result::retryableUrls($results)` собирает URL, которые стоит повторить.

```php
$indexNow->submitter->addListener(fn (IndexNowKit\Result $r) => $metrics->increment('indexnow_results_total', $r->metricLabels()));
```

Строки лога уходят в PSR-3 логгер, переданный в `IndexNowKit::create()`. Уровни, точные тексты и чеклист
«почему URL не отправился» — в [docs/operations.md](docs/operations.md).

## Объявление страниц: `#[IndexNow]`

`#[IndexNow]` **повторяем**: один атрибут на семейство публичных URL объекта. Ровно один источник на правило —
`route`, `resolver`, `via`, `url` или `urls`. Политика уровня класса живёт в `#[IndexNowDefaults]`, её `when`
объединяется по «И» с собственным `when` правила (страница черновика не публична, что бы ни говорило правило).

```php
use IndexNowKit\Attribute\{IndexNow, IndexNowDefaults, IndexNowUrl};
use IndexNowKit\Attribute\Param\{Accessor, Call, Formatted, Placeholder, Value};

#[IndexNowDefaults(when: 'isPublished', fields: ['slug', 'title', 'body', 'published'])]
#[IndexNow(route: 'post_show', params: ['slug' => 'slug'])]          // страница статьи
#[IndexNow(route: 'post_amp', params: ['slug' => 'slug'], when: 'hasAmp', whenFields: ['ampEnabled'])]
#[IndexNow(via: 'category')]                                          // переотправить страницу категории
#[IndexNow(via: 'tags')]                                              // и каждую страницу тега
#[IndexNow(urls: ['/', '/blog'])]                                     // и два литеральных URL
class Post {}
```

Типизированные источники параметров — рядом с обычной строкой-accessor (свойство, геттер, метод `is`/`has`,
`точечный.путь`, `self`):

```php
#[IndexNow(route: 'post_show', params: [
    'year'    => new Formatted('publishedAt', 'Y'),         // DateTimeInterface::format()
    'cat'     => 'category.slug',                            // точечный путь через связь
    'section' => new Value('blog'),                          // константа
    'slug'    => new Call('slugFor', Placeholder::Locale),   // вызов метода, один URL на локаль
])]
```

Остальные формы — все реальные случаи:

```php
#[IndexNow(url: 'publicUrl')]                       // свойство или метод, возвращающий string|iterable<string>|null
#[IndexNow(resolver: SyliusChannelUrls::class)]     // класс или id сервиса UrlResolverInterface
#[IndexNow(route: 'page_show', params: ['slug' => 'slug'], host: new Accessor('tenant.domain'))]  // мультидомен
#[IndexNow(route: 'post_show', params: ['slug' => 'slug'], locales: 'all')]                       // локализованные маршруты
class Page {}

class Offer
{
    #[IndexNowUrl(when: 'isLive')]                  // конвенция get_absolute_url()
    public function getPublicUrl(): string { return '/offers/' . $this->code; }
}
```

Правила наследуются от родительских классов и различаются по `name` (выводится из источника либо задаётся явно):
правило наследника с именем предка **заменяет** его, а новое имя **добавляет** страницу.

### Семантика удаления

Видимость (`when`) вычисляется для каждого правила до и после изменения. `true → false` отправляет URL этого правила
как **удаление**, чтобы поисковики переобошли 404; `false → true` — как создание; без перехода это обновление,
отфильтрованное по `fields`. Удаление объекта, к которому правило неприменимо, не отправляет ничего: страница и так
не была публичной.

`when` часто задаётся геттером (`isPublished`), а в change set ORM лежит поле (`published`). Конвенция
`isPublished → published`/`is_published` и `getStatus → status` применяется автоматически; если имена не связаны,
перечислите поля в `whenFields`. Строковый или enum-статус не булев: используйте
`when: new Equals('status', 'published')` (`IndexNowKit\Attribute\Param\Equals`); правила, зарегистрированные в
рантайме, могут передать замыкание.

Полная модель, таблица семантики и типы для адаптеров (`UrlRule`, `RuleSet`, `RuleRegistry`):
[docs/attribute-reference.md](docs/attribute-reference.md).

```php
$indexNow = IndexNowKit::create($config, resolver: new AttributeUrlResolver(new AttributeReader(), $router, $locator));
$indexNow->submitEntity($post, IndexNowKit\Event::Updated);
$urls = $indexNow->urlsFor($post, Event::Deleted);   // вычислить, не отправляя
$rows = $indexNow->explain($post, Event::Updated);   // ResolvedUrl: какое правило дало какой URL
```

`urlsFor()`, `explain()` и `submitEntity()` идут через `GuardedUrlResolver`, который никогда не бросает исключений:
некорректный атрибут пишется в лог и не даёт URL, так что опечатка не может сломать flush.

## Конфигурация

| Опция | Env | По умолчанию | Смысл |
|---|---|---|---|
| `enabled` | `INDEXNOW_ENABLED` | `true` | `false` отбрасывает все отправки (лог уровня `info`) |
| `key` | `INDEXNOW_KEY` | — | ключ по умолчанию, для всех хостов вне `hosts` |
| `hosts` | `INDEXNOW_HOSTS` (`a.com=KEY1,b.com=KEY2`) | `[]` | по хосту `{key, key_location, base_url}` |
| `strict_hosts` | `INDEXNOW_STRICT_HOSTS` | `false` | ключ по умолчанию применяется только к хосту `base_url` |
| `base_url` | `INDEXNOW_BASE_URL` | `null` | раскрывает относительные URL; обязателен вне HTTP-запросов |
| `engines` | `INDEXNOW_ENGINES` | `['api']` | имена движков или свои `https://`-endpoint |
| `dispatch` | `INDEXNOW_DISPATCH` | `sync` | режим доставки, определяемый адаптером; core только сообщает его |
| `batch.max_urls` | `INDEXNOW_BATCH_MAX_URLS` | `10000` | URL в одном запросе (максимум протокола) |
| `debounce.per_url` | `INDEXNOW_DEBOUNCE_PER_URL` | `600` | секунд до повторной отправки того же URL (`0` = выключено) |
| `throttle.max_requests_per_minute` | `INDEXNOW_THROTTLE_PER_MINUTE` | `60` | запросов в минуту на процесс (`0` = без лимита) |
| `http.timeout` | `INDEXNOW_HTTP_TIMEOUT` | `10.0` | секунды, применяются к клиентам, созданным через discovery |
| `dry_run` | `INDEXNOW_DRY_RUN` | `false` | писать запрос в лог вместо отправки |
| `environment` | `INDEXNOW_ENV` / `APP_ENV` | — | всё, кроме `prod`/`production`, без ключа включает `dry_run` |

Есть ещё `serve_key_file`, `http.user_agent` и `key_location`. Каждое значение проверяется в конструкторе, поэтому
неверная настройка падает при старте, а не при первой отправке. Полный справочник, переопределения по хостам,
`Config::with()`, `Config::OPTIONS` и `unknownOptions()`: [docs/configuration.md](docs/configuration.md).

## Повторы, очереди и массовые отправки

Внутри веб-запроса повторов нет: `429`/`5xx` возвращаются как `retryable`. Используйте `RetryingSubmitter` в CLI,
cron и воркерах либо ставьте `Result::retryableUrls($results)` обратно в очередь через
`(new RetryPolicy())->delayAfter($results, $attempt)` секунд. Собирайте URL за единицу работы, доставляйте один раз:

```php
$indexNow->collect(['/posts/1', '/posts/2']);   // в любом месте запроса
$indexNow->flush();                              // в конце единицы работы
```

Рецепт воркера и рекомендации по массовым выгрузкам — в [docs/retries-and-queues.md](docs/retries-and-queues.md).

`Sitemap\SitemapReader` потоково читает sitemap или его индекс в объекты `SitemapEntry`, при необходимости
фильтруя по `<lastmod>`, — это нужный инструмент, чтобы переотправить массовое изменение:
`$reader->read($sitemapUrl, new DateTimeImmutable('-1 day'))`. Память не зависит от размера: документы
складываются во временные файлы (прямо из сокета, если транспорт реализует `Http\StreamingTransportInterface`,
как `Psr18Transport`), gzip распаковывается по кускам, XMLReader идёт по файлу, записи отдаются по одной.
Отправляйте их порциями по `Config::$batchMaxUrls`, а не собирайте генератор в массив. Индексы обходятся только
в пределах origin корневого sitemap (`allowForeignHosts: true` или одноимённый аргумент `read()` разрешает части на
CDN), с ограничениями на глубину, количество документов и размер документа.

## Тестирование

`IndexNowKit\Testing` входит в публикуемый пакет: `FakeTransport` (записывает POST, отдаёт ответы из очереди),
`ArrayLogger`, `FrozenClock`, `RecordingDispatcher`.

```php
$transport = new FakeTransport();
$indexNow = IndexNowKit::create($config, transport: $transport, debounce: new NullDebounceStore());
$indexNow->submitEntity($post);

self::assertSame(['https://www.example.com/posts/hello'], $transport->posts[0]['body']['urlList']);
```

Больше рецептов — в [docs/testing.md](docs/testing.md).

## Точки расширения

| Интерфейс | По умолчанию | Заменяйте, чтобы |
|---|---|---|
| `Http\TransportInterface` | `Psr18Transport::discover()` | использовать свой HTTP-стек (`LazyTransport` откладывает его создание) |
| `Key\KeyProviderInterface` | `StaticKeyProvider` | брать ключи из базы, по тенанту |
| `Url\UrlNormalizerInterface` | `UrlNormalizer` | убирать трекинг-параметры, задавать политику слеша, маппить хосты |
| `Url\UrlResolverInterface` | `AttributeUrlResolver` | превращать объекты в URL по-своему |
| `Url\RouteUrlResolverInterface` | — (даёт адаптер) | подключить роутер своего фреймворка |
| `Attribute\AttributeReaderInterface` | `AttributeReader` | `RuleRegistry` для правил в рантайме или свой источник метаданных |
| `Collector\CollectorInterface` | `Collector` | надёжный outbox, буфер на тенанта |
| `Debounce\DebounceStoreInterface` | `MemoryDebounceStore` | `Psr16DebounceStore` или своё |
| `Throttle\ThrottleInterface` | `TokenBucket` | `NullThrottle`, общий лимитер |
| `Dispatch\DispatcherInterface` | `SyncDispatcher` | `CallableDispatcher` для очереди, `NullDispatcher` |
| `SubmitterInterface` | `Submitter` | декорировать (`RetryingSubmitter`), записывать, мокать |

Передайте любой из них в `IndexNowKit::create()` по имени аргумента или соберите граф вручную: `Client` →
`Submitter` → `Collector` + `DispatcherInterface` → `IndexNowKit`. Пишете адаптер?
[docs/adapters.md](docs/adapters.md).

## Исключения

Все исключения реализуют `IndexNowKit\Exception\IndexNowException`: `ConfigurationException` (неверный `Config`,
атрибут или настройка резолвера), `InvalidUrlException` (URL, который нельзя отправить, — перехватывается
`Submitter` и отбрасывается с предупреждением), `InvalidArgumentException` (программные ошибки) и
`Http\Exception\TransportException` (сетевой сбой, превращаемый `Client` в повторяемый `Result`; наружу его отдают
только `SitemapReader` и `Checker`). Из хуков жизненного цикла не вылетает ничего — контракт ошибок описан в
[docs/adapters.md](docs/adapters.md).

## Ограничения

- Один и тот же URL не отправляется повторно в течение `debounce.per_url` (по умолчанию 10 минут): этого просит Яндекс.
- Внутри веб-запроса повторов нет; `TokenBucket` ограничивает в пределах процесса. Межпроцессные лимиты — задача очереди.
- Только `http(s)`-URL на хостах, для которых у вас есть ключ. Поддомены — отдельные хосты, каждому нужен свой файл ключа.
- Массовые операции ORM обходят хуки сущностей во всех адаптерах: такие URL отправляйте сами.
- До Google через IndexNow не достучаться.

## Требования

PHP 8.2+, `ext-json`, `ext-filter`, PSR-18 клиент с PSR-17 фабриками (`symfony/http-client` и Guzzle настраиваются
автоматически — таймаут, без редиректов; остальные используются как есть). Опционально: `ext-intl` (IDN по UTS #46,
иначе чистый PHP-punycode), `ext-xmlreader` и `ext-zlib` для `SitemapReader`.

## Версионирование

SemVer. До 1.0 минорные версии могут содержать ломающие изменения; они перечислены в [CHANGELOG.md](CHANGELOG.md).
Что покрыто обещанием совместимости, а что нет: [docs/bc.md](docs/bc.md).

## Другие пакеты

| | |
|---|---|
| PHP | [symfony-bundle](../symfony-bundle), [doctrine](../doctrine), laravel (в планах) |
| JS/TS | `@indexnowkit/core`, `next`, `prisma` (в планах) |
| Python | `indexnowkit`, `indexnowkit-django` (в планах) |

Обоснование архитектуры и межъязыковая модель: [docs/spec](../../../docs/spec).
Conformance-набор: [indexnowkit/spec](https://github.com/indexnowkit/spec).

MIT.
