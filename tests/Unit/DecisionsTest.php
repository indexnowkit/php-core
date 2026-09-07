<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Adapter\ServicesBuilder;
use IndexNowKit\Attribute\IndexNow;
use IndexNowKit\Attribute\Param\Condition;
use IndexNowKit\Attribute\Param\Equals;
use IndexNowKit\Attribute\Param\FieldCondition;
use IndexNowKit\Attribute\ParamExtractor;
use IndexNowKit\Attribute\SubjectReaderInterface;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Testing\FakeTransport;
use IndexNowKit\Tests\Support\Factory;
use IndexNowKit\Url\AttributeUrlResolver;
use IndexNowKit\Url\NullUrlResolver;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

#[IndexNow(url: 'path', when: new Equals('status', 'live'))]
final class DecisionsPage
{
    public function __construct(public string $slug = 'one', public string $status = 'live') {}

    public function path(): string
    {
        return '/decisions/' . $this->slug;
    }
}

/** A reader that answers `status` with "live" for every object: the graph's extractor must be the one the resolver reads with. */
final class AlwaysLiveReader implements SubjectReaderInterface
{
    public function supports(object $subject): bool
    {
        return true;
    }

    public function has(object $subject, string $accessor): bool
    {
        return $accessor === 'status';
    }

    public function read(object $subject, string $accessor): mixed
    {
        return 'live';
    }
}

/** The decisions of the 0.10 audit (docs/plans/audit-0.10.md §6) that changed the API in core 0.12.0. */
final class DecisionsTest extends TestCase
{
    #[TestDox('A3: a FieldCondition is not a Condition — Equals has no evaluate(), the extractor evaluates it through its readers')]
    public function testFieldConditionIsNotACondition(): void
    {
        self::assertFalse((new ReflectionClass(FieldCondition::class))->implementsInterface(Condition::class));
        self::assertFalse((new ReflectionClass(Equals::class))->implementsInterface(Condition::class));
        self::assertFalse((new ReflectionClass(Equals::class))->hasMethod('evaluate'));

        $page = new DecisionsPage(status: 'draft');
        self::assertFalse(ParamExtractor::plain()->condition($page, new Equals('status', 'live')));
        self::assertTrue((new ParamExtractor(new AlwaysLiveReader()))->condition($page, new Equals('status', 'live')), 'read through the reader, not the property');
    }

    #[TestDox('A4: ParamExtractor::plain() is the DSL alone; the resolver, the classifier and the change handler take the extractor as a required parameter')]
    public function testPlainExtractorAndRequiredParameter(): void
    {
        self::assertSame([], ParamExtractor::plain()->readers());
        foreach ([[AttributeUrlResolver::class, '__construct'], [AttributeUrlResolver::class, 'fromConfig'], [\IndexNowKit\Url\ObjectChangeHandler::class, '__construct'], [\IndexNowKit\Attribute\ChangeClassifier::class, 'classify'], [\IndexNowKit\Attribute\UrlRule::class, 'appliesTo']] as [$class, $method]) {
            $parameter = null;
            foreach ((new ReflectionMethod($class, $method))->getParameters() as $candidate) {
                if ($candidate->getName() === 'extractor') {
                    $parameter = $candidate;
                }
            }
            self::assertNotNull($parameter, "$class::$method() has an \$extractor parameter");
            self::assertFalse($parameter->isOptional(), "$class::$method(): \$extractor is required");
            self::assertFalse($parameter->allowsNull(), "$class::$method(): \$extractor is not nullable");
        }
    }

    #[TestDox('A9: IndexNowKit::create() without a resolver resolves #[IndexNow] rules (a `url` accessor, literal `urls`) with the extractor given, like ServicesBuilder; NullUrlResolver stays the explicit way to resolve nothing')]
    public function testCreateDefaultsToTheAttributeResolver(): void
    {
        $config = Factory::config();
        $kit = IndexNowKit::create($config, new FakeTransport());
        self::assertSame(['/decisions/one'], $kit->urlsFor(new DecisionsPage()), 'as resolved, before normalization');
        self::assertSame([], $kit->urlsFor(new DecisionsPage(status: 'draft')), 'when is evaluated');

        $extractor = new ParamExtractor(new AlwaysLiveReader());
        $kit = IndexNowKit::create($config, new FakeTransport(), extractor: $extractor);
        self::assertSame($extractor, $kit->extractor);
        $inner = $kit->resolver()->inner();
        self::assertInstanceOf(AttributeUrlResolver::class, $inner);
        self::assertSame($extractor, $inner->extractor(), 'the default resolver reads with the extractor given to create()');
        self::assertSame(['/decisions/one'], $kit->urlsFor(new DecisionsPage(status: 'draft')), 'the reader says live');

        $services = (new ServicesBuilder($config))->transport(new FakeTransport())->build();
        self::assertSame($services->kit()->resolver()->inner()::class, IndexNowKit::create($config, new FakeTransport())->resolver()->inner()::class, 'create() and ServicesBuilder agree on the default resolver');

        self::assertSame([], IndexNowKit::create($config, new FakeTransport(), resolver: new NullUrlResolver())->urlsFor(new DecisionsPage()));
    }

    #[TestDox('A14: submitEntities() is the bulk method of the facade (submitX / submitXs like every adapter); submitAll() is its deprecated alias')]
    public function testSubmitEntities(): void
    {
        $transport = new FakeTransport();
        $kit = IndexNowKit::create(Factory::config(), $transport);
        self::assertCount(1, $kit->submitEntities([new DecisionsPage('a'), new DecisionsPage('b')]));
        self::assertSame(['https://www.example.com/decisions/a', 'https://www.example.com/decisions/b'], $transport->posts[0]['body']['urlList']);
        self::assertTrue((new ReflectionMethod(IndexNowKit::class, 'submitAll'))->getDocComment() !== false && str_contains((string) (new ReflectionMethod(IndexNowKit::class, 'submitAll'))->getDocComment(), '@deprecated'));
    }
}
