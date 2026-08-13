<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace Tests\MyAdmin\Plugins\Testing\Fixtures;

/**
 * A repo whose `requirementRoot()` hatch is whatever the current test set it to.
 *
 * The value has to come from a static rather than a literal because the roots under test are
 * created on disk at run time — a literal path would either not exist (making every test the
 * "bad root" test) or would have to be committed, and B-10's whole subject is what is
 * actually on disk.
 *
 * Used to drive both misuse directions of the hatch through the real base test case:
 *
 *  - a root that is not a directory at all;
 *  - a root that *is* a directory and that happens to contain the file, which turns a
 *    genuine dangling-path failure into a green run. That is the abuse case gate G2 asks to
 *    be able to audit, and it is the one that leaves no failure message behind to read.
 */
class PctcCaseHatchedRoot extends PctcCasePlain
{
    /**
     * Root the next run should use. Set by the test; never a default worth relying on.
     *
     * @var string
     */
    public static $root = '';

    /**
     * @return string
     */
    protected function pluginClass()
    {
        return PctcHatchedRequirementPlugin::class;
    }

    /**
     * @return string
     */
    protected function requirementRoot()
    {
        return self::$root;
    }
}
