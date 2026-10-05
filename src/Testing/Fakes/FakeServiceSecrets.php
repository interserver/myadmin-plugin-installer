<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace MyAdmin\Plugins\Testing\Fakes;

/**
 * Stand-in for core's `\MyAdmin\Security\ServiceSecrets` (MyAdmin plan_2way,
 * SecretBox), installed under that name by `Bootstrap::installSecrets()` only
 * when core's class is not loadable, i.e. in a plugin's own test run.
 *
 * It models core with **every SecretBox write flag off and plaintext data**,
 * which is production today: readers hand the stored value back unchanged,
 * writers return what they are given and run no query, the pre-flight passes.
 * The one rule core applies regardless of flags, the input rule
 * (`rejectInput()`: at most 128 bytes and not shaped like an envelope), is
 * applied here too. Anything that would need a key (an envelope, `open()`,
 * `seal()`) fails loudly: plugin tests must not carry sealed data.
 */
class FakeServiceSecrets
{
    /** core's SecretBoxPurpose::MAX_INPUT_BYTES */
    public const MAX_INPUT_BYTES = 128;

    /** @param mixed $rowRef */
    public static function readColumn($table, $column, $rowRef, $stored)
    {
        self::refuseEnvelope($stored);
        return $stored;
    }

    public static function readHistoryRow(array $row, $table = 'history_log')
    {
        foreach (['history_old_value', 'history_new_value'] as $field) {
            self::refuseEnvelope($row[$field] ?? null);
        }
        return $row;
    }

    public static function readQueueParam(array $queueRow)
    {
        return self::readHistoryRow($queueRow, 'queue_log')['history_old_value'];
    }

    public static function readRowSecrets($table, array $row)
    {
        return $row;
    }

    public static function passesThrough($stored, $purpose)
    {
        return !self::isEnvelope($stored);
    }

    /** @return mixed */
    public static function insertValue($table, $column, $plain)
    {
        return $plain;
    }

    /** @return mixed */
    public static function updateValue($table, $column, $rowRef, $plain)
    {
        return $plain;
    }

    /** @return void */
    public static function sealAfterInsert($db, $table, $column, $rowRef, $plain, $line = 0, $file = '')
    {
    }

    /** @return mixed */
    public static function historyUpdateValue(array $storedRow, $column, $value, $table = 'history_log')
    {
        return $value;
    }

    public static function historyWriteEnabled($purpose, $section)
    {
        return false;
    }

    /** @return string|null */
    public static function rejectInput($password)
    {
        if ($password === null || (strlen($password) <= self::MAX_INPUT_BYTES && preg_match('/^S\d\./', $password) !== 1)) {
            return null;
        }
        return strlen($password) > self::MAX_INPUT_BYTES
            ? sprintf('The password must be at most %d characters long.', self::MAX_INPUT_BYTES)
            : 'The password cannot start with the letter S, a digit and a dot.';
    }

    /** @return string|null */
    public static function writeBlocked(...$purposes)
    {
        return null;
    }

    /** Instance side (`App::secrets()`): only an envelope or a flipped flag reaches it, neither exists in plugin tests. */
    public function __call($method, $args)
    {
        throw new \LogicException("SecretBox {$method}() is not available in plugin tests: test data must be plaintext and every write flag is off");
    }

    /** @param mixed $value */
    private static function isEnvelope($value)
    {
        return is_string($value) && preg_match('/^S1\.k[0-9]{1,4}\.[A-Za-z0-9_-]{54,}\z/', $value) === 1;
    }

    /** @param mixed $value */
    private static function refuseEnvelope($value)
    {
        if (self::isEnvelope($value)) {
            throw new \LogicException('a SecretBox envelope reached a plugin test: test data must be plaintext');
        }
    }
}
