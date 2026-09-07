<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Attribute\AttributeReaderInterface;
use IndexNowKit\Attribute\IndexNow;
use IndexNowKit\Attribute\RuleRegistry;
use IndexNowKit\Attribute\RuleSet;
use IndexNowKit\Check\CheckLevel;
use IndexNowKit\Check\CheckReport;
use IndexNowKit\Check\LocalesCheck;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

final class LocalesPost {}
final class LocalesPage {}
final class LocalesBroken {}
final class LocalesA {}
final class LocalesB {}
final class LocalesC {}
final class LocalesD {}
final class LocalesE {}

/** A reader whose rules() throws for one class: what an invalid #[IndexNow] does. */
final class ThrowingForOneClassReader implements AttributeReaderInterface
{
    public function __construct(private readonly AttributeReaderInterface $inner, private readonly string $broken) {}

    public function rules(string|object $classOrObject): RuleSet
    {
        if ($classOrObject === $this->broken) {
            throw new RuntimeException('invalid rule');
        }

        return $this->inner->rules($classOrObject);
    }
}

/**
 * The `router.locales` line every adapter prints from the core (spec 19 §4.4): the ok line with a filled list,
 * silence with a filled list and no parameter, the warning naming the option and the classes, and nothing at all
 * when no class asks for every locale.
 */
final class LocalesCheckTest extends TestCase
{
    #[TestDox('a filled list is one ok line naming the option, the locales and the route parameter; without a parameter nothing is printed')]
    public function testFilledList(): void
    {
        $rules = new RuleRegistry();
        $check = new LocalesCheck(['en', 'de'], $rules, static fn(): array => [], 'router.locales', 'locale');

        self::assertSame([CheckLevel::Ok], self::levels($check));
        self::assertSame('router.locales: en, de — a rule with locales: \'all\' generates one URL per locale (route parameter "locale")', self::messages($check)[0]);
        self::assertSame([], self::levels(new LocalesCheck(['en'], $rules, static fn(): array => [stdClass::class], 'framework.enabled_locales')), 'the bundle prints no ok line');
    }

    #[TestDox('an empty list with a class asking for every locale is one warning naming the option twice and the class; the classes are read when the check runs')]
    public function testEmptyListWithARuleAskingForAll(): void
    {
        $rules = new RuleRegistry();
        $post = LocalesPost::class;
        $page = LocalesPage::class;
        $rules->register($post, [new IndexNow(route: 'post', params: ['slug' => 'slug'], locales: 'all')]);
        $rules->register($page, [new IndexNow(route: 'page', params: ['slug' => 'slug'])]);
        /** @var list<class-string> $classes */
        $classes = [];
        $check = new LocalesCheck([], $rules, static function () use (&$classes): array {
            return $classes;
        }, 'framework.enabled_locales');

        self::assertSame([], self::levels($check), 'no class visible: nothing to say');
        $classes = [$page];
        self::assertSame([], self::levels($check), 'no rule asks for every locale: the empty list is not used');
        $classes = [$post, $page, $post];
        self::assertSame([CheckLevel::Warning], self::levels($check));
        self::assertSame(
            'framework.enabled_locales is empty, but ' . LocalesPost::class . ' has a rule with locales: \'all\': one URL in the current locale is generated instead of one per locale. List the locales in framework.enabled_locales, or name them in the rule (locales: [\'en\', \'de\']).',
            self::messages($check)[0],
        );
        self::assertSame(LocalesCheck::CODE, self::codes($check)[0]);
    }

    #[TestDox('the warning names three classes and counts the rest; a class whose rules cannot be read is skipped, not fatal')]
    public function testNamesAndBrokenClass(): void
    {
        $rules = new RuleRegistry();
        $classes = [LocalesBroken::class, LocalesA::class, LocalesB::class, LocalesC::class, LocalesD::class, LocalesE::class];
        foreach (\array_slice($classes, 1) as $class) {
            $rules->register($class, [new IndexNow(route: 'x', locales: 'all')]);
        }
        $check = new LocalesCheck([], new ThrowingForOneClassReader($rules, LocalesBroken::class), static fn(): array => $classes);

        self::assertSame([CheckLevel::Warning], self::levels($check));
        self::assertStringContainsString(\sprintf('router.locales is empty, but %s, %s, %s and 2 more has a rule', LocalesA::class, LocalesB::class, LocalesC::class), self::messages($check)[0]);
    }

    /**
     * @return list<CheckLevel>
     */
    private static function levels(LocalesCheck $check): array
    {
        $report = new CheckReport();
        $check->check($report);

        return array_map(static fn($item): CheckLevel => $item->level, $report->items());
    }

    /**
     * @return list<string>
     */
    private static function messages(LocalesCheck $check): array
    {
        $report = new CheckReport();
        $check->check($report);

        return array_map(static fn($item): string => $item->message, $report->items());
    }

    /**
     * @return list<string|null>
     */
    private static function codes(LocalesCheck $check): array
    {
        $report = new CheckReport();
        $check->check($report);

        return array_map(static fn($item): ?string => $item->code, $report->items());
    }
}
