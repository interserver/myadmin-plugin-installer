<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace Tests\MyAdmin\Plugins\Testing\Contract;

use MyAdmin\Plugins\Testing\Contract\Finding;
use MyAdmin\Plugins\Testing\Contract\PluginSubject;
use MyAdmin\Plugins\Testing\Contract\TierA1ClassIsConstructible;
use PHPUnit\Framework\TestCase;

/**
 * Fixtures live at the bottom of this file with a `TierA1Fixture` prefix. Every test file in
 * this directory runs in the same process, so a fixture name shared with another file is a
 * fatal redeclaration rather than a test failure.
 *
 * @covers \MyAdmin\Plugins\Testing\Contract\TierA1ClassIsConstructible
 */
class TierA1ClassIsConstructibleTest extends TestCase
{
    /**
     * @param string $class
     * @return array<int,Finding>
     */
    private function inspect($class)
    {
        $inspector = new TierA1ClassIsConstructible();
        return $inspector->inspect(new PluginSubject($class));
    }

    /**
     * @param array<int,Finding> $findings
     * @return Finding
     */
    private function soleFailure(array $findings)
    {
        $this->assertCount(1, $findings, 'expected exactly one finding');
        $this->assertSame('A-1', $findings[0]->assertion());
        $this->assertTrue($findings[0]->isFailure(), 'expected a failure, got '.$findings[0]->severity());
        return $findings[0];
    }

    /**
     * @return void
     */
    public function testReportsItsCatalogueIdentity()
    {
        $inspector = new TierA1ClassIsConstructible();
        $this->assertSame('A-1', $inspector->id());
        $this->assertNotSame('', $inspector->title());
    }

    // -----------------------------------------------------------------------
    // Passing path
    // -----------------------------------------------------------------------

    /**
     * @return void
     */
    public function testConcreteClassWithEmptyConstructorPasses()
    {
        $this->assertSame([], $this->inspect(TierA1FixtureGood::class));
    }

    /**
     * @return void
     */
    public function testConcreteClassWithNoDeclaredConstructorPasses()
    {
        $this->assertSame([], $this->inspect(TierA1FixtureNoConstructor::class));
    }

    /**
     * @return void
     */
    public function testConstructorWithOnlyOptionalArgumentsPasses()
    {
        $this->assertSame([], $this->inspect(TierA1FixtureOptionalArg::class));
    }

    // -----------------------------------------------------------------------
    // Failing path
    // -----------------------------------------------------------------------

    /**
     * @return void
     */
    public function testAbstractClassFails()
    {
        $finding = $this->soleFailure($this->inspect(TierA1FixtureAbstract::class));
        $this->assertStringContainsString('TierA1FixtureAbstract', $finding->message());
        $this->assertStringContainsString('abstract', $finding->message());
    }

    /**
     * An interface answers false to `class_exists()`, so a naive loadability gate would file
     * this as "never ran". It must be a failure.
     *
     * @return void
     */
    public function testInterfaceFailsRatherThanBeingSkipped()
    {
        $finding = $this->soleFailure($this->inspect(TierA1FixtureInterface::class));
        $this->assertStringContainsString('interface', $finding->message());
        $this->assertSame('interface', $finding->context()['kind']);
    }

    /**
     * @return void
     */
    public function testTraitFailsRatherThanBeingSkipped()
    {
        $finding = $this->soleFailure($this->inspect(TierA1FixtureTrait::class));
        $this->assertStringContainsString('trait', $finding->message());
        $this->assertSame('trait', $finding->context()['kind']);
    }

    /**
     * @return void
     */
    public function testConstructorWithARequiredArgumentFails()
    {
        $finding = $this->soleFailure($this->inspect(TierA1FixtureRequiredArg::class));
        $this->assertStringContainsString('requires 1 argument', $finding->message());
        $this->assertSame(1, $finding->context()['required']);
    }

    /**
     * @return void
     */
    public function testPrivateConstructorFails()
    {
        $finding = $this->soleFailure($this->inspect(TierA1FixturePrivateConstructor::class));
        $this->assertStringContainsString('private', $finding->message());
    }

    /**
     * @return void
     */
    public function testThrowingConstructorIsReportedNotPropagated()
    {
        $finding = $this->soleFailure($this->inspect(TierA1FixtureThrowingConstructor::class));
        $this->assertStringContainsString('tier-a1 fixture explosion', $finding->message());
        $this->assertSame('RuntimeException', $finding->context()['exception']);
    }

    // -----------------------------------------------------------------------
    // Buffer discipline (R-8)
    // -----------------------------------------------------------------------

    /**
     * A constructor that prints must not print into the surrounding PHPUnit process.
     *
     * Left unbuffered, `beStrictAboutOutputDuringTests="true"` + `failOnRisky="true"` fails
     * *this* test with `R  This test printed output: …` — no plugin name, no hint that the
     * printing code was the plugin's — which is the report B-15 exists to replace.
     *
     * @return void
     */
    public function testConstructorOutputIsCapturedRatherThanEscaping()
    {
        $level = ob_get_level();
        ob_start();

        $findings = $this->inspect(TierA1FixtureEchoingConstructor::class);

        $escaped = ob_get_clean();

        $this->assertSame('', $escaped, 'the inspector must swallow the constructor output, not re-emit it');
        $this->assertSame($level, ob_get_level(), 'and must leave the buffer stack where it found it');
        $this->assertCount(1, $findings);
    }

    /**
     * Captured is not enough — nobody else in the catalogue constructs the plugin, so if A-1
     * drops the bytes they are reported nowhere. B-15 executes `getSettings()`/`getMenu()`
     * and never `__construct()`.
     *
     * @return void
     */
    public function testEchoingConstructorIsReportedAsAFailure()
    {
        $finding = $this->soleFailure($this->inspect(TierA1FixtureEchoingConstructor::class));

        $this->assertStringContainsString('a1 constructor leak', $finding->message());
        $this->assertStringContainsString('add_output()', $finding->message());
        $this->assertSame('__construct', $finding->context()['site']);
        $this->assertSame(19, $finding->context()['bytes']);
    }

    /**
     * One construction, one finding. Splitting a print-then-throw into two would double-count
     * a single defect; dropping either half would lose evidence, and the printed bytes are
     * the half that names the offending line.
     *
     * @return void
     */
    public function testConstructorThatPrintsAndThenThrowsReportsBothInOneFinding()
    {
        $finding = $this->soleFailure($this->inspect(TierA1FixtureEchoThenThrowConstructor::class));

        $this->assertStringContainsString('a1 printed first', $finding->message());
        $this->assertStringContainsString('a1 then exploded', $finding->message());
        $this->assertSame('RuntimeException', $finding->context()['exception']);
        $this->assertSame(16, $finding->context()['bytes']);
    }

    // -----------------------------------------------------------------------
    // Skip path
    // -----------------------------------------------------------------------

    /**
     * @return void
     */
    public function testMissingClassIsSkippedNotPassed()
    {
        $findings = $this->inspect('Tests\\MyAdmin\\Plugins\\Testing\\Contract\\TierA1FixtureThatDoesNotExist');
        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped());
        $this->assertFalse($findings[0]->isFailure());
        $this->assertSame('A-1', $findings[0]->assertion());
    }
}

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

class TierA1FixtureGood
{
    public function __construct()
    {
    }
}

class TierA1FixtureNoConstructor
{
}

class TierA1FixtureOptionalArg
{
    /** @var string */
    public $flavour;

    /**
     * @param string $flavour
     */
    public function __construct($flavour = 'default')
    {
        $this->flavour = $flavour;
    }
}

abstract class TierA1FixtureAbstract
{
}

interface TierA1FixtureInterface
{
}

trait TierA1FixtureTrait
{
}

class TierA1FixtureRequiredArg
{
    /** @var mixed */
    public $needed;

    /**
     * @param mixed $needed
     */
    public function __construct($needed)
    {
        $this->needed = $needed;
    }
}

class TierA1FixturePrivateConstructor
{
    private function __construct()
    {
    }
}

class TierA1FixtureThrowingConstructor
{
    public function __construct()
    {
        throw new \RuntimeException('tier-a1 fixture explosion');
    }
}

/**
 * A constructor with a leftover debug print. In a real request this runs before the theme
 * has emitted a byte, so it lands above `<!DOCTYPE html>`.
 */
class TierA1FixtureEchoingConstructor
{
    public function __construct()
    {
        echo 'a1 constructor leak';
    }
}

/**
 * Prints and then fatals — both facts belong in the one report.
 */
class TierA1FixtureEchoThenThrowConstructor
{
    public function __construct()
    {
        echo 'a1 printed first';
        throw new \RuntimeException('a1 then exploded');
    }
}
