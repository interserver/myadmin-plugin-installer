<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace MyAdmin\Plugins\Testing\Contract;

/**
 * One entry from the Phase 2 assertion catalogue.
 *
 * Implementations must:
 *
 *  - be **side-effect free** with respect to the plugin. Inspectors run back-to-back over
 *    69 plugins in one process during the self-check; one that leaves a constant defined or
 *    a global set changes the result of every inspector after it.
 *  - **never throw** for a defect they are meant to detect. A dangling hook target is a
 *    returned {@see Finding}, not an exception. Throwing is reserved for the inspector
 *    itself being broken, and is what distinguishes an H-bug from a P-bug under D7.
 *  - return `[]` when the plugin satisfies the check. An empty result is the pass signal;
 *    there is no explicit "ok" finding.
 *
 * Inspectors that cannot run — a plugin whose class will not load, an on-disk check with no
 * package directory — must return a {@see Finding::skipped()} rather than an empty array,
 * so the triage matrix can tell "passed" from "never ran".
 */
interface PluginInspector
{
    /**
     * Catalogue id, e.g. "A-7" or "B-9". Used as the matrix column key.
     *
     * @return string
     */
    public function id();

    /**
     * Short human-readable title for the matrix header and failure output.
     *
     * @return string
     */
    public function title();

    /**
     * @param \MyAdmin\Plugins\Testing\Contract\PluginSubject $subject
     * @return array<int,\MyAdmin\Plugins\Testing\Contract\Finding> empty when the plugin passes
     */
    public function inspect(PluginSubject $subject);
}
