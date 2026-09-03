<?php

declare(strict_types=1);

namespace IndexNowKit\Attribute;

use Closure;
use IndexNowKit\Attribute\Param\Accessor;
use IndexNowKit\Attribute\Param\Equals;
use IndexNowKit\Attribute\Param\ParamValue;
use IndexNowKit\Event;
use IndexNowKit\Exception\ConfigurationException;

/**
 * One fully resolved URL rule: class defaults merged in, inheritance applied, nothing left to inherit.
 * The language-neutral model of docs/spec/02: attributes (PHP), decorators (Python) and config objects (JS)
 * all compile down to it, and everything downstream (classifier, guards, resolver, explain) consumes it.
 */
final readonly class UrlRule
{
    /** @var array<int, list<string>> */
    public array $whenFields;

    /**
     * @param array<string, string|ParamValue> $params
     * @param list<string>                     $urls
     * @param list<string|ParamValue|Closure>  $when       conjunction: every condition must hold (accessor truthy, Equals, closure)
     * @param array<int, list<string>>|list<string> $whenFields fields backing each $when condition, keyed by its index; a flat list applies to every condition
     * @param list<string>                     $fields     changed-field filter for updates; [] = any
     * @param list<Event>                      $events
     * @param list<string>|string              $locales
     */
    public function __construct(
        public string $name,
        public RuleSource $source,
        public ?string $route = null,
        public array $params = [],
        public ?string $resolver = null,
        public ?string $via = null,
        public ?string $url = null,
        public array $urls = [],
        public array $when = [],
        array $whenFields = [],
        public array $fields = [],
        public array $events = [Event::Created, Event::Updated, Event::Deleted],
        public array|string $locales = 'current',
        public string|ParamValue|null $host = null,
    ) {
        $flat = $whenFields !== [] && array_is_list($whenFields) && \is_string($whenFields[0]);
        /** @var array<int, list<string>> $keyed */
        $keyed = $flat ? array_fill(0, max(1, \count($when)), array_values(array_filter($whenFields, 'is_string'))) : $whenFields;
        $this->whenFields = $keyed;
    }

    public function listensTo(Event $event): bool
    {
        return \in_array($event, $this->events, true);
    }

    /**
     * A declared field matches an exact change or a nested change beneath it (an embeddable change set key
     * `address.city` is caught by `fields: ['address']`).
     *
     * @param list<string> $changedFields
     */
    public function caresAbout(array $changedFields): bool
    {
        if ($this->fields === []) {
            return true;
        }
        foreach ($this->fields as $declared) {
            foreach ($changedFields as $changed) {
                if ($changed === $declared || str_starts_with($changed, $declared . '.') || str_starts_with($declared, $changed . '.')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether the page exists in the object's current state: every `when` condition holds.
     *
     * @throws ConfigurationException when an accessor cannot be read
     */
    public function appliesTo(object $subject): bool
    {
        foreach ($this->when as $condition) {
            if (!ParamExtractor::condition($subject, $condition)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a change of this field may change the outcome of `when`: the accessor itself, a declared
     * whenField, or the field a getter conventionally reads (`isPublished` -> `published`, `getStatus` -> `status`).
     */
    public function whenDependsOn(string $field): bool
    {
        foreach (array_keys($this->when) as $index) {
            if ($this->conditionDependsOn($index, $field)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a change of this field may change the outcome of one `when` condition: a declared whenField, or the
     * field the condition's accessor conventionally reads. Closures depend on whenFields only.
     */
    public function conditionDependsOn(int $index, string $field): bool
    {
        if (\in_array($field, $this->whenFields[$index] ?? [], true)) {
            return true;
        }
        $accessor = self::accessorOf($this->when[$index] ?? '');

        return $accessor !== null && $accessor !== '' && \in_array($field, self::fieldCandidates($accessor), true);
    }

    /**
     * The accessor a condition reads, when it is statically known (string or Equals); null for closures/other sources.
     */
    public static function accessorOf(string|ParamValue|Closure $condition): ?string
    {
        return match (true) {
            \is_string($condition) => $condition,
            $condition instanceof Equals => $condition->path,
            $condition instanceof Accessor => $condition->path,
            default => null,
        };
    }

    /**
     * Field names that may back an accessor, most specific first: "isPublished" -> [isPublished, published, is_published].
     *
     * @return list<string>
     */
    public static function fieldCandidates(string $accessor): array
    {
        $candidates = [$accessor];
        if (preg_match('/^(?:is|has|get)([A-Z].*)$/', $accessor, $m) === 1) {
            $bare = lcfirst($m[1]);
            $candidates[] = $bare;
            $candidates[] = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $m[1]));
            if (str_starts_with($accessor, 'is')) {
                $candidates[] = 'is_' . $candidates[2];
            } elseif (str_starts_with($accessor, 'has')) {
                $candidates[] = 'has_' . $candidates[2];
            }
        }

        return array_values(array_unique($candidates));
    }
}
