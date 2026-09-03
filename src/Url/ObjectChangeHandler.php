<?php

declare(strict_types=1);

namespace IndexNowKit\Url;

use IndexNowKit\Attribute\AttributeReaderInterface;
use IndexNowKit\Attribute\ChangeClassifier;
use IndexNowKit\Attribute\RuleEvent;
use IndexNowKit\Attribute\RuleSet;
use IndexNowKit\Event;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
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
     * @return list<ResolvedUrl>
     */
    public function resolve(object $subject, RuleEvent $ruleEvent): array
    {
        return $this->resolver->resolveRule($subject, $ruleEvent->rule, $ruleEvent->event);
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
