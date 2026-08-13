<?php

namespace Tests\MyAdmin\Plugins\Testing\Scaffold;

use MyAdmin\Plugins\Testing\Scaffold\PluginFacts;
use MyAdmin\Plugins\Testing\Scaffold\SkillDoc;
use PHPUnit\Framework\TestCase;

/**
 * ---------------------------------------------------------------------------------
 * WHY A GENERATED DOC IS WORTH TESTING AT ALL
 * ---------------------------------------------------------------------------------
 * This file is prose, and most prose does not need a test. This prose does, for one reason:
 * it is the thing a model reads *before* it reads any code, so a mistake in it does not
 * produce a red build — it produces a session that confidently undoes the harness and leaves
 * a green build behind. The assertions below therefore pin the handful of statements whose
 * absence would let that happen, and nothing about wording.
 *
 * @covers \MyAdmin\Plugins\Testing\Scaffold\SkillDoc
 */
class SkillDocTest extends TestCase
{
    /**
     * @param string      $type
     * @param string|null $module
     * @return \MyAdmin\Plugins\Testing\Scaffold\PluginFacts
     */
    private function facts($type = 'service', $module = 'licenses')
    {
        return new PluginFacts(
            'Detain\\MyAdminThing\\Plugin',
            'Detain\\MyAdminThing\\Tests',
            'Thing',
            $type,
            $module,
            ['licenses.activate']
        );
    }

    /**
     * The frontmatter is not decoration: a skill with no `name`/`description` is not
     * discoverable, so the whole file may as well not exist.
     */
    public function testItOpensWithUsableFrontmatter(): void
    {
        $doc = (new SkillDoc())->render($this->facts());

        $this->assertStringStartsWith("---\nname: plugin-contract-tests\n", $doc);
        $this->assertStringContainsString("\ndescription: ", $doc);
        // Opens on the very first line, so only the closing fence is newline-prefixed.
        $this->assertSame(1, substr_count($doc, "\n---\n"), 'frontmatter must close exactly once');
    }

    /**
     * The correction this whole sweep exists to make. If the generated skill does not say it,
     * the next session reads the package's older skill and writes reflection assertions again.
     */
    public function testItForbidsReflectionOnlyTests(): void
    {
        $doc = (new SkillDoc())->render($this->facts());

        $this->assertStringContainsString('Never write a reflection-only test', $doc);
        $this->assertStringContainsString('passes whether or not the handler works', $doc);
    }

    /**
     * The fleet's standing rule, and the one most likely to be broken by a model that has
     * just been told the older tests are outdated.
     */
    public function testItStatesTheConversionIsAdditive(): void
    {
        $doc = (new SkillDoc())->render($this->facts());

        $this->assertStringContainsString('Never delete an existing test', $doc);
        $this->assertStringContainsString('strictly additive', $doc);
    }

    public function testItSaysTheGeneratedTestIsNeverHandEdited(): void
    {
        $doc = (new SkillDoc())->render($this->facts());

        $this->assertStringContainsString('Never hand-write or hand-edit `tests/ContractTest.php`', $doc);
        $this->assertStringContainsString('composer myadmin:scaffold-tests', $doc);
    }

    /**
     * The P-bug/H-bug split decides which repository gets touched, which makes it the most
     * expensive thing in this file to get wrong.
     */
    public function testItCarriesTheTriageTable(): void
    {
        $doc = (new SkillDoc())->render($this->facts());

        $this->assertStringContainsString('**P-bug**', $doc);
        $this->assertStringContainsString('**H-bug**', $doc);
        $this->assertStringContainsString('Suspect the harness first', $doc);
    }

    public function testItCarriesTheThreeOrderingRules(): void
    {
        $doc = (new SkillDoc())->render($this->facts());

        $this->assertStringContainsString('primeConstants()', $doc);
        $this->assertStringContainsString('TierA5HooksAreIdempotent::hookTable()', $doc);
        $this->assertStringContainsString('evaluated exactly once', $doc);
        $this->assertStringContainsString('@runTestsInSeparateProcesses', $doc);
    }

    public function testAServicePackageGetsTheLifecycleSection(): void
    {
        $doc = (new SkillDoc())->render($this->facts('service'));

        $this->assertStringContainsString('## Service lifecycle', $doc);
        $this->assertStringContainsString('getChangeIp()', $doc);
        $this->assertStringContainsString('type=service', $doc);
    }

    /**
     * A non-service package must not be told about assertions that will never run for it —
     * that is how a session ends up "fixing" a lifecycle handler the package does not have.
     */
    public function testANonServicePackageIsNotToldAboutLifecycleAssertions(): void
    {
        $doc = (new SkillDoc())->render($this->facts('plugin', null));

        $this->assertStringNotContainsString('## Service lifecycle', $doc);
        $this->assertStringNotContainsString('getChangeIp()', $doc);
    }

    public function testItPinsThisPackagesModule(): void
    {
        $doc = (new SkillDoc())->render($this->facts('service', 'vps'));

        $this->assertStringContainsString('`$module = \'vps\'`', $doc);
    }

    /**
     * Absent `$module` is correct for all 27 type=plugin packages and is asserted
     * bidirectionally, so the skill has to say so rather than leave it looking like an
     * omission somebody should fix.
     */
    public function testAModulelessPackageIsToldThatIsCorrect(): void
    {
        $doc = (new SkillDoc())->render($this->facts('plugin', null));

        $this->assertStringContainsString('declares no `$module`, which is correct', $doc);
    }

    public function testItNamesThePackagesOwnPluginClass(): void
    {
        $this->assertStringContainsString(
            '`Detain\\MyAdminThing\\Plugin`',
            (new SkillDoc())->render($this->facts())
        );
    }

    /**
     * Same reason as the contract test: a Windows checkout with core.autocrlf would otherwise
     * commit CRLF into a repository whose every other file is LF.
     */
    public function testTheOutputIsLineFeedTerminatedWhateverTheHostDoes(): void
    {
        $this->assertStringNotContainsString("\r", (new SkillDoc())->render($this->facts()));
        $this->assertStringNotContainsString("\r", (new SkillDoc())->supersedeNotice());
    }

    /**
     * The notice is prepended to 58 hand-written files by a sweep that will be run again.
     * Without a machine-detectable marker, "has this one been done?" becomes a prose diff.
     */
    public function testTheNoticeIsIdempotentlyDetectable(): void
    {
        $doc = new SkillDoc();
        $notice = $doc->supersedeNotice();

        $this->assertTrue($doc->isAmended($notice));
        $this->assertTrue($doc->isAmended("---\nname: x\n---\n".$notice.'body'));
        $this->assertFalse($doc->isAmended("---\nname: x\n---\nbody"));
    }

    /**
     * The notice narrows an existing file; it does not condemn it. A notice that read as
     * "ignore everything below" would throw away the per-package knowledge that is the only
     * reason those files still exist.
     */
    public function testTheNoticePreservesTheFileItAmends(): void
    {
        $notice = (new SkillDoc())->supersedeNotice();

        $this->assertStringContainsString('Everything else in this file is still accurate', $notice);
        $this->assertStringContainsString('Nothing below has been removed', $notice);
        $this->assertStringContainsString('plugin-contract-tests', $notice);
    }

    /**
     * Every line has to be quoted, or markdown ends the blockquote at the first unquoted line
     * and the rest of the notice merges into the package's own prose.
     */
    public function testTheNoticeIsAWellFormedBlockquote(): void
    {
        $lines = explode("\n", trim((new SkillDoc())->supersedeNotice()));
        array_shift($lines); // the HTML marker comment

        foreach ($lines as $line) {
            $this->assertStringStartsWith('>', $line, 'unquoted line would break out of the blockquote: '.$line);
        }
    }

    /**
     * `CLAUDE.md` is the always-on half of the correction, and it is the half that has to earn
     * its place: it costs context on every turn, so it says the three things whose absence
     * lets a session undo the harness, and stops.
     */
    public function testTheClaudeMdSectionCarriesTheCorrectionAndPointsAtTheSkill(): void
    {
        $section = (new SkillDoc())->claudeMdSection();

        $this->assertStringStartsWith('## Plugin contract harness', $section);
        $this->assertStringContainsString('do not write reflection-only tests', $section);
        $this->assertStringContainsString('generated', $section);
        $this->assertStringContainsString('additive', $section);
        $this->assertStringContainsString('plugin-contract-tests', $section);
        $this->assertStringNotContainsString("\r", $section);
    }

    /**
     * It goes into 70 files that are already long. A section that grew to skill-length would
     * be paid for on every turn in every session.
     */
    public function testTheClaudeMdSectionStaysShort(): void
    {
        $this->assertLessThan(
            1600,
            strlen((new SkillDoc())->claudeMdSection()),
            'CLAUDE.md is loaded unprompted every session; this is not the place for the full workflow'
        );
    }

    /**
     * A model choosing between skills reads descriptions, not bodies. An amendment that lives
     * only in the body arrives after the choice has already been made.
     */
    public function testTheDescriptionSuffixRoutesToTheNewSkill(): void
    {
        $suffix = (new SkillDoc())->descriptionSuffix();

        $this->assertStringContainsString('plugin-contract-tests skill instead', $suffix);
        $this->assertStringStartsWith(' ', $suffix, 'it is appended to an existing sentence');
        $this->assertStringNotContainsString("\n", $suffix, 'frontmatter descriptions are a single line');
    }

    /**
     * The one that was learned the expensive way.
     *
     * A skill `description:` is an unquoted YAML scalar, so a single `: ` inside it makes the
     * whole frontmatter block unparseable — and an unparseable skill is not a broken skill,
     * it is an *invisible* one. Nothing goes red. A first draft of this suffix opened with
     * `NOTE: ` and took the frontmatter of 83 previously-valid skills with it.
     */
    public function testTheDescriptionSuffixCannotBreakUnquotedYaml(): void
    {
        $suffix = (new SkillDoc())->descriptionSuffix();

        $this->assertStringNotContainsString(': ', $suffix, 'a colon-space ends the YAML scalar');
        $this->assertStringNotContainsString(':'."\t", $suffix);
        $this->assertStringNotContainsString('#', $suffix, 'a hash starts a YAML comment');
    }

    /**
     * The generated skill's own description is subject to exactly the same rule, and it is
     * assembled from measured values, so it has to hold for a real one rather than in
     * principle.
     */
    public function testTheGeneratedDescriptionIsParseableYaml(): void
    {
        $doc = (new SkillDoc())->render($this->facts());
        $close = strpos($doc, "\n---\n");
        $front = substr($doc, 4, $close - 3);

        foreach (explode("\n", trim($front)) as $line) {
            if ($line === '') {
                continue;
            }
            $key = substr($line, 0, (int)strpos($line, ':'));
            $value = substr($line, strlen($key) + 2);
            $this->assertNotFalse(strpos($line, ': '), 'every frontmatter line is a mapping: '.$line);
            $this->assertStringNotContainsString(': ', $value, 'unquoted scalar broken by a colon-space: '.$line);
        }
    }
}
