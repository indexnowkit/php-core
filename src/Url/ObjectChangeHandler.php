<?php

declare(strict_types=1);

namespace IndexNowKit\Url;

use Closure;
use IndexNowKit\Attribute\AttributeReaderInterface;
use IndexNowKit\Attribute\ChangeClassifier;
use IndexNowKit\Attribute\Param\Accessor;
use IndexNowKit\Attribute\Param\Call;
use IndexNowKit\Attribute\Param\Equals;
use IndexNowKit\Attribute\Param\Formatted;
use IndexNowKit\Attribute\Param\ParamValue;
use IndexNowKit\Attribute\RuleEvent;
use IndexNowKit\Attribute\RuleSet;
use IndexNowKit\Attribute\RuleSource;
use IndexNowKit\Attribute\UrlRule;
use IndexNowKit\Event;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionProperty;
use Throwable;

/**
 * The ORM-hook building block shared by every adapter: rule lookup + event classification + guarded
 * resolution. Never throws: an invalid rule set or a failing resolver is logged and yields nothing.
 *
 * Two levels: hooks that run before ids exist (Doctrine onFlush) collect {@see RuleEvent}s with the
 * `*Events()` methods and resolve them later with {@see resolve()}; hooks that run after the write
 * (Eloquent observers, CMS save hooks) call {@see created()} / {@see updated()} / {@see deleted()} directly.
 * Deletions must be resolved while the object still has its identifiers and old state.
 */
final class ObjectChangeHandler
{
    public function __construct(
        private readonly AttributeReaderInterface $rules,
        private readonly GuardedUrlResolver $resolver,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Rules to resolve for a newly persisted object.
     *
     * @return list<RuleEvent>
     */
    public function createdEvents(object $subject): array
    {
        return $this->eventsFor($subject, Event::Created);
    }

    /**
     * Rules to resolve for an object about to be removed (`when` false = the page was never public, skipped).
     *
     * @return list<RuleEvent>
     */
    public function deletedEvents(object $subject): array
    {
        return $this->eventsFor($subject, Event::Deleted);
    }

    /**
     * Rules to resolve for an update, classified per rule: a rule that stopped applying yields Deleted (resolve
     * it now, while the old state is live), one that started applying yields Created, otherwise Updated when
     * the changed fields match the rule's `fields`.
     *
     * @param list<string>                             $changedFields
     * @param array<string, array{0: mixed, 1: mixed}> $changeSet     field => [old, new] when the ORM has it
     *
     * @return list<RuleEvent>
     */
    public function updatedEvents(object $subject, array $changedFields, array $changeSet = []): array
    {
        $out = [];
        foreach ($this->rulesOf($subject) as $rule) {
            try {
                $event = ChangeClassifier::classify($rule, $subject, $changedFields, $changeSet);
            } catch (Throwable $e) {
                $this->logger->error('indexnow: cannot classify the change of {class} for rule "{rule}": {error}', ['class' => $subject::class, 'rule' => $rule->name, 'error' => $e->getMessage(), 'exception' => $e]);
                continue;
            }
            if ($event === null) {
                $this->logger->debug('indexnow: {class} rule "{rule}" ignores this update (fields {changed} vs filter {fields}, or `when` unchanged and false)', ['class' => $subject::class, 'rule' => $rule->name, 'changed' => $changedFields, 'fields' => $rule->fields]);
                continue;
            }
            $out[] = new RuleEvent($rule, $event);
        }

        return $out;
    }

    /**
     * URLs the object had before this update and does not have any more: a route rule whose parameters read a
     * changed field (a slug, a category) yields its old URLs as Deleted, resolved from the previous values in
     * $changeSet, so the engine drops the page that now answers 404. Only route rules and only fields the object
     * can be reset to (readonly properties are skipped with a debug line); the object is restored afterwards.
     *
     * @param array<string, array{0: mixed, 1: mixed}> $changeSet field => [old, new]
     *
     * @return list<ResolvedUrl>
     */
    public function renamed(object $subject, array $changeSet): array
    {
        if ($changeSet === []) {
            return [];
        }
        $changed = array_keys($changeSet);
        $rules = [];
        $dependent = [];
        foreach ($this->rulesOf($subject) as $rule) {
            if ($rule->source !== RuleSource::Route || !$rule->listensTo(Event::Deleted)) {
                continue;
            }
            $fields = self::routeFields($rule, $changed);
            if ($fields !== []) {
                $rules[] = $rule;
                $dependent = [...$dependent, ...$fields];
            }
        }
        if ($rules === []) {
            return [];
        }
        try {
            return $this->previousUrls($subject, $changeSet, $rules, array_values(array_unique($dependent)));
        } catch (Throwable $e) {
            // Never into the ORM's flush: the page keeps its new URL, the old one is not announced as deleted.
            $this->logger->error('indexnow: cannot resolve the previous URLs of {class}: {error}', ['class' => $subject::class, 'error' => $e->getMessage(), 'exception' => $e]);

            return [];
        }
    }

    /**
     * @param array<string, array{0: mixed, 1: mixed}> $changeSet
     * @param list<UrlRule>                            $rules
     * @param list<string>                             $dependent
     *
     * @return list<ResolvedUrl>
     */
    private function previousUrls(object $subject, array $changeSet, array $rules, array $dependent): array
    {
        $restore = self::apply($subject, array_map(static fn(array $pair): mixed => $pair[0], $changeSet), $dependent);
        if ($restore === null) {
            $this->logger->debug('indexnow: cannot rebuild the previous state of {class} (a field the URL depends on is readonly, uninitialized or not a property), old URLs are not announced as deleted', ['class' => $subject::class]);

            return [];
        }
        try {
            $old = [];
            foreach ($rules as $rule) {
                $old = [...$old, ...$this->resolver->resolveRule($subject, $rule, Event::Deleted, false)];
            }
        } finally {
            $restore();
        }
        if ($old === []) {
            return [];
        }
        $current = [];
        foreach ($rules as $rule) {
            foreach ($this->resolver->resolveRule($subject, $rule, Event::Updated, true) as $item) {
                $current[$item->url] = true;
            }
        }

        return array_values(array_filter($old, static fn(ResolvedUrl $item): bool => !isset($current[$item->url])));
    }

    /**
     * Changed fields the rule's route parameters (or host) read, directly or as the root of a dotted path.
     *
     * @param list<string> $changed
     *
     * @return list<string>
     */
    private static function routeFields(UrlRule $rule, array $changed): array
    {
        $fields = [];
        $sources = array_values($rule->params);
        if ($rule->host !== null) {
            $sources[] = $rule->host;
        }
        foreach ($sources as $source) {
            $path = match (true) {
                \is_string($source) => $source,
                $source instanceof Accessor, $source instanceof Formatted, $source instanceof Equals => $source->path,
                $source instanceof Call => $source->method,
                $source instanceof ParamValue => null,
            };
            if ($path === null) {
                continue;
            }
            $root = explode('.', $path, 2)[0];
            $fields = [...$fields, ...array_values(array_intersect(UrlRule::fieldCandidates($root), $changed))];
        }

        return array_values(array_unique($fields));
    }

    /**
     * Sets $values on the object and returns the closure that restores the current values. Fields that are not
     * writable properties (missing, readonly, static, uninitialized) are skipped, unless the
     * URL depends on them ($required): then null, nothing is touched. A setter that throws restores the fields
     * already changed before rethrowing.
     *
     * @param array<string, mixed> $values   field => previous value
     * @param list<string>         $required fields that must be applied for the old URL to be right
     *
     * @return (Closure(): void)|null
     */
    private static function apply(object $subject, array $values, array $required): ?Closure
    {
        $properties = [];
        foreach (array_keys($values) as $field) {
            $property = self::property($subject::class, $field);
            if ($property === null || !self::isRestorable($property, $subject)) {
                if (\in_array($field, $required, true)) {
                    return null;
                }
                continue;
            }
            $properties[$field] = $property;
        }
        $current = [];
        try {
            foreach ($properties as $field => $property) {
                $value = $property->getValue($subject);
                $property->setValue($subject, $values[$field]);
                $current[$field] = $value;
            }
        } catch (Throwable $e) {
            self::restore($subject, $properties, $current);

            throw $e;
        }

        return static fn() => self::restore($subject, $properties, $current);
    }

    /**
     * @param array<string, ReflectionProperty> $properties
     * @param array<string, mixed>              $values
     */
    private static function restore(object $subject, array $properties, array $values): void
    {
        foreach ($values as $field => $value) {
            $properties[$field]->setValue($subject, $value);
        }
    }

    /**
     * Readable now and writable back to the same value later: initialized, not readonly, not static. A PHP 8.4
     * property with only a get hook passes here and fails in setValue(), which apply() turns into a restore.
     */
    private static function isRestorable(ReflectionProperty $property, object $subject): bool
    {
        return !$property->isReadOnly() && !$property->isStatic() && $property->isInitialized($subject);
    }

    /**
     * @param class-string $class
     */
    private static function property(string $class, string $field): ?ReflectionProperty
    {
        for ($reflection = new ReflectionClass($class); $reflection !== false; $reflection = $reflection->getParentClass()) {
            if ($reflection->hasProperty($field)) {
                return $reflection->getProperty($field);
            }
        }

        return null;
    }

    /**
     * @return list<ResolvedUrl>
     */
    public function resolve(object $subject, RuleEvent $ruleEvent): array
    {
        // A classified deletion is resolved from whatever state the object has now (an unpublish left `when` false).
        return $this->resolver->resolveRule($subject, $ruleEvent->rule, $ruleEvent->event, $ruleEvent->event === Event::Deleted);
    }

    /**
     * @return list<ResolvedUrl>
     */
    public function created(object $subject): array
    {
        return $this->resolveAll($subject, $this->createdEvents($subject));
    }

    /**
     * @param list<string>                             $changedFields
     * @param array<string, array{0: mixed, 1: mixed}> $changeSet
     *
     * @return list<ResolvedUrl>
     */
    public function updated(object $subject, array $changedFields, array $changeSet = []): array
    {
        return $this->resolveAll($subject, $this->updatedEvents($subject, $changedFields, $changeSet));
    }

    /**
     * @return list<ResolvedUrl>
     */
    public function deleted(object $subject): array
    {
        return $this->resolveAll($subject, $this->deletedEvents($subject));
    }

    /**
     * Rules of the object, or an empty set (logged) when the declaration is invalid.
     */
    public function rulesOf(object $subject): RuleSet
    {
        try {
            return $this->rules->rules($subject);
        } catch (Throwable $e) {
            $this->logger->error('indexnow: invalid #[IndexNow] on {class}: {error}', ['class' => $subject::class, 'error' => $e->getMessage(), 'exception' => $e]);

            return new RuleSet($subject::class);
        }
    }

    /**
     * @return list<RuleEvent>
     */
    private function eventsFor(object $subject, Event $event): array
    {
        $out = [];
        foreach ($this->rulesOf($subject) as $rule) {
            if (!$rule->listensTo($event)) {
                continue;
            }
            try {
                if (!$rule->appliesTo($subject)) {
                    $this->logger->debug('indexnow: {class} rule "{rule}" skipped for {event}: `when` is false', ['class' => $subject::class, 'rule' => $rule->name, 'event' => $event->value]);
                    continue;
                }
            } catch (Throwable $e) {
                $this->logger->error('indexnow: cannot evaluate `when` of {class} rule "{rule}": {error}', ['class' => $subject::class, 'rule' => $rule->name, 'error' => $e->getMessage(), 'exception' => $e]);
                continue;
            }
            $out[] = new RuleEvent($rule, $event);
        }

        return $out;
    }

    /**
     * @param list<RuleEvent> $events
     *
     * @return list<ResolvedUrl>
     */
    private function resolveAll(object $subject, array $events): array
    {
        $out = [];
        foreach ($this->distinct($events) as $ruleEvent) {
            $out = [...$out, ...$this->resolve($subject, $ruleEvent)];
        }

        return $out;
    }

    /**
     * With a custom (whole-object) resolver every rule of an object would trigger the same call: keep one per event.
     *
     * @param list<RuleEvent> $events
     *
     * @return list<RuleEvent>
     */
    public function distinct(array $events): array
    {
        if ($this->resolver->isRuleAware()) {
            return $events;
        }
        $seen = [];
        $out = [];
        foreach ($events as $ruleEvent) {
            if (!isset($seen[$ruleEvent->event->value])) {
                $seen[$ruleEvent->event->value] = true;
                $out[] = $ruleEvent;
            }
        }

        return $out;
    }
}
