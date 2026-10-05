<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace MyAdmin\Plugins\Testing\Fakes;

/**
 * Stand-in for core's `\MyAdmin\Security\SecretFlags`, installed by
 * `Bootstrap::installSecrets()` only when core's class is not loadable: every
 * write flag off, plaintext allowed, nothing strict, `mail` history unsealed.
 * That is production today.
 */
class FakeSecretFlags
{
    public static function writeEnabled($purpose)
    {
        return false;
    }

    public static function allowPlaintext($purpose)
    {
        return true;
    }

    public static function isStrict($purpose)
    {
        return false;
    }

    public static function historySectionUnsealed($section)
    {
        return $section === 'mail';
    }

    public static function blankClientHistorySecrets()
    {
        return false;
    }

    public static function all()
    {
        return ['write' => [], 'allow_plaintext' => true, 'strict_purposes' => [], 'history_unsealed_sections' => ['mail'], 'blank_client_history_secrets' => false];
    }
}
