<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace MyAdmin\Plugins\Testing\Contract;

use Throwable;

/**
 * Tier-B-9b — **the hook key is actually dispatched by somebody**.
 *
 * Tier-B-9 asks whether a hook's *target* resolves. This asks the other half of the same
 * question: whether anything ever fires the *key*. Both halves have to hold for a listener to
 * run, and they fail independently.
 *
 * They also **fail differently**, which is worth stating because the two were once described
 * in the same words and the shared wording was wrong for B-9. A key nothing dispatches is
 * genuinely silent: the target resolves, `Closure::fromCallable()` succeeds, the listener is
 * registered, and the event is simply never fired. Nothing throws, ever, and there is nothing
 * to log. A *dangling target* is not silent — see {@see TierB9HookTargetsResolve}, where it
 * threw a `TypeError` out of Symfony's listener optimisation and 500'd a shared admin page.
 * Do not carry that class's failure story over to this one, or this one's over to it.
 *
 * ---------------------------------------------------------------------------------
 * WHY THIS IS A SEPARATE CATALOGUE ENTRY
 * ---------------------------------------------------------------------------------
 * Recon over all 233 hook registrations in the 69 in-scope plugins found **233/233** passing
 * Tier-B-9: every target exists, is public, is static, takes one parameter, and types it on
 * `GenericEvent`. B-9 is a regression guard with no day-one yield, and that is fine — but it
 * would be a mistake to conclude the hook tables are clean, because the same sweep found
 * **2 of 233** keys that nothing in the fleet dispatches:
 * `plugin.install` and `plugin.uninstall` in `detain/myadmin-cloudlinux-licensing`. Two
 * handlers wired to events that do not exist. They get their own matrix column here rather
 * than being folded into B-9's, because a column that is green for a different reason than
 * the one the reader assumes is worse than no column.
 *
 * ---------------------------------------------------------------------------------
 * THE TRAP: PLUGINS ARE DISPATCHERS TOO
 * ---------------------------------------------------------------------------------
 * The obvious way to build the vocabulary is to grep the core tree for dispatch sites. That
 * is wrong, and wrong in the most damaging possible direction. A core-only sweep reports
 * `<module>.terminate` as dead in six packages — cpanel/directadmin/plesk/pleskautomation
 * webhosting, directadmin-storage, zonemta-mail — because `.terminate` is not dispatched by
 * core at all. It is dispatched by *other plugins*: `myadmin-webhosting-module`,
 * `myadmin-backups-module`, `myadmin-mail-module` and `myadmin-servers-module` each fire
 * `self::$module.'.terminate'`. Plugin-to-plugin dispatch is normal here, so the vocabulary
 * is the union over core **and** every vendor plugin. Six false positives on service
 * termination — the code path that stops billing a cancelled customer — is exactly the kind
 * of result that gets a whole check switched off.
 *
 * The vocabulary below is therefore held as reviewed **data**, derived once from both trees
 * and confirmed against every dispatch form. Anyone adding a dispatch site adds it here; that
 * is a smaller and far more visible obligation than re-deriving it per run, and it means a
 * failure is always attributable to a specific missing entry rather than to a scan that
 * happened to look in the wrong place.
 *
 * A key with no `.` in it is reported as unreachable rather than deferred to Tier-A-6. A-6
 * complains about its *format*; this reports the independent fact that no dispatch form can
 * ever produce it. Both are true, and a reader who fixes only the format still has dead code.
 *
 * ---------------------------------------------------------------------------------
 * NOT TO BE CONFUSED WITH run_event()
 * ---------------------------------------------------------------------------------
 * `run_event()` is a separate legacy mechanism: `$GLOBALS['events'][$module][$event]` mapping
 * to a **string function name** invoked through `call_user_func`, with four event names in
 * use fleet-wide (`get_service_types`, `get_service_offers`, `parse_service_extra`,
 * `verify_activated_services`). It has nothing to do with the `GenericEvent` hook table this
 * inspector reads, and no assertion here crosses between the two vocabularies.
 */
class TierB9bHookKeysDispatched implements PluginInspector
{
    /** Catalogue id. Deliberately not "B-9": its own column in the matrix. */
    const ID = 'B-9b';

    /**
     * Keys dispatched verbatim, matched exactly.
     *
     * ---------------------------------------------------------------------------------
     * SINGLE SOURCE OF TRUTH — A-7 ALIASES THIS
     * ---------------------------------------------------------------------------------
     * {@see TierA7HookKeyScoping::GLOBAL_HOOK_KEYS} *is* this constant, not a copy of it.
     * A-7 asks whether a key may be registered by a plugin whose `$module` does not match
     * the key's prefix; the answer is yes exactly when the key is dispatched from a literal
     * string, because a literal dispatch fires regardless of who is listening. So "global"
     * and "dispatched verbatim" name one set, and the two hand-maintained lists that used to
     * express it had already drifted — A-7 carried six of these nine, and false-failed
     * `licenses.deactivate_key` with the words "a prefix nothing dispatches to" while this
     * class, in the same run, reported that key as dispatched.
     *
     * The obligation the class docblock states — *anyone adding a dispatch site adds it
     * here* — therefore now covers A-7 as well. Adding a key here widens what A-7 accepts
     * under a non-matching `$module`, so a key belongs in this list only if it is genuinely
     * dispatched from a literal; a `$module.'.<suffix>'` dispatch belongs in
     * {@see DYNAMIC_SUFFIXES}, which A-7 deliberately does **not** consult.
     *
     * The `licenses.*` three are the literal dispatches in core's
     * `include/licenses/deactivate_license_by_key.php`,
     * `include/licenses/deactivate_license_by_ip.php` and
     * `include/licenses/license.functions.inc.php`.
     *
     * @var array<int,string>
     */
    const LITERAL_KEYS = [
        'account.activated',
        'ui.menu',
        'system.settings',
        'mailinglist.subscribe',
        'function.requirements',
        'api.register',
        'licenses.deactivate_key',
        'licenses.deactivate_ip',
        'licenses.change_ip',
    ];

    /**
     * Suffixes dispatched as `$module.'.<suffix>'`, so **any** module may legitimately pair
     * with them. `terminate` is in this list because module plugins fire it — see the class
     * docblock before removing anything here.
     *
     * @var array<int,string>
     */
    const DYNAMIC_SUFFIXES = [
        'load_processing',
        'load_addons',
        'queue',
        'activate',
        'settings',
        'deactivate',
        'reactivate',
        'terminate',
    ];

    /**
     * @return string
     */
    public function id()
    {
        return self::ID;
    }

    /**
     * @return string
     */
    public function title()
    {
        return 'Hook key is dispatched';
    }

    /**
     * The exact-match half of the vocabulary.
     *
     * @return array<int,string>
     */
    public function dispatchedKeys()
    {
        return self::LITERAL_KEYS;
    }

    /**
     * The `$module.'.<suffix>'` half of the vocabulary.
     *
     * @return array<int,string>
     */
    public function dispatchedSuffixes()
    {
        return self::DYNAMIC_SUFFIXES;
    }

    /**
     * Whether anything in core or in a vendor plugin fires this key.
     *
     * @param string $key
     * @return bool
     */
    public function isDispatched($key)
    {
        if (in_array($key, self::LITERAL_KEYS, true)) {
            return true;
        }
        $dot = strrpos($key, '.');
        if ($dot === false) {
            return false;
        }
        return in_array(substr($key, $dot + 1), self::DYNAMIC_SUFFIXES, true);
    }

    /**
     * @param PluginSubject $subject
     * @return array<int,Finding>
     */
    public function inspect(PluginSubject $subject)
    {
        if (!$subject->isLoadable()) {
            return [Finding::skipped(
                self::ID,
                'plugin class does not load, so its hook keys cannot be read',
                ['plugin' => $subject->pluginClass()]
            )];
        }

        $hooks = $this->readHooks($subject);
        if ($hooks instanceof Finding) {
            return [$hooks];
        }

        $findings = [];
        foreach ($hooks as $key => $target) {
            $key = (string)$key;
            if ($this->isDispatched($key)) {
                continue;
            }
            $findings[] = Finding::failure(
                self::ID,
                'hook "'.$key.'" is never dispatched: it is not one of the literal keys'
                    .' ('.implode(', ', self::LITERAL_KEYS).') and its suffix is not one of the'
                    .' per-module suffixes ('.implode(', ', self::DYNAMIC_SUFFIXES).').'
                    .' The handler is dead code — either the dispatch site was removed, or the'
                    .' key is a typo, or a new dispatch site needs adding to'
                    .' TierB9bHookKeysDispatched',
                ['plugin' => $subject->pluginClass(), 'hook' => $key]
            );
        }
        return $findings;
    }

    /**
     * @param PluginSubject $subject
     * @return array<string,mixed>|Finding
     */
    private function readHooks(PluginSubject $subject)
    {
        $reflection = $subject->reflection();
        if (!$reflection->hasMethod('getHooks')) {
            return Finding::skipped(
                self::ID,
                'plugin declares no getHooks(), so there are no hook keys to check',
                ['plugin' => $subject->pluginClass()]
            );
        }

        $method = $reflection->getMethod('getHooks');
        if (!$method->isStatic() || !$method->isPublic()) {
            return Finding::skipped(
                self::ID,
                'getHooks() is not public static, so it cannot be called (Tier-A-5 reports this)',
                ['plugin' => $subject->pluginClass()]
            );
        }

        try {
            $hooks = $method->invoke(null);
        } catch (Throwable $e) {
            return Finding::skipped(
                self::ID,
                'getHooks() threw '.get_class($e).': '.$e->getMessage(),
                ['plugin' => $subject->pluginClass()]
            );
        }

        if (!is_array($hooks)) {
            return Finding::skipped(
                self::ID,
                'getHooks() did not return an array, so there is no hook table to walk (Tier-A-5 reports this)',
                ['plugin' => $subject->pluginClass(), 'returned' => gettype($hooks)]
            );
        }

        return $hooks;
    }
}
