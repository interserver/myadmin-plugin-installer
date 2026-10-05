<?php

namespace Tests\MyAdmin\Plugins\Testing;

use MyAdmin\Plugins\Testing\Bootstrap;
use MyAdmin\Plugins\Testing\Fakes\FakeApp;
use MyAdmin\Plugins\Testing\Fakes\FakeSecretFlags;
use MyAdmin\Plugins\Testing\Fakes\FakeServiceSecrets;
use PHPUnit\Framework\TestCase;

/**
 * The SecretBox stand-ins (MyAdmin plan_2way): core's ServiceSecrets and
 * SecretFlags with every write flag off and plaintext data, which is
 * production today, so plugin code that reads a stored secret through them
 * executes in a plugin's own tests.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class SecretFakesTest extends TestCase
{
    public function testInitAliasesBothNamesOnceAndOnlyWhenAbsent()
    {
        $this->assertFalse(class_exists('MyAdmin\Security\ServiceSecrets', false));
        Bootstrap::init();
        $this->assertTrue(class_exists('MyAdmin\Security\ServiceSecrets', false));
        $this->assertSame(FakeServiceSecrets::class, get_class(new \MyAdmin\Security\ServiceSecrets()));
        $this->assertSame(FakeSecretFlags::class, (new \ReflectionClass('MyAdmin\Security\SecretFlags'))->getName());
        $this->assertSame(['MyAdmin\Security\ServiceSecrets' => false, 'MyAdmin\Security\SecretFlags' => false], Bootstrap::installSecrets(), 'a second call leaves the names alone');
    }

    public function testARealClassIsNeverReplaced()
    {
        eval('namespace MyAdmin\Security; final class ServiceSecrets { public static function readColumn() { return "real"; } }');
        $installed = Bootstrap::installSecrets();
        $this->assertFalse($installed['MyAdmin\Security\ServiceSecrets']);
        $this->assertTrue($installed['MyAdmin\Security\SecretFlags']);
        $this->assertSame('real', \MyAdmin\Security\ServiceSecrets::readColumn());
    }

    public function testReadersAndWritersAreTodaysFlagsOffBehaviour()
    {
        $row = ['history_section' => 'vps', 'history_type' => 'webuzo_pass', 'history_new_value' => "Pw'1\\", 'history_old_value' => 'Webuzo Details', 'history_owner' => 5];
        $this->assertSame("Root'Pw", FakeServiceSecrets::readColumn('vps', 'vps_rootpass', 1, "Root'Pw"));
        $this->assertNull(FakeServiceSecrets::readColumn('vps', 'vps_rootpass', 1, null));
        $this->assertSame($row, FakeServiceSecrets::readHistoryRow($row));
        $this->assertSame('Webuzo Details', FakeServiceSecrets::readQueueParam($row));
        $this->assertSame(12345678, FakeServiceSecrets::insertValue('vps', 'vps_rootpass', 12345678));
        $this->assertSame('x', FakeServiceSecrets::updateValue('vps', 'vps_rootpass', 1, 'x'));
        $this->assertSame('y', FakeServiceSecrets::historyUpdateValue($row, 'history_new_value', 'y'));
        $this->assertNull(FakeServiceSecrets::sealAfterInsert(null, 'vps', 'vps_rootpass', 1, 'x'));
        $this->assertNull(FakeServiceSecrets::writeBlocked('vps_rootpass', 'queue_param'));
        $this->assertFalse(FakeServiceSecrets::historyWriteEnabled('service_password_log', 'vps'));
        $this->assertFalse(FakeSecretFlags::writeEnabled('vps_rootpass'));
        $this->assertTrue(FakeSecretFlags::allowPlaintext('vps_rootpass'));
        $this->assertTrue(FakeSecretFlags::historySectionUnsealed('mail'));
    }

    public function testTheInputRuleMatchesCore()
    {
        $this->assertNull(FakeServiceSecrets::rejectInput(str_repeat('x', 128)));
        $this->assertNull(FakeServiceSecrets::rejectInput('s1.lower'));
        $this->assertNull(FakeServiceSecrets::rejectInput(null));
        $this->assertSame('The password must be at most 128 characters long.', FakeServiceSecrets::rejectInput(str_repeat('x', 129)));
        $this->assertSame('The password cannot start with the letter S, a digit and a dot.', FakeServiceSecrets::rejectInput('S1.Abc'));
    }

    public function testSealedDataAndTheInstanceSideFailLoudly()
    {
        $envelope = 'S1.k1.' . str_repeat('A', 60);
        foreach ([
            function () use ($envelope) { FakeServiceSecrets::readColumn('vps', 'vps_rootpass', 1, $envelope); },
            function () use ($envelope) { FakeServiceSecrets::readHistoryRow(['history_old_value' => $envelope, 'history_new_value' => '1']); },
            function () { FakeApp::secrets()->open('vps', 'vps_rootpass', 1, 'x'); },
            function () { FakeApp::secretBox()->decrypt('x', 'vps_rootpass', 'a'); },
        ] as $i => $call) {
            try {
                $call();
                $this->fail("case {$i} must throw");
            } catch (\LogicException $e) {
                $this->assertStringContainsString('plaintext', $e->getMessage());
            }
        }
    }
}
