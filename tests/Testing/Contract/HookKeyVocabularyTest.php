<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace Tests\MyAdmin\Plugins\Testing\Contract;

use MyAdmin\Plugins\Testing\Contract\PluginSubject;
use MyAdmin\Plugins\Testing\Contract\TierA7HookKeyScoping;
use MyAdmin\Plugins\Testing\Contract\TierB9bHookKeysDispatched;
use PHPUnit\Framework\TestCase;

/**
 * The drift guard for the one hook-key vocabulary two inspectors read.
 *
 * ---------------------------------------------------------------------------------
 * WHAT WENT WRONG, AND WHY A GUARD RATHER THAN A COMMENT
 * ---------------------------------------------------------------------------------
 * A-7 and B-9b were derived from the same grep over core plus every vendor plugin, then
 * written down twice: six keys in `TierA7HookKeyScoping::GLOBAL_HOOK_KEYS`, nine in
 * `TierB9bHookKeysDispatched::LITERAL_KEYS`. Nothing compared them, so nothing noticed that
 * A-7 was failing `licenses.deactivate_key` with the words "a prefix nothing dispatches to"
 * while B-9b, over the same plugin in the same run, reported that key as dispatched.
 *
 * The lists are believed **complete today** — verified against every `dispatch()` site in
 * core and in all 69 in-scope plugins. This file is not re-checking that; it is the thing
 * that was missing when the second copy was created, and it is what makes the next edit
 * safe: a key added to one vocabulary and not the other now fails here rather than
 * surfacing as a contradictory pair of findings months later.
 *
 * ---------------------------------------------------------------------------------
 * THE DIRECTION THAT IS NOT MERE EQUALITY
 * ---------------------------------------------------------------------------------
 * Two of these assertions would survive someone re-expanding A-7's alias into a literal
 * array as long as they copied it faithfully — equality is cheap to keep true by accident
 * and cheap to break by accident. The load-bearing ones are behavioural:
 * {@see testA7AcceptsEveryDispatchedLiteralKeyUnderAnUnrelatedModule} runs A-7 over a plugin
 * whose hook table is *built from* B-9b's constant, so a key B-9b gains and A-7 loses fails
 * with that key named; and {@see testDynamicSuffixesAreNotGlobalToA7} pins the half of B-9b's
 * vocabulary A-7 must keep rejecting, because "unifying" the two by folding the per-module
 * suffixes in as well would leave A-7 accepting `anything.activate` from any plugin — green,
 * and worthless.
 *
 * @covers \MyAdmin\Plugins\Testing\Contract\TierA7HookKeyScoping
 * @covers \MyAdmin\Plugins\Testing\Contract\TierB9bHookKeysDispatched
 */
class HookKeyVocabularyTest extends TestCase
{
    /**
     * @return void
     */
    public function testThereIsExactlyOneVocabularyAndBothInspectorsReadIt()
    {
        $this->assertSame(
            TierB9bHookKeysDispatched::LITERAL_KEYS,
            TierA7HookKeyScoping::GLOBAL_HOOK_KEYS,
            'A-7 and B-9b must name the same list, in the same order. If this fails, one of'
                .' them has been given a private copy again.'
        );
        $this->assertNotSame([], TierB9bHookKeysDispatched::LITERAL_KEYS);
        $this->assertSame(
            array_values(array_unique(TierB9bHookKeysDispatched::LITERAL_KEYS)),
            TierB9bHookKeysDispatched::LITERAL_KEYS,
            'the vocabulary must not contain duplicates'
        );
    }

    /**
     * Every literally-dispatched key is global to A-7 — the direction that was broken.
     *
     * @return void
     */
    public function testEveryDispatchedLiteralKeyIsGlobalToA7()
    {
        foreach (TierB9bHookKeysDispatched::LITERAL_KEYS as $key) {
            $this->assertTrue(
                TierA7HookKeyScoping::isGlobalKey($key),
                'B-9b says "'.$key.'" is dispatched verbatim, so A-7 must accept it under any'
                    .' $module — otherwise A-7 fails a key B-9b calls reachable.'
            );
        }
    }

    /**
     * And the converse: A-7 must never wave through a key B-9b would call dead code.
     *
     * @return void
     */
    public function testEveryGlobalKeyIsDispatchedAccordingToB9b()
    {
        $dispatch = new TierB9bHookKeysDispatched();
        foreach (TierA7HookKeyScoping::GLOBAL_HOOK_KEYS as $key) {
            $this->assertTrue(
                $dispatch->isDispatched($key),
                'A-7 accepts "'.$key.'" under any $module, so B-9b must agree something fires'
                    .' it — otherwise A-7 licenses a handler B-9b calls dead.'
            );
        }
    }

    /**
     * The behavioural sweep. The fixture's hook table is generated from B-9b's constant, so
     * this cannot be kept green by editing a list in this file.
     *
     * @return void
     */
    public function testA7AcceptsEveryDispatchedLiteralKeyUnderAnUnrelatedModule()
    {
        $hooks = HookVocabFixtureLiteralSweep::getHooks();
        $this->assertCount(
            count(TierB9bHookKeysDispatched::LITERAL_KEYS),
            $hooks,
            'the sweep must cover the whole vocabulary, or an empty one would pass vacuously'
        );
        $this->assertGreaterThan(1, count($hooks));
        $this->assertArrayNotHasKey(
            HookVocabFixtureLiteralSweep::$module.'.activate',
            $hooks,
            'the fixture\'s $module must share no prefix with the vocabulary, or the sweep'
                .' would be passing on prefix match rather than on globality'
        );

        $inspector = new TierA7HookKeyScoping();
        $findings = $inspector->inspect(new PluginSubject(HookVocabFixtureLiteralSweep::class));

        $reported = [];
        foreach ($findings as $finding) {
            $context = $finding->context();
            $reported[] = isset($context['key']) ? $context['key'] : $finding->message();
        }
        $this->assertSame([], $reported, 'A-7 rejected dispatched literal keys');
    }

    /**
     * The half of B-9b's vocabulary A-7 must keep rejecting. `vps.activate` is dispatched —
     * by the vps module, for the vps module — which is exactly why a plugin declaring some
     * other `$module` may not claim it.
     *
     * @return void
     */
    public function testDynamicSuffixesAreNotGlobalToA7()
    {
        $dispatch = new TierB9bHookKeysDispatched();
        foreach (TierB9bHookKeysDispatched::DYNAMIC_SUFFIXES as $suffix) {
            $key = 'hookvocab.'.$suffix;
            $this->assertTrue($dispatch->isDispatched($key));
            $this->assertFalse(
                TierA7HookKeyScoping::isGlobalKey($key),
                '"'.$key.'" is dispatched only for the "hookvocab" module, so A-7 must still'
                    .' require the listener to declare it. Folding DYNAMIC_SUFFIXES into A-7'
                    .' would disable A-7 entirely.'
            );
        }
        $this->assertNotSame([], TierB9bHookKeysDispatched::DYNAMIC_SUFFIXES);
    }

    /**
     * And the same statement as a verdict rather than a predicate.
     *
     * @return void
     */
    public function testA7StillFailsADynamicallyDispatchedKeyBelongingToAnotherModule()
    {
        $inspector = new TierA7HookKeyScoping();
        $findings = $inspector->inspect(new PluginSubject(HookVocabFixtureForeignModuleKey::class));
        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isFailure());
        $this->assertSame('hookvocabother.activate', $findings[0]->context()['key']);
    }
}

// ---------------------------------------------------------------------------
// Fixtures — `HookVocabFixture` prefix, unique to this file
// ---------------------------------------------------------------------------

/**
 * Registers the entire literal vocabulary under a module that matches none of it.
 */
class HookVocabFixtureLiteralSweep
{
    /** @var string */
    public static $module = 'hookvocab';

    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        $hooks = [];
        foreach (TierB9bHookKeysDispatched::LITERAL_KEYS as $key) {
            $hooks[$key] = [__CLASS__, 'handle'];
        }
        return $hooks;
    }

    /**
     * @param mixed $event
     * @return void
     */
    public static function handle($event)
    {
    }
}

class HookVocabFixtureForeignModuleKey
{
    /** @var string */
    public static $module = 'hookvocab';

    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return [
            'hookvocab.activate' => [__CLASS__, 'handle'],
            'hookvocabother.activate' => [__CLASS__, 'handle'],
        ];
    }

    /**
     * @param mixed $event
     * @return void
     */
    public static function handle($event)
    {
    }
}
