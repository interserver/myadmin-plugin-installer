<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace MyAdmin\Plugins\Testing\Contract;

use ReflectionClass;

/**
 * The plugin under inspection, plus the per-repo overrides that apply to it.
 *
 * Every inspector receives one of these rather than a bare class-string, so that the
 * reflection and the override resolution happen once instead of fifteen times, and so an
 * inspector cannot quietly disagree with another about what `$module` or `$type` is.
 *
 * ---------------------------------------------------------------------------------
 * ON THE OVERRIDES
 * ---------------------------------------------------------------------------------
 * Gate G2 requires that no assertion is weakened just to make a plugin pass, and that every
 * escape hatch used is logged. That is enforceable only if the hatches are *data* rather
 * than scattered `if` statements — {@see overridesInUse()} reports exactly which ones a
 * repo set, so the triage matrix can list them without trusting anyone to self-report.
 *
 * Deliberately holds no PHPUnit types. The Phase 2 self-check runs these inspectors over
 * all 69 plugins outside a PHPUnit process.
 *
 * Reading a static property is not the trivial operation it looks like — see
 * {@see staticProperty()} before touching it.
 */
class PluginSubject
{
    /** @var class-string */
    private $pluginClass;

    /** @var string|null expected `$type`, or null to accept any valid one */
    private $expectedType;

    /**
     * Filesystem root that `getRequirements()` sources must resolve under, or null to skip
     * the path check entirely (Tier-B-10 escape hatch).
     *
     * @var string|null
     */
    private $requirementRoot;

    /** @var array<string,int> service defines to seed before executing plugin code */
    private $serviceDefines;

    /** @var array<string,mixed> constants to force before the plugin class is touched */
    private $constantOverrides;

    /** @var array<string,bool> which overrides were explicitly set by the repo */
    private $explicit = [];

    /** @var ReflectionClass|null */
    private $reflection;

    /**
     * @param class-string        $pluginClass
     * @param array<string,mixed> $options expectedType, requirementRoot, serviceDefines,
     *                                     constantOverrides — omit to take the default
     */
    public function __construct($pluginClass, array $options = [])
    {
        $this->pluginClass = (string)$pluginClass;

        foreach (['expectedType', 'requirementRoot', 'serviceDefines', 'constantOverrides'] as $key) {
            $this->explicit[$key] = array_key_exists($key, $options);
        }

        $this->expectedType = isset($options['expectedType']) ? (string)$options['expectedType'] : null;
        $this->requirementRoot = array_key_exists('requirementRoot', $options)
            ? ($options['requirementRoot'] === null ? null : (string)$options['requirementRoot'])
            : null;
        $this->serviceDefines = isset($options['serviceDefines']) && is_array($options['serviceDefines'])
            ? $options['serviceDefines']
            : [];
        $this->constantOverrides = isset($options['constantOverrides']) && is_array($options['constantOverrides'])
            ? $options['constantOverrides']
            : [];
    }

    /**
     * @return class-string
     */
    public function pluginClass()
    {
        return $this->pluginClass;
    }

    /**
     * @return bool
     */
    public function isLoadable()
    {
        return class_exists($this->pluginClass);
    }

    /**
     * @return ReflectionClass
     */
    public function reflection()
    {
        if ($this->reflection === null) {
            $this->reflection = new ReflectionClass($this->pluginClass);
        }
        return $this->reflection;
    }

    /**
     * Value of a `public static` property, or null when it is absent.
     *
     * Absence and a null value are deliberately not distinguished here — callers that need
     * the difference should ask {@see hasStaticProperty()}. Most do not: a missing `$module`
     * and a `$module = null` mean the same thing to the hook-scoping rule (§0.7).
     *
     * ---------------------------------------------------------------------------------
     * WHY THIS SWALLOWS A Throwable, AND WHY THERE IS A SOURCE FALLBACK
     * ---------------------------------------------------------------------------------
     * **PHP evaluates every static property initializer of a class on the first access to
     * any one of its statics** — not just the one being read. So a class whose `$type` is a
     * plain string still fatals on `$type` if some *other* static's initializer references
     * a constant that is not defined yet. {@see \Tests\MyAdmin\Plugins\Testing\ConstantOrderingTest}
     * pins that semantic; this method is what makes it survivable.
     *
     * Ten fleet packages have exactly that shape — the `*-module` ones: backups, domains,
     * floating-ips, licenses, mail, quickservers, servers, ssl, vps, webhosting. Each
     * declares
     *
     *     public static $settings = ['REPEAT_BILLING_METHOD' => PRORATE_BILLING, ...];
     *
     * (licenses uses `NORMAL_BILLING`; the shape is identical). Reading the unrelated
     * `$type` on `Detain\MyAdminWebhosting\Plugin` with constants unprimed therefore throws
     * `Error: Undefined constant "Detain\MyAdminWebhosting\PRORATE_BILLING"`.
     *
     * {@see PluginInspector} requires an inspector never to throw for a defect it detects.
     * Letting that `Error` escape made the requirement impossible to honour: a perfectly
     * compliant inspector crashed merely by asking what `$type` was, and the crash was an
     * H-bug attributed to whichever plugin happened to be under inspection. Hence the catch.
     *
     * The catch alone would be worse than the crash, because a swallowed error returning
     * null is indistinguishable from "the property is not declared" — a silent downgrade
     * from *fatal* to *invisible*. Two things prevent that:
     *
     *  - the value is recovered from the declaration's own source text, which is reliable
     *    for exactly the metadata inspectors need: all 69 fleet plugins declare `$name`,
     *    `$description`, `$help`, `$type` and `$module` as plain scalar literals. Array
     *    initializers are **not** evaluated from source and yield null — recovering
     *    `$settings` would mean reimplementing constant resolution, which is the very
     *    thing that failed;
     *  - {@see staticPropertyError()} reports the swallowed message, and
     *    {@see hasStaticProperty()} keeps answering true, so "declared but unevaluable"
     *    stays distinguishable from "absent".
     *
     * Do not "simplify" the fallback away. Without it, `type()` and `module()` are
     * unusable on one seventh of the fleet.
     *
     * @param string $name
     * @return mixed
     */
    public function staticProperty($name)
    {
        $evaluated = $this->evaluateStatic($name);
        return $evaluated['value'];
    }

    /**
     * The message from the Throwable {@see staticProperty()} swallowed, or null when the
     * property evaluated cleanly or is not declared at all.
     *
     * An inspector that reads a static property SHOULD consult this and surface a non-null
     * result as a {@see Finding} — a `Finding::skipped()` when the check cannot proceed
     * without the value, or a `Finding::failure()` when an unevaluable initializer is
     * itself the defect. What it must **not** do is treat the null from `staticProperty()`
     * as "absent" and pass silently; that is how a harness bug gets reported as fleet-wide
     * compliance.
     *
     * Deliberately re-evaluates rather than caching. Constants are process-global, so a
     * property that throws before `Bootstrap::init()` primes them succeeds afterwards —
     * verified — and a cached "it threw" would outlive the condition that caused it.
     *
     * @param string $name
     * @return string|null
     */
    public function staticPropertyError($name)
    {
        $evaluated = $this->evaluateStatic($name);
        return $evaluated['error'];
    }

    /**
     * One guarded read of a static property.
     *
     * @param string $name
     * @return array{value:mixed,error:string|null}
     */
    private function evaluateStatic($name)
    {
        if (!$this->hasStaticProperty($name)) {
            return ['value' => null, 'error' => null];
        }
        $property = $this->reflection()->getProperty($name);
        $property->setAccessible(true);
        try {
            return ['value' => $property->getValue(), 'error' => null];
        } catch (\Throwable $e) {
            return ['value' => $this->literalFromSource($name), 'error' => $e->getMessage()];
        }
    }

    /**
     * Recovers a scalar literal initializer by tokenising the file that declares it.
     *
     * Reads the **declaring** class's file rather than the subject's, so an inherited
     * property is looked for where it actually lives. Reflection metadata — `hasProperty()`,
     * `isStatic()`, `getDeclaringClass()`, `getFileName()` — never evaluates initializers,
     * so none of this can re-trigger the failure it is recovering from.
     *
     * @param string $name
     * @return mixed null when the file is unreadable or the initializer is not a scalar literal
     */
    private function literalFromSource($name)
    {
        $declaring = $this->reflection()->getProperty($name)->getDeclaringClass();
        $file = $declaring->getFileName();
        if ($file === false || !is_file($file) || !is_readable($file)) {
            return null;
        }
        $start = $declaring->getStartLine();
        $end = $declaring->getEndLine();
        return self::scanLiteral(
            (string)file_get_contents($file),
            $name,
            $start === false ? null : $start,
            $end === false ? null : $end
        );
    }

    /**
     * The token scan itself, over a source string.
     *
     * Public so it can be unit-tested without needing a class whose initializer actually
     * throws. Matches only a declaration carrying **both** a visibility modifier and
     * `static`, which is what excludes a method-local `static $module = ...;` and, more
     * to the point, the plain local `$module = get_module_name('default');` that
     * `myadmin-icontact-mailinglist` assigns at line 84 of its `Plugin.php`.
     *
     * The line bounds are not optional in practice, only in signature: a file holding two
     * classes that both declare `$type` would otherwise answer with whichever came first.
     * Every fleet `Plugin.php` holds exactly one class, so nothing in the fleet exposes it
     * — the harness's own test fixtures did, immediately.
     *
     * @param string   $source    PHP source, including the opening tag
     * @param string   $name      property name, without the leading `$`
     * @param int|null $startLine first line of the declaring class, or null for the whole file
     * @param int|null $endLine   last line of the declaring class, or null for the whole file
     * @return mixed the literal's value, or null when there is no recoverable literal
     */
    public static function scanLiteral($source, $name, $startLine = null, $endLine = null)
    {
        // Punctuation tokens carry no line number of their own, so the line is tracked
        // across the whole stream. Dropping `$type` but keeping the `=` and `;` around it
        // would leave adjacency checks reading tokens from a class that was filtered out.
        $tokens = [];
        $line = 1;
        foreach (token_get_all($source) as $token) {
            $text = $token;
            if (is_array($token)) {
                $line = $token[2];
                $text = $token[1];
            }
            $skip = is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
            if (!$skip && self::withinBounds($line, $startLine, $endLine)) {
                $tokens[] = $token;
            }
            $line += substr_count($text, "\n");
        }

        $variable = '$'.$name;
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_VARIABLE || $token[1] !== $variable) {
                continue;
            }
            if (!self::declaredStatic($tokens, $i)) {
                continue;
            }
            if ($i + 1 >= $count || $tokens[$i + 1] !== '=') {
                // `public static $x;` — declared, but with no initializer to recover.
                return null;
            }
            $literal = self::literalAt($tokens, $i + 2);
            return $literal === null ? null : $literal['value'];
        }
        return null;
    }

    /**
     * @param int      $line
     * @param int|null $startLine
     * @param int|null $endLine
     * @return bool
     */
    private static function withinBounds($line, $startLine, $endLine)
    {
        if ($startLine !== null && $line < $startLine) {
            return false;
        }
        return $endLine === null || $line <= $endLine;
    }

    /**
     * Whether the run of modifiers immediately before a variable declares it as a
     * visibility-qualified static property.
     *
     * @param array<int,array{0:int,1:string}|string> $tokens
     * @param int                                     $index position of the T_VARIABLE
     * @return bool
     */
    private static function declaredStatic(array $tokens, $index)
    {
        $visibility = [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_VAR];
        $modifiers = array_merge($visibility, [T_STATIC, T_FINAL]);
        // PHP 8.1 added `readonly`. Resolved at runtime because this package still
        // supports 7.4, where the token does not exist.
        if (defined('T_READONLY')) {
            $modifiers[] = constant('T_READONLY');
        }

        $sawStatic = false;
        $sawVisibility = false;
        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];
            if (!is_array($token) || !in_array($token[0], $modifiers, true)) {
                break;
            }
            if ($token[0] === T_STATIC) {
                $sawStatic = true;
            }
            if (in_array($token[0], $visibility, true)) {
                $sawVisibility = true;
            }
        }
        return $sawStatic && $sawVisibility;
    }

    /**
     * Reads a scalar literal starting at `$index`, or null when what is there is anything
     * else — an array, a constant reference, a concatenation.
     *
     * The terminator check is what makes "anything else" reliable: `'a' . 'b'` and `1 + 2`
     * both start with a valid literal, and only the token *after* it distinguishes them
     * from a complete initializer.
     *
     * @param array<int,array{0:int,1:string}|string> $tokens
     * @param int                                     $index
     * @return array{value:mixed}|null wrapped so a recovered null is not mistaken for failure
     */
    private static function literalAt(array $tokens, $index)
    {
        $count = count($tokens);
        if ($index >= $count) {
            return null;
        }

        $negated = false;
        if ($tokens[$index] === '-' || $tokens[$index] === '+') {
            $negated = $tokens[$index] === '-';
            $index++;
            if ($index >= $count) {
                return null;
            }
        }

        $token = $tokens[$index];
        if (!is_array($token)) {
            return null;
        }

        $value = null;
        if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
            if ($negated) {
                return null;
            }
            $value = self::unquote($token[1]);
            if ($value === null) {
                return null;
            }
        } elseif ($token[0] === T_LNUMBER) {
            // Base 0 so hex/octal/binary literals read correctly; `_` separators stripped.
            $value = intval(str_replace('_', '', $token[1]), 0);
            $value = $negated ? -$value : $value;
        } elseif ($token[0] === T_DNUMBER) {
            $value = (float)str_replace('_', '', $token[1]);
            $value = $negated ? -$value : $value;
        } elseif ($token[0] === T_STRING) {
            if ($negated) {
                return null;
            }
            $keyword = strtolower($token[1]);
            if ($keyword === 'true') {
                $value = true;
            } elseif ($keyword === 'false') {
                $value = false;
            } elseif ($keyword === 'null') {
                $value = null;
            } else {
                // A bare constant — the exact thing that could not be evaluated.
                return null;
            }
        } else {
            return null;
        }

        $next = $index + 1 < $count ? $tokens[$index + 1] : null;
        if ($next !== ';' && $next !== ',') {
            return null;
        }
        return ['value' => $value];
    }

    /**
     * Unescapes a `T_CONSTANT_ENCAPSED_STRING`.
     *
     * That token type guarantees the string contains no interpolation, so a single
     * left-to-right pass is faithful. An escape this does not model — `\101`, `\x41`,
     * `\u{1F600}` — returns null rather than a plausible-looking wrong string: a silently
     * mangled `$module` would misroute every hook-scoping verdict downstream.
     *
     * @param string $text the raw token, quotes included
     * @return string|null
     */
    private static function unquote($text)
    {
        $quote = substr($text, 0, 1);
        $inner = (string)substr($text, 1, -1);

        if ($quote === "'") {
            // Single quotes define exactly two escapes.
            return strtr($inner, ['\\\\' => '\\', "\\'" => "'"]);
        }

        $escapes = [
            'n' => "\n", 'r' => "\r", 't' => "\t", 'v' => "\v",
            'e' => "\033", 'f' => "\f", '\\' => '\\', '$' => '$', '"' => '"',
        ];
        $out = '';
        $length = strlen($inner);
        for ($i = 0; $i < $length; $i++) {
            if ($inner[$i] !== '\\') {
                $out .= $inner[$i];
                continue;
            }
            if ($i + 1 >= $length) {
                return null;
            }
            $next = $inner[$i + 1];
            if (!isset($escapes[$next])) {
                return null;
            }
            $out .= $escapes[$next];
            $i++;
        }
        return $out;
    }

    /**
     * Whether the class declares the property as static.
     *
     * Answers from reflection metadata only, which never evaluates an initializer — so it
     * keeps telling the truth for the ten packages whose initializers throw. That matters:
     * it is the only thing separating "declared but unevaluable" from "absent" once
     * {@see staticProperty()} has returned null for both.
     *
     * @param string $name
     * @return bool
     */
    public function hasStaticProperty($name)
    {
        return $this->reflection()->hasProperty($name)
            && $this->reflection()->getProperty($name)->isStatic();
    }

    /**
     * The plugin's declared module, or null/'' when it declares none.
     *
     * @return string|null
     */
    public function module()
    {
        $module = $this->staticProperty('module');
        return is_string($module) ? $module : null;
    }

    /**
     * The plugin's declared type.
     *
     * @return string|null
     */
    public function type()
    {
        $type = $this->staticProperty('type');
        return is_string($type) ? $type : null;
    }

    /**
     * @return string|null
     */
    public function expectedType()
    {
        return $this->expectedType;
    }

    /**
     * @return string|null
     */
    public function requirementRoot()
    {
        return $this->requirementRoot;
    }

    /**
     * Whether the repo explicitly opted out of the Tier-B-10 path check.
     *
     * Distinct from "requirementRoot happens to be null": an unset root means the check
     * falls back to a default, while an explicit null is a logged opt-out.
     *
     * @return bool
     */
    public function skipsRequirementCheck()
    {
        return $this->explicit['requirementRoot'] && $this->requirementRoot === null;
    }

    /**
     * @return array<string,int>
     */
    public function serviceDefines()
    {
        return $this->serviceDefines;
    }

    /**
     * @return array<string,mixed>
     */
    public function constantOverrides()
    {
        return $this->constantOverrides;
    }

    /**
     * Names of the overrides this repo set explicitly, for the G2 escape-hatch log.
     *
     * @return array<int,string>
     */
    public function overridesInUse()
    {
        $used = [];
        foreach ($this->explicit as $name => $wasSet) {
            if ($wasSet) {
                $used[] = $name;
            }
        }
        return $used;
    }

    /**
     * Directory the plugin class lives in — the anchor for on-disk checks (templates,
     * requirement paths) that must not depend on the current working directory.
     *
     * @return string|null
     */
    public function packageDir()
    {
        $file = $this->reflection()->getFileName();
        if ($file === false) {
            return null;
        }
        return dirname(dirname($file));
    }
}
