<?php

namespace Tests\MyAdmin\Plugins\Testing\Contract;

use MyAdmin\Plugins\Testing\Contract\Finding;
use MyAdmin\Plugins\Testing\Contract\PluginSubject;
use MyAdmin\Plugins\Testing\Contract\TierB14QueueActionScanner;
use MyAdmin\Plugins\Testing\Contract\TierB14TemplateCompleteness;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The two ways B-14 used to be wrong quietly.
 *
 * ---------------------------------------------------------------------------------
 * WHAT THIS FILE IS FOR
 * ---------------------------------------------------------------------------------
 * `TierB14TemplateCompletenessTest` covers what B-14 *says* about a handler it understood.
 * This one covers the cases where it did not understand the handler and said something
 * anyway:
 *
 *  1. a template path assembled with `.=`, which leaves the scanner with the `.sh.tpl`
 *     suffix and no directory. Resolving that against the package root retargets the whole
 *     check one directory too high and reports every action as missing a template that is
 *     sitting in `templates/` where it belongs;
 *  2. a token walk that desynchronised, which produced the same "nothing to cross-check"
 *     skip as an honest dynamic dispatch — so the harness failing and the harness working
 *     were the same cell in the fleet matrix.
 *
 * Both are stated as *contrasts* rather than in isolation: each test that pins the new
 * behaviour is paired with one pinning the neighbouring case it must stay distinguishable
 * from, because "indistinguishable from its neighbour" was the defect.
 *
 * @covers \MyAdmin\Plugins\Testing\Contract\TierB14TemplateCompleteness
 * @covers \MyAdmin\Plugins\Testing\Contract\TierB14QueueActionScanner
 */
class TierB14ScannerSoundnessTest extends TestCase
{
    /** @var string */
    private $root;

    /** @var TierB14TemplateCompleteness */
    private $inspector;

    /** @var int keeps every fixture class name unique; a collision in one process is fatal */
    private static $seq = 0;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/tierb14snd_'.getmypid().'_'.uniqid();
        mkdir($this->root, 0777, true);
        $this->inspector = new TierB14TemplateCompleteness();
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir), ['.', '..']) as $entry) {
            $path = $dir.'/'.$entry;
            is_dir($path) && !is_link($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * Writes and loads a fixture service package, returning its class name.
     *
     * @param string        $queueBody body of getQueue()
     * @param string[]|null $templates template basenames to create under templates/
     */
    private function makePlugin(string $queueBody, ?array $templates): string
    {
        $namespace = 'TierB14Snd'.getmypid().'x'.(++self::$seq);
        $package = $this->root.'/pkg'.self::$seq;
        mkdir($package.'/src', 0777, true);
        if ($templates !== null) {
            mkdir($package.'/templates', 0777, true);
            foreach ($templates as $name) {
                file_put_contents($package.'/templates/'.$name.'.sh.tpl', "#!/bin/sh\necho {$name}\n");
            }
        }
        $source = "<?php\nnamespace {$namespace};\nclass Plugin\n{\n"
            ."    public static \$name = 'Fixture';\n"
            ."    public static \$module = 'vps';\n"
            ."    public static \$type = 'service';\n"
            ."    public static function getQueue(\$event)\n    {\n".$queueBody."\n    }\n"
            ."}\n";
        $file = $package.'/src/Plugin.php';
        file_put_contents($file, $source);
        require $file;
        return $namespace.'\\Plugin';
    }

    /**
     * The same fixture with the whole method crammed onto one line, along with the brace
     * that closes the class.
     *
     * `TierB14TemplateCompleteness::methodSource()` slices lines, not syntax, so this shape
     * hands the scanner a stream with one `}` too many. It is the cheapest reproduction of a
     * desynchronised walk that does not require inventing an implausible handler.
     */
    private function makeCrammedPlugin(): string
    {
        $namespace = 'TierB14Snd'.getmypid().'x'.(++self::$seq);
        $package = $this->root.'/pkg'.self::$seq;
        mkdir($package.'/src', 0777, true);
        mkdir($package.'/templates', 0777, true);
        file_put_contents($package.'/templates/create.sh.tpl', "echo create\n");
        $source = "<?php\nnamespace {$namespace};\nclass Plugin\n{\n"
            ."    public static \$name = 'Fixture';\n"
            ."    public static \$module = 'vps';\n"
            ."    public static \$type = 'service';\n"
            ."    public static function getQueue(\$event)"
            ." { return __DIR__.'/../templates/'.\$event['action'].'.sh.tpl'; } }\n";
        $file = $package.'/src/Plugin.php';
        file_put_contents($file, $source);
        require $file;
        return $namespace.'\\Plugin';
    }

    /**
     * @return Finding[]
     */
    private function inspect(string $class): array
    {
        return $this->inspector->inspect(new PluginSubject($class));
    }

    /**
     * @param Finding[] $findings
     */
    private function messages(array $findings): string
    {
        return implode(' | ', array_map(function (Finding $finding) {
            return $finding->describe();
        }, $findings));
    }

    /**
     * @param Finding[] $findings
     * @return Finding[]
     */
    private function failures(array $findings): array
    {
        return array_values(array_filter($findings, function (Finding $finding) {
            return $finding->isFailure();
        }));
    }

    /**
     * The R-2(2) reproduction.
     *
     * `create.sh.tpl` is on disk, in `templates/`, exactly where the handler puts it. Before
     * the fix B-14 resolved the empty directory against the package root, listed no
     * templates there, and reported `create` as missing — a failure naming a file that
     * exists. No interpolation is involved, which is what makes this defect independent of
     * the bracket-counting one.
     */
    public function testConcatAssignPathIsSkippedInsteadOfBlamingTemplatesThatExist(): void
    {
        $body = "        \$serviceInfo = \$event->getSubject();\n"
            ."        \$path = __DIR__;\n"
            ."        \$path .= '/../templates/';\n"
            ."        if (in_array(\$serviceInfo['action'], ['create'])) {\n"
            ."            \$path .= \$serviceInfo['action'];\n"
            ."        }\n"
            ."        \$path .= '.sh.tpl';\n"
            ."        return \$path;";
        $class = $this->makePlugin($body, ['create']);

        $findings = $this->inspect($class);

        $this->assertSame([], $this->failures($findings), $this->messages($findings));
        $this->assertCount(1, $findings, $this->messages($findings));
        $this->assertTrue($findings[0]->isSkipped());
        $this->assertStringContainsString('without a directory literal', $findings[0]->message());
        $this->assertStringNotContainsString('create', $findings[0]->message());
    }

    /**
     * The neighbouring case that must keep working: the same directory written as one
     * concatenation chain still resolves, so the skip above is about the missing directory
     * and not about `.=` scaring the inspector off.
     */
    public function testAnOrdinaryConcatenationChainStillResolvesItsDirectory(): void
    {
        $body = "        \$serviceInfo = \$event->getSubject();\n"
            ."        if (in_array(\$serviceInfo['action'], ['create', 'resize'])) {\n"
            ."            return __DIR__.'/../templates/'.\$serviceInfo['action'].'.sh.tpl';\n"
            ."        }\n"
            ."        return false;";
        $class = $this->makePlugin($body, ['create']);

        $failures = $this->failures($this->inspect($class));

        $this->assertCount(1, $failures, 'resize has no template and must still be reported');
        $this->assertSame('resize', $failures[0]->context()['action']);
    }

    /**
     * The R-2(3) reproduction: a desynchronised walk is now a *different* skip.
     */
    public function testDesynchronisedScanIsSkippedWithADistinctReason(): void
    {
        $class = $this->makeCrammedPlugin();

        $findings = $this->inspect($class);

        $this->assertSame([], $this->failures($findings), $this->messages($findings));
        $this->assertCount(1, $findings, $this->messages($findings));
        $this->assertTrue($findings[0]->isSkipped());
        $this->assertStringContainsString(
            TierB14TemplateCompleteness::SCAN_TRUNCATED,
            $findings[0]->message(),
            'a broken parse must not read as an honest "nothing to cross-check"'
        );
        $this->assertSame(1, $findings[0]->context()['desyncs']);
    }

    /**
     * The other half of the same claim. An honest dynamic dispatch — six of the eight real
     * queue handlers — must not pick up the truncation marker, or the marker means nothing.
     */
    public function testHonestDynamicDispatchSkipIsNotMarkedAsTruncated(): void
    {
        $body = "        \$serviceInfo = \$event->getSubject();\n"
            ."        if (!file_exists(__DIR__.'/../templates/'.\$serviceInfo['action'].'.sh.tpl')) {\n"
            ."            return false;\n"
            ."        }\n"
            ."        return __DIR__.'/../templates/'.\$serviceInfo['action'].'.sh.tpl';";
        $class = $this->makePlugin($body, ['create', 'delete']);

        $findings = $this->inspect($class);

        $this->assertCount(1, $findings, $this->messages($findings));
        $this->assertTrue($findings[0]->isSkipped());
        $this->assertStringContainsString('no action set to cross-check', $findings[0]->message());
        $this->assertStringNotContainsString(
            TierB14TemplateCompleteness::SCAN_TRUNCATED,
            $findings[0]->message()
        );
    }

    /**
     * `operandBefore()` walks a concatenation chain backwards, so an interpolated segment in
     * the middle of it is a `}` before a `{$` from that direction.
     *
     * When the walk cannot account for the `{$` it never returns to depth 0, which means
     * nothing breaks the expression any more and the "chain" keeps swallowing tokens back
     * through the `(` of the enclosing call. The visible result is a dispatch that has lost
     * its `__DIR__` anchor and its directory — reported as `relative` with an empty
     * directory, which is precisely the shape the resolver used to point at the package root.
     */
    public function testInterpolatedSegmentDoesNotDetachAChainFromItsDirectory(): void
    {
        $dispatches = TierB14QueueActionScanner::templateDispatches(
            "<?php\n\$s->fetch(__DIR__.'/../templates/'.\"{\$sub}\".'.sh.tpl');\n"
        );

        $this->assertCount(1, $dispatches);
        $this->assertSame('dir', $dispatches[0]['anchor'], 'the __DIR__ anchor was walked past');
        $this->assertSame('/../templates/', $dispatches[0]['directory'], 'the directory literal was lost');
        $this->assertTrue($dispatches[0]['dynamic']);
        $this->assertNull($dispatches[0]['template']);
    }

    /**
     * `operandAfter()` is only reachable through the comparison rule, and every way of
     * getting an interpolated right-hand operand out of that rule ends in "not a lone string
     * literal" either way — so the counter is pinned here directly instead.
     *
     * That is deliberate rather than lazy: the invariant being protected is that an operand
     * slice is never cut in half by an interpolation, and the only place that is observable
     * is the slice itself. A future rule that reads more than `loneString()` out of the
     * right-hand operand would otherwise inherit a silently truncated slice.
     */
    public function testOperandAfterDoesNotStopInsideAnInterpolation(): void
    {
        $source = "<?php\nif (\$i['action'] === \"pre{\$x}post\") { doIt(); }\n";
        $tokens = $this->significant($source);
        $operator = null;
        foreach ($tokens as $index => $token) {
            if (is_array($token) && $token[0] === T_IS_IDENTICAL) {
                $operator = $index;
                break;
            }
        }
        $this->assertNotNull($operator, 'the fixture must contain an identity comparison');

        $operand = $this->callPrivate('operandAfter', [$tokens, $operator]);

        $this->assertSame('"', $operand[0], 'the operand must start at the opening quote');
        $this->assertSame('"', end($operand), 'the operand was cut off inside the interpolation');
        $this->assertContains('}', $operand, 'the interpolation closer belongs to the operand');
    }

    /**
     * @return array<string,array{0:string}>
     */
    public function balancedSourceProvider(): array
    {
        return [
            'brace interpolation' => ["<?php\n\$s->fetch(\"{\$dir}/create.sh.tpl\");\n"],
            'subscript inside interpolation' => ["<?php\n\$s->fetch(\"{\$info['dir']}/create.sh.tpl\");\n"],
            'array literal argument' => ["<?php\nin_array(\$i['action'], ['create', 'delete']);\n"],
            'nested calls' => ["<?php\n\$s->fetch(dirname(__DIR__).'/'.trim(\$a, '/').'.sh.tpl');\n"],
            'switch body' => ["<?php\nswitch (\$i['action']) { case 'create': break; }\n"],
            'attribute' => ["<?php\n#[Deprecated]\nfunction f() {}\n\$s->fetch('a.sh.tpl');\n"],
            'heredoc with interpolation' => ["<?php\n\$x = <<<SH\necho {\$name}\nSH;\n"],
        ];
    }

    /**
     * @dataProvider balancedSourceProvider
     */
    public function testBalancedSourcesReportNoDesync(string $source): void
    {
        $this->assertSame([], TierB14QueueActionScanner::scanDesyncs($source), $source);
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public function desyncedSourceProvider(): array
    {
        return [
            'unclosed call' => ["<?php\nf(\$a;\n", 'is never closed'],
            'surplus closer' => ["<?php\nf(\$a); }\n", 'unmatched "}"'],
            'mismatched pair' => ["<?php\n\$a = [1);\n", 'expected "]"'],
            'unclosed interpolation' => ["<?php\n\$a = \"{\$b\";\n", 'is never closed'],
        ];
    }

    /**
     * @dataProvider desyncedSourceProvider
     */
    public function testDesyncedSourcesAreReported(string $source, string $expected): void
    {
        $problems = TierB14QueueActionScanner::scanDesyncs($source);

        $this->assertNotSame([], $problems, $source);
        $this->assertStringContainsString($expected, implode(' | ', $problems));
    }

    /**
     * The equivalence `scanDesyncs()` is documented to have, checked rather than asserted in
     * prose: on a clean stream every opener's `matchingBracket()` answer is a real partner of
     * the matching kind.
     *
     * @dataProvider balancedSourceProvider
     */
    public function testACleanScanMeansEveryBracketQueryIsSound(string $source): void
    {
        $tokens = $this->significant($source);
        $probed = 0;

        foreach ($tokens as $index => $token) {
            $expected = $this->expectedCloser($token);
            if ($expected === null) {
                continue;
            }
            $close = $this->matchingBracket($tokens, $index);
            $this->assertNotNull($close, 'opener at '.$index.' has no partner in '.$source);
            $this->assertSame($expected, $tokens[$close], 'opener at '.$index.' closed on the wrong token');
            $probed++;
        }

        $this->assertGreaterThan(0, $probed, 'the fixture must contain at least one bracket');
    }

    /**
     * The contrapositive of the guarantee above, and the one the loudness depends on: an
     * unsound bracket query cannot happen on a stream `scanDesyncs()` calls clean.
     *
     * Checked across the whole desynced fixture set at once, with a final assertion that at
     * least one of them really does produce an unsound query — otherwise this test would
     * pass vacuously on a set of harmless fixtures.
     */
    public function testNoUnsoundBracketQueryEscapesTheDesyncReport(): void
    {
        $total = 0;

        foreach ($this->desyncedSourceProvider() as $label => $case) {
            $source = $case[0];
            $unsound = $this->unsoundQueryCount($source);
            $total += $unsound;
            if ($unsound > 0) {
                $this->assertNotSame(
                    [],
                    TierB14QueueActionScanner::scanDesyncs($source),
                    $label.' has an unsound bracket query that scanDesyncs() did not report'
                );
            }
        }

        $this->assertGreaterThan(0, $total, 'no fixture in the set actually breaks a bracket query');
    }

    /**
     * The other side of the same coin, stated so nobody "fixes" it later.
     *
     * The crammed one-line handler — the fixture that drives the B-14 skip above — reports a
     * desync (a surplus `}` left over from the class body) while every opener in it still
     * pairs up correctly. `scanDesyncs()` is a conservative precondition, not a detector of
     * demonstrated harm: it costs a skip on a check that had nothing to prove, and buys the
     * guarantee that a truncation can never be silent.
     */
    public function testDesyncReportIsConservativeAndMaySeeNoActualHarm(): void
    {
        $source = "<?php\n    public static function getQueue(\$e)"
            ." { return __DIR__.'/../templates/'.\$e['action'].'.sh.tpl'; } }\n";

        $this->assertNotSame([], TierB14QueueActionScanner::scanDesyncs($source));
        $this->assertSame(0, $this->unsoundQueryCount($source), 'this fixture is the conservative case');
    }

    /**
     * How many openers in $source get an answer that is not their true partner.
     */
    private function unsoundQueryCount(string $source): int
    {
        $tokens = $this->significant($source);
        $unsound = 0;
        foreach ($tokens as $index => $token) {
            $expected = $this->expectedCloser($token);
            if ($expected === null) {
                continue;
            }
            $close = $this->matchingBracket($tokens, $index);
            if ($close === null || $tokens[$close] !== $expected) {
                $unsound++;
            }
        }
        return $unsound;
    }

    /**
     * @param array{0:int,1:string,2:int}|string $token
     * @return string|null the closer this token is waiting for, or null when it opens nothing
     */
    private function expectedCloser($token): ?string
    {
        if (!is_array($token)) {
            if ($token === '(') {
                return ')';
            }
            if ($token === '[') {
                return ']';
            }
            return $token === '{' ? '}' : null;
        }
        if (defined('T_ATTRIBUTE') && $token[0] === constant('T_ATTRIBUTE')) {
            return ']';
        }
        return in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true) ? '}' : null;
    }

    /**
     * @return array<int,array{0:int,1:string,2:int}|string>
     */
    private function significant(string $source): array
    {
        return $this->callPrivate('significant', [token_get_all($source)]);
    }

    /**
     * @param array<int,array{0:int,1:string,2:int}|string> $tokens
     * @return int|null
     */
    private function matchingBracket(array $tokens, int $open): ?int
    {
        return $this->callPrivate('matchingBracket', [$tokens, $open]);
    }

    /**
     * @param array<int,mixed> $arguments
     * @return mixed
     */
    private function callPrivate(string $method, array $arguments)
    {
        $reflection = new ReflectionMethod(TierB14QueueActionScanner::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs(null, $arguments);
    }
}
