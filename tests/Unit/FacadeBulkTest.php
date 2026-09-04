<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Attribute\AttributeReader;
use IndexNowKit\Attribute\IndexNow as IndexNowAttribute;
use IndexNowKit\Attribute\RuleSource;
use IndexNowKit\Attribute\UrlRule;
use IndexNowKit\Event;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Testing\ArrayLogger;
use IndexNowKit\Testing\FakeTransport;
use IndexNowKit\Tests\Support\Factory;
use IndexNowKit\Url\AttributeUrlResolver;
use IndexNowKit\Url\GuardedUrlResolver;
use IndexNowKit\Url\ResolvedUrl;
use IndexNowKit\Url\RuleAwareUrlResolverInterface;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[IndexNowAttribute(url: 'url')]
#[IndexNowAttribute(urls: ['/category/news'])]
final class BulkPost
{
    public function __construct(public string $slug) {}

    public function url(): string
    {
        return '/posts/' . $this->slug;
    }
}

/**
 * A rule-aware resolver that is not the shipped AttributeUrlResolver: GuardedUrlResolver must treat it per rule.
 */
final class CustomRuleAwareResolver implements RuleAwareUrlResolverInterface
{
    public function resolve(object $subject, Event $event): iterable
    {
        return ResolvedUrl::urls($this->explain($subject, $event));
    }

    public function explain(object $subject, Event $event): array
    {
        return [new ResolvedUrl('/custom/' . $subject::class, 'custom', $subject::class, $event)];
    }

    public function resolveRule(object $subject, UrlRule $rule, Event $event, int $depth = 0, bool $ignoreWhen = false): array
    {
        if ($rule->name === 'boom') {
            throw new RuntimeException('rule failed');
        }

        return [new ResolvedUrl('/rule/' . $rule->name, 'custom', $subject::class, $event)];
    }
}

final class FacadeBulkTest extends TestCase
{
    #[TestDox('submitAll() and urlsForAll() resolve many objects and de-duplicate across the set: 100 posts of one category yield the category once')]
    public function testSubmitAll(): void
    {
        $transport = new FakeTransport();
        $kit = IndexNowKit::create(Factory::config(), $transport, resolver: new AttributeUrlResolver(new AttributeReader()));
        $posts = [new BulkPost('a'), new BulkPost('b'), new BulkPost('a')];

        self::assertSame(['/posts/a', '/category/news', '/posts/b'], $kit->urlsForAll($posts), 'as resolved, before normalization');

        $results = $kit->submitAll($posts);
        self::assertCount(1, $results, 'one request per host and batch');
        self::assertCount(1, $transport->posts);
        self::assertSame(['https://www.example.com/posts/a', 'https://www.example.com/category/news', 'https://www.example.com/posts/b'], $transport->posts[0]['body']['urlList']);
        self::assertSame([], $kit->submitAll([]), 'nothing to submit, no request');
    }

    #[TestDox('GuardedUrlResolver treats any RuleAwareUrlResolverInterface per rule: a failing rule loses its own URLs only')]
    public function testCustomRuleAwareResolver(): void
    {
        $logger = new ArrayLogger();
        $guarded = new GuardedUrlResolver(new CustomRuleAwareResolver(), new AttributeReader(), $logger);

        self::assertTrue($guarded->isRuleAware());
        self::assertSame(['/rule/url:url', '/rule/urls:/category/news'], $guarded->resolve(new BulkPost('x'), Event::Updated), 'per rule, named after the rule');

        $rules = (new AttributeReader())->rules(new BulkPost('x'));
        $rule = $rules->rules[0];
        self::assertSame(['/rule/url:url'], ResolvedUrl::urls($guarded->resolveRule(new BulkPost('x'), $rule, Event::Updated)));
        $broken = new UrlRule(name: 'boom', source: RuleSource::Urls, urls: ['/x']);
        self::assertSame([], $guarded->resolveRule(new BulkPost('x'), $broken, Event::Updated));
        self::assertStringContainsString('rule "boom"', implode("\n", $logger->messages('error')));
    }
}
