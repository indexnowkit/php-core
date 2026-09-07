<?php

declare(strict_types=1);

namespace IndexNowKit\Check;

use Closure;
use IndexNowKit\Attribute\AttributeReaderInterface;
use Throwable;

/**
 * The `router.locales` line of `check`: `#[IndexNow(locales: 'all')]` expands to the list of locales the adapter
 * configures (`router.locales` in Laravel and Yii2, `framework.enabled_locales` in Symfony), and an empty list quietly
 * collapses the rule to one URL in the current locale — no exception, no log line at boot, just the localized URLs
 * missing from every submission. With locales configured the line names them (when the adapter says which route
 * parameter carries the locale); with none it stays silent unless a class the adapter can see asks for `'all'`,
 * which is one warning naming the option. At run time `Url\RouteOrigin::expand()` warns once per process about the
 * same thing. Three adapters used to print this check with three texts.
 */
final class LocalesCheck implements CheckInterface
{
    public const CODE = 'router.locales';

    /** How many class names the warning lists before it says "and N more". */
    private const NAMED = 3;

    /**
     * @param list<string>                  $locales   the configured list
     * @param Closure(): list<class-string> $classes   whose rules to read: the mapped Doctrine classes, the `--sample-class`
     *                                                 values, `active_record.models` — read when the check runs, not when it is built
     * @param string                        $option    the option the texts name (`router.locales`, `framework.enabled_locales`)
     * @param string|null                   $parameter the route parameter the ok line names; null = no ok line when the list is filled
     */
    public function __construct(
        private readonly array $locales,
        private readonly AttributeReaderInterface $rules,
        private readonly Closure $classes,
        private readonly string $option = 'router.locales',
        private readonly ?string $parameter = null,
    ) {}

    public function check(CheckReport $report): void
    {
        if ($this->locales !== []) {
            if ($this->parameter !== null) {
                $report->ok(\sprintf('%s: %s — a rule with locales: \'all\' generates one URL per locale (route parameter "%s")', $this->option, implode(', ', $this->locales), $this->parameter), self::CODE);
            }

            return;
        }
        $asking = $this->classesAskingForEveryLocale();
        if ($asking === []) { // nothing asks for every locale: the empty list is simply not used
            return;
        }
        $report->warning(\sprintf(
            '%s is empty, but %s has a rule with locales: \'all\': one URL in the current locale is generated instead of one per locale. List the locales in %s, or name them in the rule (locales: [\'en\', \'de\']).',
            $this->option,
            self::names($asking),
            $this->option,
        ), self::CODE);
    }

    /**
     * @return list<class-string>
     */
    private function classesAskingForEveryLocale(): array
    {
        $asking = [];
        foreach (($this->classes)() as $class) {
            try {
                $rules = $this->rules->rules($class);
            } catch (Throwable) {
                continue; // an invalid #[IndexNow] is the business of the rule reader, not of this line
            }
            foreach ($rules as $rule) {
                if ($rule->locales === 'all') {
                    $asking[] = $class;

                    break;
                }
            }
        }

        return array_values(array_unique($asking));
    }

    /**
     * @param list<string> $classes
     */
    private static function names(array $classes): string
    {
        $named = \array_slice($classes, 0, self::NAMED);
        $rest = \count($classes) - \count($named);

        return implode(', ', $named) . ($rest > 0 ? \sprintf(' and %d more', $rest) : '');
    }
}
