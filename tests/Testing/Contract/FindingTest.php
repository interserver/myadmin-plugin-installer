<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace Tests\MyAdmin\Plugins\Testing\Contract;

use MyAdmin\Plugins\Testing\Contract\Finding;
use PHPUnit\Framework\TestCase;

/**
 * Pins the severity vocabulary itself.
 *
 * ---------------------------------------------------------------------------------
 * WHY THIS FILE EXISTS AT ALL
 * ---------------------------------------------------------------------------------
 * `Finding` had no test of its own for two revisions of this vocabulary, and both times the
 * defect that cost the most was the same shape: a severity that existed but that no consumer
 * could ask about. `NOTICE` shipped without an `isNotice()`, so every consumer's
 * `if (failure) … elseif (skipped) …` chain dropped it into the `else` and a lone notice
 * produced a run indistinguishable from one that found nothing. R-4 adds a fourth severity
 * with exactly the same exposure.
 *
 * So the assertions below are not "the constructor stores what it was given". They are:
 * every severity has a factory, every severity has a predicate, and the predicates are
 * mutually exclusive — which is what makes "the next consumer will handle it" true rather
 * than hopeful.
 *
 * @covers \MyAdmin\Plugins\Testing\Contract\Finding
 */
class FindingTest extends TestCase
{
    /**
     * Every severity the vocabulary has, with the factory that makes one and the predicate
     * that recognises it.
     *
     * @return array<string,array{0:string,1:string,2:string}>
     */
    public static function severities()
    {
        return [
            'failure' => [Finding::FAILURE, 'failure', 'isFailure'],
            'notice' => [Finding::NOTICE, 'notice', 'isNotice'],
            'skipped' => [Finding::SKIPPED, 'skipped', 'isSkipped'],
            'not applicable' => [Finding::NOT_APPLICABLE, 'notApplicable', 'isNotApplicable'],
        ];
    }

    /**
     * @dataProvider severities
     * @param string $severity
     * @param string $factory
     * @param string $predicate
     * @return void
     */
    public function testEverySeverityHasAFactoryAndAPredicateThatAgree($severity, $factory, $predicate)
    {
        $finding = Finding::$factory('X-1', 'because', ['key' => 'value']);

        $this->assertSame($severity, $finding->severity());
        $this->assertTrue($finding->$predicate(), $factory.'() must be recognised by '.$predicate.'()');
        $this->assertSame('X-1', $finding->assertion());
        $this->assertSame('because', $finding->message());
        $this->assertSame(['key' => 'value'], $finding->context());
    }

    /**
     * The mutual-exclusion assertion is the one that matters. Consumers are written as a
     * chain of predicates, so two predicates answering true for one finding would put the
     * same observation in two buckets, and none answering true would put it in neither —
     * which is precisely how notices used to vanish.
     *
     * @dataProvider severities
     * @param string $severity
     * @param string $factory
     * @param string $predicate
     * @return void
     */
    public function testExactlyOnePredicateAnswersForAnyFinding($severity, $factory, $predicate)
    {
        $finding = Finding::$factory('X-1', 'because');

        $answered = [];
        foreach (self::severities() as $candidate) {
            if ($finding->{$candidate[2]}()) {
                $answered[] = $candidate[2];
            }
        }

        $this->assertSame([$predicate], $answered);
    }

    /**
     * The four severity strings have to stay distinct: the fleet matrix keys its cell rule on
     * them, and two constants sharing a value would silently merge two states.
     *
     * @return void
     */
    public function testTheSeverityStringsAreDistinct()
    {
        $values = array_column(array_values(self::severities()), 0);

        $this->assertSame($values, array_values(array_unique($values)));
        $this->assertCount(4, $values);
    }

    /**
     * A not-applicable finding carries its reason exactly as a skip does. The whole content of
     * an `o` cell is *why* nothing arose, and "registers no routes" and "declares no
     * function.requirements handler" are different facts wearing the same glyph.
     *
     * @return void
     */
    public function testANotApplicableFindingSurvivesRenderingWithItsReasonAndContext()
    {
        $finding = Finding::notApplicable('B-13', 'plugin declares no getMenu()', ['class' => 'Foo\\Plugin']);

        $this->assertSame(
            "[B-13] plugin declares no getMenu() (class='Foo\\\\Plugin')",
            $finding->describe()
        );
        $this->assertSame(
            [
                'assertion' => 'B-13',
                'severity' => Finding::NOT_APPLICABLE,
                'message' => 'plugin declares no getMenu()',
                'context' => ['class' => 'Foo\\Plugin'],
            ],
            $finding->toArray()
        );
    }
}
