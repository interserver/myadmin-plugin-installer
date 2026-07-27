<?php

namespace Tests\MyAdmin\Plugins\Support;

use ReflectionClass;

/**
 * Helper for asserting on a class's CODE rather than its prose.
 *
 * Several tests check that removed scaffolding has really gone. A naive
 * file_get_contents() scan is useless for that, because the replacement docblocks describe
 * what was removed and therefore contain the very strings being searched for. Stripping
 * comments first makes the assertion mean what it says.
 */
trait SourceInspection
{
    /**
     * Returns a class's source with all comments and docblocks removed.
     *
     * @param string $class fully-qualified class name
     * @return string comment-free PHP source
     */
    protected function codeOf(string $class): string
    {
        $file = (new ReflectionClass($class))->getFileName();
        $code = '';
        foreach (token_get_all((string)file_get_contents($file)) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $code .= $token[1];
            } else {
                $code .= $token;
            }
        }
        return $code;
    }
}
