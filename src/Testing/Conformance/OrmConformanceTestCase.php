<?php

declare(strict_types=1);

namespace IndexNowKit\Testing\Conformance;

use IndexNowKit\Http\Response;
use IndexNowKit\Testing\ArrayLogger;
use IndexNowKit\Testing\FakeTransport;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * ORM conformance scenarios of docs/spec/03 (A01-A21, plus A05b/A05c) that every ORM adapter runs against its own
 * hooks. The adapter implements the small driver below: fixtures with fixed rules, the transaction verbs of its data
 * layer, and the end of a unit of work. The scenarios only look at the FakeTransport and the ArrayLogger.
 *
 * Fixtures the driver must provide (rule shapes are fixed, mapping and router are the adapter's):
 *
 *   post          route page at {@see postUrl()} ; `when` = published ; `fields` = [slug, title, published] ; has `views`
 *   multi post    #[IndexNowDefaults(when: isPublished())] + page at postUrl() + AMP page at {@see ampUrl()} with
 *                 `when: hasAmp()` + literal {@see homeUrl()} ; `isPublished()` / `hasAmp()` are methods over
 *                 `published` / `amp` fields (exercises the getter -> field convention)
 *   categorized   page at postUrl() + `via: category` ; a to-many `tags` collection ; has `views`
 *   category      page at {@see categoryUrl()}
 *   untracked     no rule
 *   broken        route rule whose parameter reads a field that does not exist
 *   bad attribute #[IndexNow] with neither route nor resolver (invalid declaration)
 *
 * Every scenario ends its unit of work with {@see flush()}; nothing may reach the transport before that.
 */
abstract class OrmConformanceTestCase extends TestCase
{
    // -- the adapter under test -----------------------------------------------------------------------------------

    abstract protected function transport(): FakeTransport;

    abstract protected function logger(): ArrayLogger;

    /** End of the unit of work: what the adapter does when a request or command terminates (usually IndexNowKit::flush()). */
    abstract protected function flush(): void;

    /** URLs waiting in the collector, not yet flushed. */
    abstract protected function collectedCount(): int;

    // -- transactions of the data layer ---------------------------------------------------------------------------

    /** Opens a (possibly nested: savepoint) transaction. */
    abstract protected function begin(): void;

    abstract protected function commit(): void;

    /** Rolls back the innermost open transaction (to its savepoint when nested). */
    abstract protected function rollback(): void;

    // -- fixtures -------------------------------------------------------------------------------------------------

    abstract protected function createPost(string $slug, bool $published = true): object;

    abstract protected function createMultiPost(string $slug, bool $published, bool $amp): object;

    abstract protected function createCategory(string $slug): object;

    abstract protected function createCategorizedPost(string $slug, ?object $category = null): object;

    abstract protected function createTag(string $name): object;

    abstract protected function createUntracked(): object;

    abstract protected function createBroken(): object;

    abstract protected function createBadAttribute(): object;

    /**
     * Sets fields (`slug`, `title`, `published`, `views`, `amp`) and persists the change.
     *
     * @param array<string, mixed> $fields
     */
    abstract protected function update(object $model, array $fields): void;

    abstract protected function delete(object $model): void;

    /** Adds the tag to the post's to-many collection and persists it. */
    abstract protected function attachTag(object $post, object $tag): void;

    /** A bulk statement (UPDATE ... SET title = $title) that bypasses the unit of work (A13). */
    abstract protected function bulkUpdateTitle(string $title): void;

    // -- URL conventions (override when the router differs) -------------------------------------------------------

    protected function postUrl(string $slug): string
    {
        return 'https://www.example.com/posts/' . $slug;
    }

    protected function ampUrl(string $slug): string
    {
        return 'https://www.example.com/amp/' . $slug;
    }

    protected function categoryUrl(string $slug): string
    {
        return 'https://www.example.com/categories/' . $slug;
    }

    protected function homeUrl(): string
    {
        return 'https://www.example.com/';
    }

    // -- scenarios ------------------------------------------------------------------------------------------------

    #[TestDox('A01 create outside a transaction -> one POST with the page URL after flush')]
    public function testA01Create(): void
    {
        $this->createPost('hello');
        $this->flush();

        self::assertSame([$this->postUrl('hello')], $this->sentUrls());
    }

    #[TestDox('A02 create inside a transaction that rolls back -> no POST; a later create still works')]
    public function testA02Rollback(): void
    {
        $this->begin();
        $this->createPost('rolled');
        $this->flush();
        self::assertSame([], $this->sentUrls(), 'nothing sent before COMMIT');
        $this->rollback();
        $this->flush();
        self::assertSame([], $this->sentUrls());

        $this->createPost('after');
        $this->flush();
        self::assertSame([$this->postUrl('after')], $this->sentUrls(), 'the rolled-back URLs were discarded, later work is unaffected');
    }

    #[TestDox('A03 update a tracked field -> POST with the same URL')]
    public function testA03Update(): void
    {
        $post = $this->createPost('upd');
        $this->flush();
        $this->update($post, ['title' => 'changed']);
        $this->flush();

        self::assertSame([$this->postUrl('upd'), $this->postUrl('upd')], $this->sentUrls());
    }

    #[TestDox('A04 delete -> POST with the URL resolved before the row disappeared')]
    public function testA04Delete(): void
    {
        $post = $this->createPost('gone');
        $this->flush();
        $this->delete($post);
        $this->flush();

        self::assertSame([$this->postUrl('gone'), $this->postUrl('gone')], $this->sentUrls());
    }

    #[TestDox('A05 nested transaction, inner commit, outer rollback -> no POST')]
    public function testA05NestedRollback(): void
    {
        $this->begin();
        $this->begin();
        $this->createPost('inner');
        $this->commit();
        $this->flush();
        self::assertSame([], $this->sentUrls(), 'the inner commit is a savepoint release, not a COMMIT');
        $this->rollback();
        $this->flush();

        self::assertSame([], $this->sentUrls());
    }

    #[TestDox('A05b nested transaction, both commit -> one POST at the real COMMIT with both URLs')]
    public function testA05NestedCommit(): void
    {
        $this->begin();
        $this->createPost('n1');
        $this->begin();
        $this->createPost('n2');
        $this->commit();
        $this->flush();
        self::assertSame([], $this->sentUrls());
        $this->commit();
        $this->flush();

        self::assertCount(1, $this->transport()->posts);
        self::assertEqualsCanonicalizing([$this->postUrl('n1'), $this->postUrl('n2')], $this->sentUrls());
    }

    #[TestDox('A05c inner transaction rolled back to its savepoint, outer commit -> only the outer URLs')]
    public function testA05SavepointRollback(): void
    {
        $this->begin();
        $this->createPost('kept');
        $this->begin();
        $this->createPost('rolled-back');
        $this->rollback();
        $this->flush();
        self::assertSame([], $this->sentUrls());
        $this->begin();
        $this->createPost('kept-too');
        $this->commit();
        $this->commit();
        $this->flush();

        self::assertCount(1, $this->transport()->posts);
        self::assertEqualsCanonicalizing([$this->postUrl('kept'), $this->postUrl('kept-too')], $this->sentUrls());
    }

    #[TestDox('A06 three objects in one transaction -> one POST with three URLs')]
    public function testA06Batch(): void
    {
        $this->begin();
        $this->createPost('a');
        $this->createPost('b');
        $this->createPost('c');
        $this->commit();
        $this->flush();

        self::assertCount(1, $this->transport()->posts);
        self::assertEqualsCanonicalizing([$this->postUrl('a'), $this->postUrl('b'), $this->postUrl('c')], $this->sentUrls());
    }

    #[TestDox('A07 object without a rule -> nothing')]
    public function testA07Untracked(): void
    {
        $this->createUntracked();
        $this->flush();

        self::assertSame([], $this->sentUrls());
    }

    #[TestDox('A08 when=false (draft) -> nothing on create or update')]
    public function testA08Draft(): void
    {
        $post = $this->createPost('draft', published: false);
        $this->flush();
        $this->update($post, ['title' => 'still draft']);
        $this->flush();

        self::assertSame([], $this->sentUrls());
    }

    #[TestDox('A09 draft -> published is sent as creation; published -> draft as deletion')]
    public function testA09PublishTransitions(): void
    {
        $post = $this->createPost('toggle', published: false);
        $this->flush();
        self::assertSame([], $this->sentUrls());

        $this->update($post, ['published' => true]);
        $this->flush();
        self::assertSame([$this->postUrl('toggle')], $this->sentUrls(), 'draft -> published = created');

        $this->update($post, ['published' => false]);
        $this->flush();
        self::assertSame([$this->postUrl('toggle'), $this->postUrl('toggle')], $this->sentUrls(), 'published -> draft = deleted (the URL now answers 404, engines must recrawl)');
    }

    #[TestDox('A10 resolver failure -> error logged, the write succeeds, nothing sent')]
    public function testA10ResolverError(): void
    {
        $this->createBroken();
        $this->flush();

        self::assertSame([], $this->sentUrls());
        self::assertStringContainsString('cannot resolve URL', implode("\n", $this->logger()->messages('error')));
    }

    #[TestDox('A10b invalid #[IndexNow] -> error logged, the write succeeds, unrelated objects in the same unit of work are still sent')]
    public function testA10InvalidAttribute(): void
    {
        $this->begin();
        $this->createBadAttribute();
        $this->createPost('with-bad-sibling');
        $this->commit();
        $this->flush();

        self::assertSame([$this->postUrl('with-bad-sibling')], $this->sentUrls());
        self::assertStringContainsString('invalid #[IndexNow]', implode("\n", $this->logger()->messages('error')));
    }

    #[TestDox('A11 engine answers 500 -> the write succeeds, warning logged')]
    public function testA11HttpError(): void
    {
        $this->transport()->willRespond(new Response(500));
        $this->createPost('e');
        $this->flush();

        self::assertCount(1, $this->transport()->posts);
        self::assertStringContainsString('server error 500', implode("\n", $this->logger()->messages('warning')));
    }

    #[TestDox('A12 only an untracked field changed -> no POST')]
    public function testA12FieldsFilter(): void
    {
        $post = $this->createPost('views');
        $this->flush();
        $this->update($post, ['views' => 42]);
        $this->flush();

        self::assertCount(1, $this->transport()->posts);
    }

    #[TestDox('A13 bulk statement bypasses the hooks -> no POST (documented limitation)')]
    public function testA13BulkBypass(): void
    {
        $this->createPost('bulk');
        $this->flush();
        $this->bulkUpdateTitle('x');
        $this->flush();

        self::assertCount(1, $this->transport()->posts);
    }

    #[TestDox('A14 URLs wait in the collector until the unit of work ends')]
    public function testA14CollectorUntilFlush(): void
    {
        $this->createPost('later');

        self::assertSame([], $this->sentUrls());
        self::assertSame(1, $this->collectedCount());
        $this->flush();
        self::assertSame([$this->postUrl('later')], $this->sentUrls());
        self::assertSame(0, $this->collectedCount());
    }

    #[TestDox('A15 multi-rule object submits every applicable URL on create')]
    public function testA15MultiRuleSubmitsAllApplicableUrls(): void
    {
        $this->createMultiPost('multi', published: true, amp: true);
        $this->flush();

        self::assertEqualsCanonicalizing([$this->postUrl('multi'), $this->ampUrl('multi'), $this->homeUrl()], $this->sentUrls());
    }

    #[TestDox('A16 amp true -> false submits the AMP URL as deletion while the page and the filter-less homepage rule still get an update, in one flush')]
    public function testA16RuleToggleSubmitsDeletionAndUpdateTogether(): void
    {
        $post = $this->createMultiPost('withamp', published: true, amp: true);
        $this->flush();
        $this->update($post, ['amp' => false]);
        $this->flush();

        self::assertCount(2, $this->transport()->posts, 'one POST per unit of work');
        self::assertEqualsCanonicalizing([$this->ampUrl('withamp'), $this->postUrl('withamp'), $this->homeUrl()], $this->transport()->posts[1]['body']['urlList']);
    }

    #[TestDox('A17 unpublish through a getter-named `when` (isPublished() over `published`) submits the deletion')]
    public function testA17UnpublishThroughGetterNamedWhen(): void
    {
        $post = $this->createMultiPost('viagetter', published: true, amp: false);
        $this->flush();
        self::assertEqualsCanonicalizing([$this->postUrl('viagetter'), $this->homeUrl()], $this->sentUrls());

        $this->update($post, ['published' => false]);
        $this->flush();

        self::assertCount(2, $this->transport()->posts);
        self::assertEqualsCanonicalizing([$this->postUrl('viagetter'), $this->homeUrl()], $this->transport()->posts[1]['body']['urlList'], 'both rules depending on isPublished() are resubmitted as deletions');
    }

    #[TestDox('A18 deleting a draft (when already false) submits nothing, neither on create nor on delete')]
    public function testA18DeletingDraftSubmitsNothing(): void
    {
        $post = $this->createMultiPost('neverpublished', published: false, amp: false);
        $this->flush();
        self::assertSame([], $this->sentUrls());

        $this->delete($post);
        $this->flush();
        self::assertSame([], $this->sentUrls(), 'it was never public, nothing to signal');
    }

    #[TestDox('A19 via a to-one relation resubmits the category page, on create and on a later update')]
    public function testA19ViaRelationResubmitsCategoryPage(): void
    {
        $category = $this->createCategory('news');
        $post = $this->createCategorizedPost('story', $category);
        $this->flush();

        self::assertContains($this->categoryUrl('news'), $this->sentUrls());
        $first = \count($this->sentUrls());

        $this->update($post, ['views' => 1]);
        $this->flush();

        self::assertGreaterThan($first, \count($this->sentUrls()), 'the category page resubmits again on an unrelated field update');
        self::assertContains($this->categoryUrl('news'), $this->urlsOfPost(\count($this->transport()->posts) - 1));
    }

    #[TestDox('A20 attaching a tag (to-many) is not part of the change set but still triggers an update of the owner')]
    public function testA20CollectionChangeTriggersUpdate(): void
    {
        $post = $this->createCategorizedPost('tagged');
        $this->flush();
        self::assertSame([$this->postUrl('tagged')], $this->sentUrls());

        $tag = $this->createTag('news');
        $this->flush();
        $before = \count($this->transport()->posts);
        $this->attachTag($post, $tag);
        $this->flush();

        self::assertCount($before + 1, $this->transport()->posts, 'the collection change triggers another submission');
        self::assertContains($this->postUrl('tagged'), $this->urlsOfPost($before));
    }

    #[TestDox('A21 changing the field a route parameter reads (slug) submits the old URL as deleted and the new one as updated, in one flush')]
    public function testA21RenamedPageSubmitsOldAndNewUrl(): void
    {
        $post = $this->createPost('old-slug');
        $this->flush();
        $this->update($post, ['slug' => 'new-slug']);
        $this->flush();

        self::assertCount(2, $this->transport()->posts);
        self::assertEqualsCanonicalizing([$this->postUrl('old-slug'), $this->postUrl('new-slug')], $this->transport()->posts[1]['body']['urlList']);
    }

    /**
     * URLs of the n-th POST (0-based).
     *
     * @return list<string>
     */
    protected function urlsOfPost(int $index): array
    {
        self::assertArrayHasKey($index, $this->transport()->posts, 'expected POST #' . $index);
        /** @var list<string> $urls */
        $urls = $this->transport()->posts[$index]['body']['urlList'];

        return $urls;
    }

    /**
     * Every URL POSTed so far, in order.
     *
     * @return list<string>
     */
    protected function sentUrls(): array
    {
        $urls = [];
        foreach ($this->transport()->posts as $post) {
            /** @var list<string> $list */
            $list = $post['body']['urlList'];
            $urls = [...$urls, ...$list];
        }

        return $urls;
    }
}
