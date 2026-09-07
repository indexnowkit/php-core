<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use DateTimeImmutable;
use IndexNowKit\Testing\ArrayLogger;
use IndexNowKit\Transaction\VerifyingStaging;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

final class VerifyingStagingTest extends TestCase
{
    #[TestDox('flush keeps the URLs whose verifier says the change landed and drops the others, de-duplicated')]
    public function testFlushRunsVerifiers(): void
    {
        $logger = new ArrayLogger();
        $staging = new VerifyingStaging($logger);
        $scope = new stdClass();

        $staging->stage($scope, static fn(): bool => true, ['https://a/1', 'https://a/2', 'https://a/1'], 'Post#1');
        $staging->stage($scope, static fn(): bool => false, ['https://a/3', 'https://a/2'], 'Post#2');
        $staging->stage($scope, static fn(): bool => true, [], 'Post#3');
        self::assertTrue($staging->hasPending($scope));
        self::assertSame(4, $staging->pendingCount($scope));

        self::assertSame(['https://a/1', 'https://a/2'], $staging->flush($scope));
        self::assertFalse($staging->hasPending($scope));
        self::assertSame([], $staging->flush($scope), 'a second flush finds nothing');
        self::assertCount(1, $logger->messages('debug'));
        self::assertStringContainsString('Post#2', $logger->messages('debug')[0]);
    }

    #[TestDox('a verifier that throws counts as survived (announcing is the safer default) and is logged at warning')]
    public function testVerifierFailure(): void
    {
        $logger = new ArrayLogger();
        $staging = new VerifyingStaging($logger);
        $scope = new stdClass();
        $staging->stage($scope, static fn(): bool => throw new RuntimeException('db gone'), ['https://a/1'], 'Post#1');

        self::assertSame(['https://a/1'], $staging->flush($scope));
        self::assertCount(1, $logger->messages('warning'));
    }

    #[TestDox('discard drops everything without running a verifier; scopes are independent')]
    public function testDiscardAndScopes(): void
    {
        $logger = new ArrayLogger();
        $staging = new VerifyingStaging($logger);
        $a = new stdClass();
        $b = new stdClass();
        $ran = false;
        $staging->stage($a, static function () use (&$ran): bool {
            $ran = true;

            return true;
        }, ['https://a/1']);
        $staging->stage($b, static fn(): bool => true, ['https://b/1']);

        $staging->discard($a);
        self::assertFalse($ran);
        self::assertFalse($staging->hasPending($a));
        self::assertSame(['https://b/1'], $staging->flush($b));
        self::assertCount(1, $logger->messages('debug'));
    }

    #[TestDox('rowMatches compares loosely (driver strings vs typed values), ignores columns the row lacks, fails on a missing row')]
    public function testRowMatches(): void
    {
        self::assertTrue(VerifyingStaging::rowMatches(['id' => '1', 'published' => '1', 'title' => 'T'], ['published' => true, 'title' => 'T']));
        self::assertTrue(VerifyingStaging::rowMatches(['id' => 1, 'views' => '10'], ['views' => 10]));
        self::assertFalse(VerifyingStaging::rowMatches(['slug' => 'old'], ['slug' => 'new']));
        self::assertTrue(VerifyingStaging::rowMatches(['slug' => 'x'], ['unknown_column' => 'y']), 'a column the re-read row does not carry cannot contradict');
        self::assertFalse(VerifyingStaging::rowMatches(null, ['slug' => 'x']));
        self::assertTrue(VerifyingStaging::rowMatches(['deleted_at' => null], ['deleted_at' => null]));
        self::assertFalse(VerifyingStaging::rowMatches(['deleted_at' => '2026-01-01 00:00:00'], ['deleted_at' => null]));
    }

    #[TestDox('rowMatches skips the values a driver spells its own way (floats, dates, arrays, objects) and treats an empty expectation as "the row is there"')]
    public function testRowMatchesAmbiguousTypes(): void
    {
        self::assertTrue(VerifyingStaging::rowMatches(['price' => '19.90'], ['price' => 19.9]), 'DECIMAL comes back as a string with the scale of the column');
        self::assertTrue(VerifyingStaging::rowMatches(['at' => '2026-09-07 08:00:00+00'], ['at' => new DateTimeImmutable('2026-09-07 08:00:00')]), 'timestamptz carries a zone suffix');
        self::assertTrue(VerifyingStaging::rowMatches(['meta' => '{"b": 1, "a": 2}'], ['meta' => ['a' => 2, 'b' => 1]]), 'the database re-spells a JSON document');
        self::assertTrue(VerifyingStaging::rowMatches(['id' => '7'], []), 'an insert passes no values: the row is there, the change landed');
        self::assertFalse(VerifyingStaging::rowMatches(null, []), 'no row: nothing landed');
        self::assertFalse(VerifyingStaging::rowMatches(['price' => '19.90', 'slug' => 'old'], ['price' => 19.9, 'slug' => 'new']), 'the unambiguous column still decides');
        self::assertTrue(VerifyingStaging::rowMatches(['kind' => 'a'], ['kind' => StagingKind::A]));
        self::assertFalse(VerifyingStaging::rowMatches(['kind' => 'b'], ['kind' => StagingKind::A]));
    }

    #[TestDox('a second change of the same subject (key) in one scope merges into the first: URLs joined, the verifier replaced by the latest')]
    public function testStageMergesBySubjectKey(): void
    {
        $logger = new ArrayLogger();
        $staging = new VerifyingStaging($logger);
        $scope = new stdClass();
        $first = 0;
        $second = 0;

        $staging->stage($scope, static function () use (&$first): bool {
            ++$first;
            return false;
        }, ['https://a/old', 'https://a/mid'], 'Post#1', 'Post#1');
        $staging->stage($scope, static function () use (&$second): bool {
            ++$second;
            return true;
        }, ['https://a/mid', 'https://a/new'], 'Post#1', 'Post#1');
        $staging->stage($scope, static fn(): bool => true, ['https://a/other'], 'Post#2', 'Post#2');
        $staging->stage($scope, static fn(): bool => true, ['https://a/anon'], 'Post#3');
        $staging->stage($scope, static fn(): bool => true, ['https://a/anon2'], 'Post#3');
        self::assertSame(6, $staging->pendingCount($scope), 'the merged change counts its joined URLs once; changes without a key never merge');

        self::assertSame(['https://a/old', 'https://a/mid', 'https://a/new', 'https://a/other', 'https://a/anon', 'https://a/anon2'], $staging->flush($scope));
        self::assertSame(0, $first, 'the first verifier is gone: it expected values the second change overwrote');
        self::assertSame(1, $second);
    }
}

enum StagingKind: string
{
    case A = 'a';
    case B = 'b';
}
