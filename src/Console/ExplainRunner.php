<?php

declare(strict_types=1);

namespace IndexNowKit\Console;

use BackedEnum;
use Closure;
use IndexNowKit\Attribute\Param\Equals;
use IndexNowKit\Attribute\UrlRule;
use IndexNowKit\Config;
use IndexNowKit\Debounce\DebounceStoreInterface;
use IndexNowKit\Event;
use IndexNowKit\Exception\InvalidArgumentException;
use IndexNowKit\Exception\InvalidUrlException;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Key\KeyProviderInterface;
use IndexNowKit\Key\KeyValidator;
use IndexNowKit\Url\UrlNormalizerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Body of `indexnow:explain <class> <id>`: "why was this object not submitted?" Walks the decision path of one
 * object: rules -> event subscription -> `when` guard -> resolved URLs -> normalization -> host/key -> debounce.
 * Sends nothing.
 */
final class ExplainRunner
{
    public function __construct(
        private readonly IndexNowKit $indexNow,
        private readonly SubjectLoaderInterface $subjects,
        private readonly Config $config,
        private readonly KeyProviderInterface $keys,
        private readonly DebounceStoreInterface $debounce,
        private readonly UrlNormalizerInterface $normalizer,
        private readonly Vocabulary $words = new Vocabulary(),
    ) {}

    /**
     * @param string $class class argument as typed (FQCN or short name)
     * @param string $event created | updated | deleted
     *
     * @return int exit code ({@see ExitCode})
     */
    public function run(SymfonyStyle $io, string $class, string $id, string $event = 'updated'): int
    {
        try {
            $class = $this->subjects->resolveClass($class);
        } catch (InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return ExitCode::INVALID;
        }
        $eventValue = Event::tryFrom($event);
        if ($eventValue === null) {
            $io->error('--event must be created, updated or deleted.');

            return ExitCode::INVALID;
        }
        [$found] = $this->subjects->byIds($class, [$id], $eventValue);
        if ($found === []) {
            $io->error(\sprintf('%s with id "%s" not found.', $class, $id));

            return ExitCode::INVALID;
        }
        $object = $found[0];

        $io->title(\sprintf('IndexNow explain: %s #%s (%s)', $class, $id, $eventValue->value));
        $io->definitionList(
            ['enabled' => $this->config->enabled ? 'yes' : 'NO (enabled: false): nothing is sent'],
            ['dry_run' => $this->config->dryRun ? 'yes: requests are logged, not sent' : 'no'],
            ['dispatch' => $this->config->dispatch],
            ['debounce' => $this->config->debouncePerUrl . 's'],
        );

        $rules = $this->indexNow->changes()->rulesOf($object);
        if ($rules->isEmpty()) {
            $io->writeln('  <fg=red>✘</> no #[IndexNow] rule on ' . $class . ' (or the attribute is invalid: see the log)');

            return ExitCode::FAILURE;
        }
        $urls = [];
        foreach ($rules as $rule) {
            $urls = [...$urls, ...$this->explainRule($io, $object, $rule, $eventValue)];
        }
        if ($urls === []) {
            $io->newLine();
            $io->warning('No URL would be submitted for this event.');

            return ExitCode::SUCCESS;
        }
        $io->section('Delivery');
        foreach (array_unique($urls) as $url) {
            $this->explainUrl($io, $url);
        }
        $io->newLine();
        // A plain line, not a note block: the hint is a command to copy, wrapping would break it.
        $io->text(\sprintf('<comment>Nothing was sent.</comment> Submit with: %s %s %s %s', $this->words->cli, $this->words->submitSubjects, $class, $id));

        return ExitCode::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function explainRule(SymfonyStyle $io, object $object, UrlRule $rule, Event $event): array
    {
        $io->section(\sprintf('Rule "%s" (%s%s)', $rule->name, $rule->source->value, $rule->route !== null ? ' ' . $rule->route : ''));
        $io->writeln(\sprintf('  events: %s -> %s', implode(', ', array_map(static fn(Event $e): string => $e->value, $rule->events)), $rule->listensTo($event) ? '<fg=green>subscribed</>' : '<fg=yellow>not subscribed to ' . $event->value . '</>'));
        if ($rule->when !== []) {
            $conditions = implode(' && ', array_map(self::describeCondition(...), $rule->when));
            try {
                $applies = $rule->appliesTo($object);
                $io->writeln(\sprintf('  when: %s -> %s', $conditions, $applies ? '<fg=green>true</>' : '<fg=yellow>false (page not public, nothing submitted)</>'));
            } catch (Throwable $e) {
                $io->writeln(\sprintf('  when: %s -> <fg=red>error: %s</>', $conditions, $e->getMessage()));

                return [];
            }
        }
        if ($rule->fields !== []) {
            $io->writeln(\sprintf('  fields: updates count only when one of [%s] changed', implode(', ', $rule->fields)));
        }
        $resolved = $this->indexNow->resolver()->resolveRule($object, $rule, $event);
        if ($resolved === []) {
            $io->writeln('  urls: <fg=yellow>none</> (see above, or the indexnow log channel for resolver errors)');

            return [];
        }
        $urls = [];
        foreach ($resolved as $item) {
            $io->writeln(\sprintf('  url: <fg=green>%s</>%s%s', $item->url, $item->locale !== null ? ' [' . $item->locale . ']' : '', $item->rule !== $rule->name ? ' via ' . $item->rule : ''));
            $urls[] = $item->url;
        }

        return $urls;
    }

    private static function describeCondition(mixed $condition): string
    {
        return match (true) {
            \is_string($condition) => $condition,
            $condition instanceof Equals => \sprintf('%s == %s', $condition->path, json_encode($condition->value instanceof BackedEnum ? $condition->value->value : $condition->value)),
            $condition instanceof Closure => 'closure',
            default => get_debug_type($condition),
        };
    }

    private function explainUrl(SymfonyStyle $io, string $url): void
    {
        try {
            $normalized = $this->normalizer->normalize($url);
        } catch (InvalidUrlException $e) {
            $io->writeln(\sprintf('  %s -> <fg=red>dropped: %s</>', $url, $e->getMessage()));

            return;
        }
        $host = $this->normalizer->hostOf($normalized);
        $key = $this->keys->keyFor($host);
        $line = '  ' . $normalized;
        if ($normalized !== $url) {
            $line .= ' (normalized from ' . $url . ')';
        }
        if ($key === null) {
            $io->writeln($line . \sprintf(' -> <fg=red>skipped: no key for host %s</> (add it to "hosts" or set base_url)', $host));

            return;
        }
        $keyFile = $this->keys->keyLocationFor($host) ?? \sprintf('https://%s/%s.txt', $host, $key);
        $line .= \sprintf(' -> host %s, key %s (%s)', $host, KeyValidator::mask($key), str_replace($key, KeyValidator::mask($key), $keyFile));
        if ($this->config->debouncePerUrl > 0) {
            try {
                $recent = $this->debounce->filterRecent([$normalized], $this->config->debouncePerUrl) !== [];
                $line .= $recent ? \sprintf(', <fg=yellow>debounced</> (sent within the last %ds; %s --force bypasses)', $this->config->debouncePerUrl, $this->words->submit) : ', not debounced';
            } catch (Throwable $e) {
                $line .= ', debounce store unavailable (' . $e->getMessage() . '), would submit';
            }
        }
        $io->writeln($line);
    }
}
