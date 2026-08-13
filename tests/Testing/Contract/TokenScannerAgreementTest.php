<?php

namespace Tests\MyAdmin\Plugins\Testing\Contract;

use MyAdmin\Plugins\Testing\Contract\TierB11RouteCallScanner;
use MyAdmin\Plugins\Testing\Contract\TierB14QueueActionScanner;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Shared characterization test for the two token scanners.
 *
 * ---------------------------------------------------------------------------------
 * WHY THIS FILE EXISTS
 * ---------------------------------------------------------------------------------
 * `TierB11RouteCallScanner` and `TierB14QueueActionScanner` each carry their own copy of
 * the same three primitives — drop the insignificant tokens, find a bracket's partner,
 * split a slice on a top-level separator. The copies were **not** equivalent: B-11 counted
 * the `{$`, `${` and `#[` openers that produce a closing brace without a matching literal
 * `{`, and B-14 did not. On `f( "{$a}" , "tail" )` that made B-14 stop at the
 * interpolation's `}` and silently truncate the argument list.
 *
 * A 508-mutant sweep showed why nobody noticed: **every distinguishing feature of the
 * correct copy was untested**. Deleting B-11's `opensCurly()` branch from
 * `matchingBracket()`, deleting it from `splitArguments()`, and deleting `?->` support all
 * left the full suite green. Consolidating the two scanners onto the weaker implementation
 * — the obvious "remove the duplication" refactor — would therefore have landed green and
 * shipped the truncation fleet-wide.
 *
 * So this file pins the *agreement* rather than either implementation. It compares the two
 * scanners against each other on one fixture set, and additionally pins the absolute answer
 * for the case that diverged, so that "both wrong in the same way" is not a way to pass.
 * If the duplication is ever collapsed into one helper, these tests keep working unchanged
 * and keep proving the surviving copy is the strong one.
 *
 * The primitives are private. Reflection is deliberate: making them public to test them
 * would widen the scanners' API purely for the test's convenience, and the invariant being
 * pinned is internal by nature.
 *
 * @covers \MyAdmin\Plugins\Testing\Contract\TierB11RouteCallScanner
 * @covers \MyAdmin\Plugins\Testing\Contract\TierB14QueueActionScanner
 */
class TokenScannerAgreementTest extends TestCase
{
    /**
     * The defect fixture, reduced to one line.
     *
     * `"{$a}"` lexes as `"` + T_CURLY_OPEN + T_VARIABLE + `}` + `"`. The opener is an *array*
     * token; the closer is the plain string `'}'`. A depth counter that only recognises the
     * string `'{'` therefore misses the open and still takes the close.
     *
     * @var string
     */
    private const INTERPOLATION = <<<'PHP'
<?php
$loader->add_page_requirement( "{$a}" , "tail" );
PHP;

    /**
     * The `${name}` spelling of the same hazard: T_DOLLAR_OPEN_CURLY_BRACES, closed by `}`.
     *
     * Deprecated in PHP 8.2 and removed in 9, but `token_get_all()` only lexes — it does not
     * compile — so this raises nothing here, and the fleet's floor is 7.4 where it is the
     * ordinary spelling.
     *
     * @var string
     */
    private const DOLLAR_INTERPOLATION = <<<'PHP'
<?php
$loader->add_page_requirement( "${a}" , "tail" );
PHP;

    /**
     * An attribute between two call sites. `#[` is T_ATTRIBUTE, closed by a plain `]`.
     *
     * On PHP 7.4 this whole line lexes as a single T_COMMENT and disappears in the
     * significant-token filter, which is why the fixture is safe at the floor.
     *
     * @var string
     */
    private const ATTRIBUTE = <<<'PHP'
<?php
$loader->add_page_requirement('a', '/a.php');
#[Deprecated]
function later() {}
$loader->add_page_requirement('b', '/b.php');
PHP;

    /**
     * A nullsafe call site. PHP 8.0's `?->` is one token; on 7.4 the same characters lex as
     * `?` followed by T_OBJECT_OPERATOR.
     *
     * @var string
     */
    private const NULLSAFE = <<<'PHP'
<?php
$loader?->add_page_requirement('abuse', '/abuse.php');
PHP;

    /**
     * A path assembled with `.=` rather than one concatenation chain.
     *
     * No interpolation anywhere: this shape is the second, independent B-14 defect, and it
     * is here so that the agreement fixtures cover a scan that produces no braces at all.
     *
     * @var string
     */
    private const CONCAT_ASSIGN = <<<'PHP'
<?php
$path = __DIR__;
$path .= '/../templates/';
$path .= $serviceInfo['action'];
$path .= '.sh.tpl';
$smarty->fetch($path);
PHP;

    /**
     * Every hazard at once, so the counters are exercised nested rather than in isolation.
     *
     * @var string
     */
    private const COMBINED = <<<'PHP'
<?php
#[Deprecated]
function later() {}
$path = __DIR__;
$path .= "/../{$vendor}/templates/";
$loader?->add_page_requirement("route{$n}", $path . '.sh.tpl', ['a' => "{$x}", 'b']);
$loader->add_page_requirement('plain', '/plain.php');
PHP;

    /**
     * @return array<string,array{0:string}>
     */
    public function sourceProvider(): array
    {
        return [
            'brace interpolation in an argument list' => [self::INTERPOLATION],
            'dollar-brace interpolation in an argument list' => [self::DOLLAR_INTERPOLATION],
            'attribute between call sites' => [self::ATTRIBUTE],
            'nullsafe call site' => [self::NULLSAFE],
            'path built with .=' => [self::CONCAT_ASSIGN],
            'all of the above at once' => [self::COMBINED],
        ];
    }

    /**
     * @param array<int,mixed> $arguments
     * @return mixed
     */
    private static function callPrivate(string $class, string $method, array $arguments)
    {
        $reflection = new ReflectionMethod($class, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs(null, $arguments);
    }

    /**
     * The significant-token stream, asserted to be the same on both scanners before use.
     *
     * Comparing spans on a stream only one of them actually walks would compare nothing, so
     * this equality is a precondition of every other assertion here, not a separate nicety.
     *
     * @return array<int,array{0:int,1:string,2:int}|string>
     */
    private function agreedStream(string $source): array
    {
        $raw = token_get_all($source);
        $fromB11 = self::callPrivate(TierB11RouteCallScanner::class, 'significant', [$raw]);
        $fromB14 = self::callPrivate(TierB14QueueActionScanner::class, 'significant', [$raw]);
        $this->assertSame($fromB11, $fromB14, 'the two scanners must keep the same tokens');
        return $fromB11;
    }

    /**
     * Indices of every token that opens a bracket, decided here rather than by asking either
     * scanner — the point is to probe both with a list neither of them supplied.
     *
     * @param array<int,array{0:int,1:string,2:int}|string> $tokens
     * @return array<int,int>
     */
    private function openerIndexes(array $tokens): array
    {
        $arrayOpeners = [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES];
        if (defined('T_ATTRIBUTE')) {
            $arrayOpeners[] = constant('T_ATTRIBUTE');
        }
        $indexes = [];
        foreach ($tokens as $index => $token) {
            if ($token === '(' || $token === '[' || $token === '{') {
                $indexes[] = $index;
                continue;
            }
            if (is_array($token) && in_array($token[0], $arrayOpeners, true)) {
                $indexes[] = $index;
            }
        }
        return $indexes;
    }

    /**
     * opener index => the index its partner was found at, or null.
     *
     * @param array<int,array{0:int,1:string,2:int}|string> $tokens
     * @return array<int,int|null>
     */
    private function spanMap(string $scanner, array $tokens): array
    {
        $spans = [];
        foreach ($this->openerIndexes($tokens) as $index) {
            $spans[$index] = self::callPrivate($scanner, 'matchingBracket', [$tokens, $index]);
        }
        return $spans;
    }

    /**
     * @dataProvider sourceProvider
     */
    public function testBothScannersComputeIdenticalBracketSpans(string $source): void
    {
        $tokens = $this->agreedStream($source);

        $spansB11 = $this->spanMap(TierB11RouteCallScanner::class, $tokens);
        $spansB14 = $this->spanMap(TierB14QueueActionScanner::class, $tokens);

        $this->assertNotSame([], $spansB11, 'the fixture must contain at least one bracket to probe');
        $this->assertSame(
            $spansB11,
            $spansB14,
            'B-11 and B-14 disagree about where a bracket closes; one of them is truncating'
        );
    }

    /**
     * @dataProvider sourceProvider
     */
    public function testBothScannersSplitTopLevelCommasIdentically(string $source): void
    {
        $tokens = $this->agreedStream($source);
        $probed = 0;

        foreach ($this->openerIndexes($tokens) as $index) {
            if ($tokens[$index] !== '(') {
                continue;
            }
            $close = self::callPrivate(TierB11RouteCallScanner::class, 'matchingBracket', [$tokens, $index]);
            $this->assertNotNull($close, 'a balanced fixture must not contain an unclosed "("');
            $slice = array_slice($tokens, $index + 1, $close - $index - 1);
            $this->assertSame(
                self::callPrivate(TierB11RouteCallScanner::class, 'splitArguments', [$slice]),
                self::callPrivate(TierB14QueueActionScanner::class, 'splitTopLevel', [$slice, ',']),
                'B-11 and B-14 disagree about where the top-level commas are'
            );
            $probed++;
        }

        $this->assertGreaterThan(0, $probed, 'the fixture must contain at least one argument list');
    }

    /**
     * The absolute answer, so that "both scanners wrong in the same way" cannot pass.
     *
     * Agreement alone is satisfied by two identically broken copies. This pins what the
     * right answer *is* for the case that diverged: the call closes at its own `)`, and the
     * argument after the interpolated one is still there.
     */
    public function testInterpolatedArgumentDoesNotTruncateTheArgumentList(): void
    {
        $tokens = $this->agreedStream(self::INTERPOLATION);
        $open = array_search('(', $tokens, true);
        $this->assertIsInt($open);

        foreach ([TierB11RouteCallScanner::class, TierB14QueueActionScanner::class] as $scanner) {
            $close = self::callPrivate($scanner, 'matchingBracket', [$tokens, $open]);
            $this->assertNotNull($close, $scanner.' lost the closing bracket');
            $this->assertSame(')', $tokens[$close], $scanner.' stopped on a brace that is not the call\'s closer');
            $this->assertSame(count($tokens) - 2, $close, $scanner.' stopped before the end of the call');
        }

        $close = self::callPrivate(TierB11RouteCallScanner::class, 'matchingBracket', [$tokens, $open]);
        $slice = array_slice($tokens, $open + 1, $close - $open - 1);
        $arguments = self::callPrivate(TierB14QueueActionScanner::class, 'splitTopLevel', [$slice, ',']);
        $this->assertCount(2, $arguments, 'the interpolated first argument swallowed the second');
        $this->assertSame('"tail"', $arguments[1][0][1], 'the trailing argument must survive the interpolation');
    }

    /**
     * The `#[` opener has no literal `{` or `[` of its own, so a counter that ignores it
     * still takes the closing `]` and desynchronises from there on.
     */
    public function testAttributeOpenerIsPairedWithItsClosingBracket(): void
    {
        if (!defined('T_ATTRIBUTE')) {
            $tokens = $this->agreedStream(self::ATTRIBUTE);
            $texts = array_map(function ($token) {
                return is_array($token) ? $token[1] : $token;
            }, $tokens);
            $this->assertNotContains('#[', $texts, 'on PHP 7.4 the attribute must lex away as a comment');
            return;
        }

        $tokens = $this->agreedStream(self::ATTRIBUTE);
        $open = null;
        foreach ($tokens as $index => $token) {
            if (is_array($token) && $token[0] === constant('T_ATTRIBUTE')) {
                $open = $index;
                break;
            }
        }
        $this->assertNotNull($open, 'the fixture stopped producing a T_ATTRIBUTE token');

        foreach ([TierB11RouteCallScanner::class, TierB14QueueActionScanner::class] as $scanner) {
            $close = self::callPrivate($scanner, 'matchingBracket', [$tokens, $open]);
            $this->assertNotNull($close, $scanner.' could not close the attribute');
            $this->assertSame(']', $tokens[$close], $scanner.' closed the attribute on the wrong token');
            $this->assertSame($open + 2, $close, $scanner.' skipped past the attribute\'s own closer');
        }
    }

    /**
     * `?->` is a call site like any other. B-11 resolves the constant at runtime because the
     * 7.4 floor has no such token; if that lookup is removed, every nullsafe registration
     * becomes invisible and the plugin reads as registering no routes at all.
     */
    public function testNullsafeCallSitesAreRecovered(): void
    {
        if (!defined('T_NULLSAFE_OBJECT_OPERATOR')) {
            $this->markTestSkipped('?-> requires PHP 8.0');
        }

        $calls = TierB11RouteCallScanner::scanSource(self::NULLSAFE);

        $this->assertCount(1, $calls, 'a $loader?->add_page_requirement() call must not be skipped');
        $this->assertSame('add_page_requirement', $calls[0]['helper']);
        $this->assertTrue($calls[0]['resolved']);
        $this->assertSame(['abuse', '/abuse.php'], $calls[0]['args']);
    }

    /**
     * The same call written with `->`, to prove the nullsafe assertion above is testing the
     * operator and not something incidental to the fixture.
     */
    public function testPlainObjectOperatorCallSitesAreRecovered(): void
    {
        $calls = TierB11RouteCallScanner::scanSource(
            "<?php\n\$loader->add_page_requirement('abuse', '/abuse.php');\n"
        );

        $this->assertCount(1, $calls);
        $this->assertSame(['abuse', '/abuse.php'], $calls[0]['args']);
    }

    /**
     * The end-to-end consequence of the span defect, stated in B-11's own vocabulary: an
     * interpolated first argument must not hide the arguments that follow it.
     */
    public function testInterpolatedCallIsReportedWithItsFullArity(): void
    {
        $calls = TierB11RouteCallScanner::scanSource(self::INTERPOLATION);

        $this->assertCount(1, $calls);
        $this->assertSame(2, $calls[0]['argCount'], 'the second argument was swallowed by the interpolation');
        $this->assertFalse($calls[0]['resolved'], 'an interpolated argument is not a literal');
    }
}
