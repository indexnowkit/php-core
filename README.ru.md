# IndexNow-клиент для PHP — `indexnowkit/core`

Сообщайте Яндексу, Bing и остальным поисковикам с поддержкой [IndexNow](https://yandex.ru/support/webmaster/ru/indexing-options/index-now),
какие URL изменились, из любого PHP-приложения. Батчи, дебаунс, троттлинг, политика повторов, файл ключа и модель
правил `#[IndexNow]` поверх PSR-18 / PSR-17 / PSR-3 / PSR-16, без привязки к фреймворку. Адаптеры ([Symfony](https://github.com/indexnowkit/php/tree/main/packages/symfony-bundle),
[Doctrine](https://github.com/indexnowkit/php/tree/main/packages/doctrine), [Laravel](https://github.com/indexnowkit/php/tree/main/packages/laravel), [Yii2](https://github.com/indexnowkit/php/tree/main/packages/yii2)) и пакеты-дополнения построены на этом пакете;
напрямую он нужен для чистого PHP, плагинов CMS и своих фреймворков.

[English version](README.md) · Issues и pull requests: [github.com/indexnowkit/php](https://github.com/indexnowkit/php/issues) (репозитории `php-*` — read-only сплиты)

[![Packagist](https://img.shields.io/packagist/v/indexnowkit/core)](https://packagist.org/packages/indexnowkit/core)
[![Downloads](https://img.shields.io/packagist/dt/indexnowkit/core)](https://packagist.org/packages/indexnowkit/core)
[![CI](https://github.com/indexnowkit/php/actions/workflows/ci.yml/badge.svg)](https://github.com/indexnowkit/php/actions)
[![Conformance](https://img.shields.io/badge/conformance-core%2022%2F22-brightgreen)](https://github.com/indexnowkit/spec)
![Coverage](https://img.shields.io/badge/coverage-%E2%89%A5%2081%25%20enforced-brightgreen)
![PHPStan](https://img.shields.io/badge/phpstan-level%209-4c1)
![PHP](https://img.shields.io/badge/php-%5E8.2-777bb4)
[![License](https://img.shields.io/packagist/l/indexnowkit/core)](LICENSE)

## Кто получит уведомление

**Яндекс, Bing (и DuckDuckGo через Bing), Naver, Seznam, Yep, Internet Archive, Amazon** — все участники
[реестра](https://www.indexnow.org/searchengines.json) IndexNow. Один запрос на общий endpoint `api.indexnow.org`
доходит до всех; перечислять движки явно (`engines: [yandex, bing]`) нужно только чтобы отправить в один. У Internet
Archive на момент написания нет работающего прямого endpoint — до него доходит через `api`.

**Google: нет.** Google не поддерживает IndexNow, ping-endpoint для sitemap закрыт, а Indexing API ограничен
`JobPosting` / `BroadcastEvent`. Для Google остаётся sitemap; библиотека не будет делать вид, что это не так.

**Уведомление, не индексация.** IndexNow сообщает поисковику, что URL изменился; обойти и проиндексировать страницу — его
решение и его сроки. Результат виден в Bing Webmaster Tools (IndexNow Insights) и в Яндекс.Вебмастере (Индексирование →
Переобход страниц); полезная метрика — доля отправленных URL в индексе через несколько дней. Удалённые страницы: отдавайте
410 (навсегда) или 404 (временно); при переезде — 301 и отправка обоих URL; soft-404 и редирект на главную вредят.
Bing URL Submission API и Google Indexing API — другие протоколы, здесь не покрываются.

## Почему это, а не X

Большинство пакетов IndexNow — тонкий HTTP-клиент: URL собираете вы, вызываете вы, ответ читаете вы. Это семейство делает
то, что на практике ломается:

- **Объявлено на модели** (`#[IndexNow]`) и отправляется из хуков ORM — нет кода в контроллере, который можно забыть.
- **После commit**, не на flush: откатившаяся транзакция ничего не объявляет.
- **Дебаунс** (10 минут на URL, через ваш кэш), **батчи** до 10 000 URL, ключ на host из env.
- **Ответы обработаны**: 202 (ключ проверяется), 422, 429 с `Retry-After` и повтором через вашу очередь, эскалация 403.
- **`check` до первой отправки** говорит, что не так (файл ключа, движки, очередь, кэш, окружение); `explain` — почему URL ушёл или не ушёл.
- **Одно ядро** под адаптерами Symfony, Laravel, Yii2 и Doctrine с общим conformance-набором: поведение одинаковое везде и описано один раз.


## Установка

```bash
composer require indexnowkit/core symfony/http-client nyholm/psr7   # подойдёт любой PSR-18 клиент с PSR-17 фабриками
```

Если у вас фреймворк, берите адаптер: он собирает всё описанное ниже через контейнер и подключается к изменениям
сущностей. Семейство:

| Пакет | Что |
|---|---|
| `indexnowkit/core` | этот пакет: клиент протокола, правила, файл ключа, набор для адаптеров |
| [`indexnowkit/doctrine`](https://github.com/indexnowkit/php/tree/main/packages/doctrine) | слушатель Doctrine ORM и DBAL middleware, безопасно к коммиту |
| [`indexnowkit/symfony-bundle`](https://github.com/indexnowkit/php/tree/main/packages/symfony-bundle) | Symfony: конфигурация, Messenger, маршрут файла ключа, команды, панель профайлера |
| [`indexnowkit/laravel`](https://github.com/indexnowkit/php/tree/main/packages/laravel) | Laravel: observer Eloquent, очередь, маршрут файла ключа, artisan-команды |
| [`indexnowkit/yii2`](https://github.com/indexnowkit/php/tree/main/packages/yii2) | Yii2: события ActiveRecord с проверкой на коммите, yii2-queue, консольный контроллер |
| [`indexnowkit/sitemap`](https://github.com/indexnowkit/php/tree/main/packages/sitemap) | читает sitemap (индекс, gzip, текст) и отправляет его URL; команда `sitemap` каждого адаптера |

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
[docs/attribute-reference.ru.md](docs/attribute-reference.ru.md).

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

Есть ещё `key_file.enabled`, `http.user_agent` и `key_location`. Каждое значение проверяется в конструкторе, поэтому
неверная настройка падает при старте, а не при первой отправке. Полный справочник, переопределения по хостам,
`Config::with()`, `Config::OPTIONS` и `unknownOptions()`: [docs/configuration.ru.md](docs/configuration.ru.md).

## Повторы, очереди и массовые отправки

Внутри веб-запроса повторов нет: `429`/`5xx` возвращаются как `retryable`. Используйте `RetryingSubmitter` в CLI,
cron и воркерах либо ставьте `Result::retryableUrls($results)` обратно в очередь через
`(new RetryPolicy())->delayAfter($results, $attempt)` секунд. Собирайте URL за единицу работы, доставляйте один раз:

```php
$indexNow->collect(['/posts/1', '/posts/2']);   // в любом месте запроса
$indexNow->flush();                              // в конце единицы работы
```

Рецепт воркера и рекомендации по массовым выгрузкам — в [docs/retries-and-queues.md](docs/retries-and-queues.md).

Переотправить массовое изменение по списку URL самого сайта — задача пакета-дополнения из таблицы семейства
(Установка); `$kit->transport` — транспорт, через который такие потребители читают документы.

Адаптер подтверждает свою обвязку через `Testing\Conformance\CoreConformanceTestCase`: наследуешь, отдаёшь
фасад из своего контейнера и его `FakeTransport`, и сценарии протокола из спеки гоняются против него.

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
| `Url\UrlResolverInterface` | `NullUrlResolver` — соберите `AttributeUrlResolver` и передайте его как `resolver:` | превращать объекты в URL по-своему |
| `Url\RouteUrlResolverInterface` | — (даёт адаптер) | подключить роутер своего фреймворка |
| `Attribute\AttributeReaderInterface` | `AttributeReader` | `RuleRegistry` для правил в рантайме или свой источник метаданных |
| `Collector\CollectorInterface` | `Collector` | надёжный outbox, буфер на тенанта |
| `Debounce\DebounceStoreInterface` | `MemoryDebounceStore` | `Psr16DebounceStore` или своё |
| `Throttle\ThrottleInterface` | `TokenBucket` | `NullThrottle`, общий лимитер |
| `Dispatch\DispatcherInterface` | `SyncDispatcher` | `CallableDispatcher` для очереди, `NullDispatcher` |
| `SubmitterInterface` | `Submitter` | декорировать (`RetryingSubmitter`), записывать, мокать |

Передайте любой из них в `IndexNowKit::create()` по имени аргумента или соберите граф вручную: `Client` →
`Submitter` → `Collector` + `DispatcherInterface` → `IndexNowKit`. У узлов, которые адаптер собирает из своей
конфигурации, есть фабрики с единым источником текстов ошибок — `Http\TransportFactory::lazy()` (`http.client`),
`Debounce\DebounceStoreFactory::fromConfig()` (`debounce.store`), `Dispatch\DispatcherFactory::fromConfig()`
(`dispatch`), `fromConfig()` у `Collector`, `TokenBucket`, `AttributeUrlResolver` и `KeyFileResponder`, — а
`Adapter\ConfigFactory` превращает сырой массив фреймворка в `Config`, никогда не бросая из хука. Контейнер, который
собирается в рантайме, описывает весь граф один раз через `Adapter\ServicesBuilder` и лениво получает его из
`Adapter\Services`; ORM-хуки делят `Hook\ObserverHelper`, задачи очередей — `Retry\WorkerOutcome`, команды —
`Console\Definitions`. Пишете адаптер? [docs/adapters.md](docs/adapters.md).

## Исключения

Все исключения реализуют `IndexNowKit\Exception\IndexNowException`: `ConfigurationException` (неверный `Config`,
атрибут или настройка резолвера), `InvalidUrlException` (URL, который нельзя отправить, — перехватывается
`Submitter` и отбрасывается с предупреждением), `InvalidArgumentException` (программные ошибки) и
`Http\Exception\TransportException` (сетевой сбой, превращаемый `Client` в повторяемый `Result`; наружу его видят
потребители, читающие документы через транспорт; `Checker` превращает его в строку отчёта). Из хуков жизненного цикла не вылетает ничего — контракт ошибок описан в
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
иначе чистый PHP-punycode).

## Версионирование

SemVer. До 1.0 минорные версии могут содержать ломающие изменения; они перечислены в [CHANGELOG.md](CHANGELOG.md).
Что покрыто обещанием совместимости, а что нет: [docs/bc.md](docs/bc.md).

## Заметки для AI-ассистентов

- Composer-пакет `indexnowkit/core` (PHP ^8.2, нужен PSR-18 клиент + PSR-17 фабрики). Во фреймворке берите адаптер: `indexnowkit/symfony-bundle`, `indexnowkit/laravel`, `indexnowkit/yii2`, `indexnowkit/doctrine`.
- Минимальный полный сниппет (все `use` на месте):

```php
use IndexNowKit\Config;
use IndexNowKit\IndexNowKit;

$indexNow = IndexNowKit::create(Config::fromEnv());          // INDEXNOW_KEY, INDEXNOW_BASE_URL, INDEXNOW_DRY_RUN
$results = $indexNow->submit(['/posts/hello']);              // list<IndexNowKit\Result>, never throws for remote errors
```

- Проверка: `(new IndexNowKit\Check\Checker($config, $indexNow->keys, $indexNow->transport))->run()` — то, что запускает команда `check` адаптеров; `$indexNow->explain($object)` показывает правило за каждым URL; каждый удалённый исход — `Result` со `status`/`reason`, ничего не бросается.
- Ловушки:
  - `dispatch: auto` есть в Symfony (`auto` | `messenger` | `sync` | `none`) и Yii2 (`auto` | `queue` | `sync` | `none`), в Laravel **нет** (`queue` | `sync` | `none`).
  - Локали: `router.locales` в Laravel, `router.languages` в Yii2, `framework.enabled_locales` в Symfony; `locales: 'all'` у правила берёт этот список.
  - `url:` — имя аксессора (метод или свойство), который возвращает URL; `urls:` — список литеральных URL. Литерал в `url:` не ставить.
  - Строка в `when:` — аксессор, читаемый как truthy (`published`, `isPublished`). Строка статуса требует `Equals`: `when: new Equals('status', 'published')` (`IndexNowKit\Attribute\Param\Equals`).
  - Ручная отправка: `submitEntity()` в Symfony, `submitModel()` в Laravel, `submitRecord()` в Yii2; команды — `indexnow:submit-entity`, `indexnow:submit-model`, `indexnow/submit-record`. Массовые запросы (`update()`, `DB::table()`, `updateAll()`) хуков не вызывают — отправляйте ими после.
  - В Laravel два класса `IndexNowKit`: фасад `IndexNowKit\Laravel\Facades\IndexNowKit` и сервис ядра `IndexNowKit\IndexNowKit` (инжектится по типу). В Yii2 ядро — `Yii::$app->indexnow->kit()`.
  - Вне production настроенный ключ с незаданным `dry_run` делает `check` красным (стейджинг отправил бы боевые URL): задайте там `dry_run: true`, либо явный `dry_run: false`, если отправка нарочно.
  - Неизвестные ключи конфигурации дают warning при загрузке (опечатки вроде debounce.per_urls); список — `Config::OPTIONS` плюс ключи адаптера.


## Другие пакеты

| | |
|---|---|
| PHP | таблица семейства в разделе [Установка](#установка) |
| JS/TS | `@indexnowkit/core`, `next`, `prisma` (в планах) |
| Python | `indexnowkit`, `indexnowkit-django` (в планах) |

Обоснование архитектуры и межъязыковая модель: [docs/spec](https://github.com/indexnowkit/spec).
Conformance-набор: [indexnowkit/spec](https://github.com/indexnowkit/spec).

MIT. IndexNow — товарный знак его владельца; проект независимый и не связан с Microsoft, Яндексом или indexnow.org.
