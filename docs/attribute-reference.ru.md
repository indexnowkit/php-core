# Справочник по атрибутам

[English version](attribute-reference.md)

Класс объявляет **список правил** — по одному на семейство публичных URL, которые у него есть. В PHP это атрибуты; в
других языках семейства — декораторы или конфиг-объекты. Все они компилируются в один и тот же
`IndexNowKit\Attribute\UrlRule`, и всё ниже по течению — классификация событий, guard'ы, локали, делегирование `via`,
дедупликация, вывод `explain` — работает только с ним.

## Три атрибута

| Атрибут | Цель | Назначение |
|---|---|---|
| `#[IndexNow]` | класс, **повторяемый** | одно правило URL |
| `#[IndexNowDefaults]` | класс | политика, общая для всех правил класса и его потомков |
| `#[IndexNowUrl]` | публичный метод | возвращаемое значение метода — семейство URL (конвенция `get_absolute_url()`) |

```php
use IndexNowKit\Attribute\{IndexNow, IndexNowDefaults, IndexNowUrl};

#[IndexNowDefaults(when: 'isPublished', fields: ['slug', 'title', 'body', 'published'])]
#[IndexNow(route: 'post_show', params: ['slug' => 'slug'])]
#[IndexNow(route: 'post_amp', params: ['slug' => 'slug'], when: 'hasAmp', whenFields: ['ampEnabled'])]
class Post {}
```

## Источники

Ровно один источник на `#[IndexNow]`. Ноль источников или два — `ConfigurationException` при компиляции с сообщением,
называющим виновников.

| Источник | Значение | Даёт |
|---|---|---|
| `route` | имя маршрута фреймворка | один URL на локаль, сгенерированный `RouteUrlResolverInterface` адаптера |
| `resolver` | класс `UrlResolverInterface` или id сервиса | то, что вернёт резолвер |
| `via` | аксессор к связанному объекту или коллекции | собственные URL связанных объектов, разрешённые как обновления |
| `url` | аксессор, возвращающий `string`, `iterable<string>` или `null` | эти URL |
| `urls` | список литеральных URL | эти URL, абсолютные или относительно `base_url` |

`url` и `urls` легко перепутать, поэтому проверяются оба: `url: '/about'` и `urls: ['aboutUrl']` отклоняются с
сообщением, какой из них вы имели в виду.

`resolver` требует `ResolverLocatorInterface`. В чистом PHP это `ArrayResolverLocator`, который также создаёт класс
по имени, если у его конструктора нет обязательных аргументов. Адаптеры фреймворков ищут id в контейнере.

## Параметры

`params` сопоставляет имя параметра маршрута источнику. Простая строка — DSL аксессоров; всё остальное — один из
четырёх типизированных объектов `Param\ParamValue`.

### DSL аксессоров

Разрешается в этом порядке, на самом объекте:

1. `'self'` — сам объект (route model binding: `params: ['post' => 'self']`);
2. путь через точку — каждый сегмент разрешается рекурсивно (`'category.slug'`); не-объект в середине бросает исключение;
3. метод с точно таким именем;
4. `get`, `is` или `has` плюс имя с заглавной (`'published'` находит `getPublished()`, затем `isPublished()`, затем `hasPublished()`);
5. свойство, в том числе приватное.

Ничего не подошло — `ConfigurationException` с именем аксессора и класса.

### Типизированные источники

| Класс | Пример | Смысл |
|---|---|---|
| `Param\Accessor` | `new Accessor('category.slug')` | явная форма строкового аксессора |
| `Param\Value` | `new Value('html')` | константа |
| `Param\Formatted` | `new Formatted('publishedAt', 'Y')` | `DateTimeInterface::format()` значения аксессора |
| `Param\Call` | `new Call('slugFor', Placeholder::Locale)` | вызов метода; дополнительные аргументы передаются как есть |

`Param\Placeholder::Locale` и `Param\Placeholder::Host` подставляются для каждого генерируемого URL, так что `Call`
может вернуть slug для локали или путь для тенанта. Извлечение выполняется один раз на URL, а не на правило.

### Приведение типов

Параметры маршрута должны годиться для URL. Экстрактор принимает `null` и скаляры как есть, разворачивает `BackedEnum`
в его `value`, приводит `Stringable` value object к строке и пропускает обычные объекты для route model binding. Голый
`DateTimeInterface` отклоняется с указанием на `new Formatted(...)`: неявное форматирование даты — так URL незаметно
меняет форму. Всё остальное бросает исключение.

## Опции правила

| Опция | Тип | Дефолт | Смысл |
|---|---|---|---|
| `when` | имя аксессора, `new Equals(path, value)` или замыкание `fn(object): bool` (только правила времени выполнения) | наследуется | страница существует, пока условие истинно |
| `whenFields` | список имён полей | `[]` | поля, стоящие за `when` этого правила, когда имя не совпадает с полем (у `when` уровня класса свои `whenFields` в `#[IndexNowDefaults]`) |
| `fields` | список имён полей или `null` | наследуется, затем `[]` | только для обновлений: отправлять, когда изменилось одно из них; `[]` = любое поле |
| `events` | подмножество `created`, `updated`, `deleted` (строки или case'ы `Event`) или `null` | наследуется, затем все три | какие события жизненного цикла слушает правило |
| `locales` | `'current'`, `'all'` или список, или `null` | наследуется, затем `'current'` | разворачивание локалей для локализованных маршрутов |
| `host` | строка или `ParamValue` | `null` | генерировать URL этого правила на этом хосте (мультидомен) |
| `name` | строка | выводится | стабильный id правила для логов, `explain` и переопределения в подклассе |

`when` — **конъюнкция**: `when` класса и `when` правила должны выполняться оба. `fields`, `events` и `locales` —
дефолты, которые правило переопределяет; `null` — наследовать, `[]` — «без фильтра».

Строковый аксессор проверяется на truthy, что верно для булевых и неверно для строки статуса (`'draft'` — truthy).
Для строковых и enum-состояний используйте `Equals`; он же даёт точное определение старого состояния из change set ORM:

```php
use IndexNowKit\Attribute\Param\Equals;

#[IndexNow(route: 'post_show', params: ['slug' => 'slug'], when: new Equals('status', 'published'))]
#[IndexNow(route: 'job_show', params: ['id' => 'id'], when: new Equals('state', JobState::Open))]   // BackedEnum или его value
```

Правила, зарегистрированные во время выполнения (`RuleRegistry`), могут передать замыкание:
`when: fn (WP_Post $p): bool => $p->post_status === 'publish'`. Старое значение замыкания восстановить нельзя, поэтому
перечислите читаемые им поля в `whenFields`; изменение одного из них считается сменой видимости (см. таблицу семантики).

Неизвестное имя события — `ConfigurationException` с именем атрибута и значением.

### Имена правил

Выводятся из источника, если не заданы: имя маршрута; `resolver:<ShortClassName>`; `via:<accessor>`; `url:<accessor>`
(и `url:<method>` для `#[IndexNowUrl]`); `urls:<первые два литерала>`. Два правила одного класса с одинаковым выведенным
именем получают `#2`, `#3` в порядке объявления. Задавайте явное `name`, когда подкласс должен переопределить конкретное
правило или когда выведенное имя нестабильно.

## Дефолты класса и наследование

Компилятор обходит иерархию **от корня** к листу.

- `#[IndexNowDefaults]` сливается поле за полем, ближайшее объявление побеждает. Объявление со своим `when` заменяет и
  унаследованные `whenFields`; без `when` — добавляет к ним.
- Правила накапливаются. Правило, чьё имя повторяет предка, **заменяет** его; новое имя **добавляет** страницу. Так
  подкласс меняет одну страницу, не повторяя остальные.
- `#[IndexNowUrl]` читается на публичных методах, объявленных каждым классом цепочки, поэтому переопределение в
  подклассе побеждает. Метод не должен требовать аргументов.
- Интерфейсы и трейты **не** сканируются: PHP не наследует атрибуты класса через них, и Doctrine-маппинг ведёт себя так же.

```php
#[IndexNowDefaults(when: 'isPublished')]
#[IndexNow(route: 'content_show', params: ['slug' => 'slug'])]
abstract class Content {}

#[IndexNow(route: 'content_show', params: ['slug' => 'slug', 'section' => new Value('news')])]  // заменяет
#[IndexNow(route: 'news_amp', params: ['slug' => 'slug'])]                                      // добавляет
class News extends Content {}
```

В иерархии, где публичные страницы есть лишь у части подклассов, базовый класс не должен нести правил: что не
объявлено, то не наследуется.

## Семантика: событие, до, после

Видимость оценивается для каждого правила. `W` — конъюнкция `when` класса и `when` правила; `W_before`
восстанавливается из change set ORM.

| Событие ORM | `W_before` | `W_after` | `fields` совпали | Событие правила | Состояние, из которого строится URL |
|---|---|---|---|---|---|
| insert | — | true | — | `Created`, если подписано | новое, после записи (id назначены) |
| insert | — | false | — | нет | — |
| update | true | true | да | `Updated`, если подписано | новое, после записи |
| update | true | true | нет | нет | — |
| update | true | false | не важно | **`Deleted`**, если подписано | текущее, до записи |
| update | false | true | не важно | `Created`, если подписано | новое, после записи |
| update | false | false | — | нет | — |
| delete | — | true | — | `Deleted`, если подписано | состояние до удаления |
| delete | — | false | — | нет | — |
| цель `via`, любое событие | — | true | по правилу цели | цель разрешается как `Updated` | собственные правила и guard'ы цели |
| цель `via`, любое событие | — | false | — | нет | — |

Два следствия стоит проговорить. `fields` никогда не подавляет смену видимости, только обычное обновление: страница,
которая только что погасла, объявляется независимо от того, какое поле это сделало. И удаление объекта, чьё правило
не применяется, ничего не отправляет — страница никогда не была публичной, чистка черновиков молчит.

### Восстановление `W_before`

`ChangeClassifier::classify(UrlRule $rule, object $subject, array $changedFields, array $changeSet = [])` возвращает
`Event`, важное правилу, или `null`. Видимость старого состояния — best effort, в три яруса:

1. Аксессор `when`, чьё поле есть в change set, вычисляется **точно** из старого значения. Поле ищется по имени, затем по
   конвенции: `isPublished → published → is_published`, `hasAmp → amp → has_amp`, `getStatus → status`.
   `UrlRule::fieldCandidates()` отдаёт этот список.
2. Аксессор без записи в change set, но с зависимым полем (его кандидаты или объявленное `whenFields`) среди
   изменённых считается **перевернувшимся**. Ложное срабатывание стоит одного запроса; пропуск оставляет мёртвую
   страницу в индексе.
3. Иначе аксессор сохраняет текущее значение.

Называйте поля через `whenFields`, когда аксессор — метод, имя которого не связано с колонкой, например
`when: 'isVisibleToPublic', whenFields: ['status', 'visibleFrom']`.

## `via`

`via` переотправляет страницы связанного объекта: изменившийся комментарий обновляет свой пост, изменившийся товар —
категорию. Цели всегда разрешаются как `Updated`: их страница существует независимо от того, что случилось с
источником. Глубина ограничена 3, fan-out — 100 связанных объектов на правило (аргументы конструктора `maxViaDepth` и
`maxViaFanout` у `AttributeUrlResolver`); превышение глубины бросает исключение, превышение fan-out пишет warning и
останавливается. Правило цели, делегирующее обратно через тот же аксессор, пропускается, так что `A -> B -> A` завершается.

Полученные URL сохраняют всю цепочку в имени правила: `via:category -> category_show`.

## Имена полей

`fields` и `whenFields` — **имена полей модели**, как их пишет разработчик, никогда не колонки БД. Doctrine
`getEntityChangeSet()` даёт ровно их. Объявленное поле совпадает с изменённым, когда они равны или одно — префикс
другого через точку, так что `fields: ['address']` ловит изменение embeddable, отражённое как `address.city`.

## Типы, которые потребляют адаптеры

```php
final readonly class UrlRule
{
    public string $name;
    public RuleSource $source;                    // Route|Resolver|Via|Url|Urls
    public ?string $route; public array $params;
    public ?string $resolver; public ?string $via; public ?string $url; public array $urls;
    public array $when; public array $whenFields; public array $fields;
    public array $events; public array|string $locales;
    public string|ParamValue|null $host;

    public function listensTo(Event $event): bool;
    public function caresAbout(array $changedFields): bool;
    public function appliesTo(object $subject): bool;      // каждый аксессор `when` truthy
    public function whenDependsOn(string $field): bool;
    public static function fieldCandidates(string $accessor): array;
}
```

`RuleSet` — все правила одного класса в порядке объявления (сначала родители). Он `Countable` и итерируемый, пуст
для классов без правил (вызывающим не нужно проверять null), и даёт `isEmpty()`, `get(string $name)` и
`listensTo(Event $event)` как дешёвый предфильтр для ORM-хуков.

`AttributeReaderInterface::rules(string|object $classOrObject): RuleSet` — точка поиска. Дефолтный `AttributeReader`
компилирует атрибуты через `RuleCompiler` и кэширует по классу на время процесса. На испорченном объявлении он бросает
`ConfigurationException` — ORM-хуки должны читать через `ObjectChangeHandler` или `GuardedUrlResolver`, которые логируют.

`ResolvedUrl` несёт происхождение для `explain`, логов и панели профайлера: `url`, `rule`, `class`, `event`, `locale`,
плюс `source()` (`App\Entity\Post#post_amp`) и `ResolvedUrl::urls()` для сплющивания списка в уникальные строки.

## Правила, регистрируемые во время выполнения

Модели, которые не могут нести атрибуты — типы записей CMS, чужие классы, API на замыканиях — используют `RuleRegistry`,
реализующий `AttributeReaderInterface` поверх внутреннего ридера (по умолчанию атрибуты).

```php
use IndexNowKit\Attribute\{IndexNow, IndexNowDefaults, RuleRegistry};

$registry = new RuleRegistry();
$registry->register(Post::class, [
    new IndexNow(route: 'posts.show', params: ['post' => 'self']),
    new IndexNow(urls: ['/']),
], new IndexNowDefaults(when: 'isPublished'));

$registry->register(WP_Post::class, [new IndexNow(resolver: 'wp_permalink')], new IndexNowDefaults(
    when: fn (WP_Post $post): bool => $post->post_status === 'publish',   // или: new Equals('post_status', 'publish')
    whenFields: ['post_status'],
));

$registry->registerFor(CmsPage::class, fn (CmsPage $page): ?RuleSet => $page->isSystem() ? null : $rulesFor($page));

$indexNow = IndexNowKit::create($config, attributes: $registry);
```

`register()` компилирует экземпляры атрибутов, созданные в коде, без рефлексии. `registerFor()` решает по объекту и
может вернуть `null`, чтобы передать слово внутреннему ридеру. Зарегистрированные правила заменяют то, что вернул бы
внутренний ридер для этого класса, и наследуются подклассами.

## Разрешение без ORM

`AttributeUrlResolver` разрешает каждое правило класса через его источник; `GuardedUrlResolver` оборачивает его, чтобы
ничего не бросалось. `ObjectChangeHandler` — то, на чём строятся ORM-хуки: классифицирует созданный, изменённый или
удалённый объект по правилам и разрешает URL, логируя каждый молчаливый исход. См. [adapters.md](adapters.md).

## Антипаттерны

Пять объявлений, которые компилируются, работают и отправляют не то.

**1. Литеральный URL в `url:`.** `url:` называет аксессор; `urls:` перечисляет литералы.

```php
#[IndexNow(url: '/')]                     // неверно: читает свойство или метод с именем "/"
#[IndexNow(urls: ['/'])]                  // верно
#[IndexNow(url: 'canonicalUrl')]          // верно: $post->canonicalUrl() или ->canonicalUrl
```

**2. Строка статуса в `when:`.** Строка — аксессор, читаемый как truthy: `'published'` значит «атрибут `published`
truthy», а колонка `status` со значением `'draft'` тоже truthy.

```php
#[IndexNow(route: 'post_show', params: ['slug' => 'slug'], when: 'status')]                              // неверно: 'draft' — truthy
#[IndexNow(route: 'post_show', params: ['slug' => 'slug'], when: new Equals('status', 'published'))]     // верно
```

**3. Правило на странице, которую нельзя индексировать.** Превью, админка, страница с `noindex` или запрет в
`robots.txt`: поисковик её запросит, найдёт неиндексируемой и запишет ошибку в счёт ключа.

```php
#[IndexNow(route: 'post_preview', params: ['slug' => 'slug'])]   // неверно: превью несёт noindex
#[IndexNow(route: 'post_show', params: ['slug' => 'slug'], when: 'isPublished')]   // верно: публичная страница под guard'ом
```

**4. Неканонические URL.** Варианты фильтров и сортировок, трекинг-параметры, apex рядом с `www`, `http` рядом с
`https`: отправляйте каноническую страницу один раз. Генерируйте URL через роутер с `base_url` на каноническом origin;
не склеивайте их строками из запроса.

```php
#[IndexNow(urls: ['/products?sort=price&utm_source=indexnow'])]   // неверно: вариант и трекинг-параметр
#[IndexNow(route: 'products_index')]                               // верно: каноническая выдача
```

**5. Нет `when` у модели с черновиками.** Без guard'а отправляет каждое сохранение, черновики включительно, а снятая
страница объявляется обновлением, не удалением.

```php
#[IndexNow(route: 'post_show', params: ['slug' => 'slug'])]                        // неверно, если у Post есть черновики
#[IndexNowDefaults(when: 'isPublished')]                                           // верно: черновики пропускаются,
#[IndexNow(route: 'post_show', params: ['slug' => 'slug'])]                        // published → draft — удаление
```

Что библиотека проверяет за вас: URL абсолютный `http(s)`, без credentials, фрагмента и управляющих символов, и
принадлежит хосту, для которого у вас есть ключ (`strict_hosts`). Что проверить не может: `noindex`, `robots.txt`,
canonical на другой адрес, код ответа страницы — сегодня это забота автора правила, а в следующих релизах — дополнения
`verify` (`check --sample`).
