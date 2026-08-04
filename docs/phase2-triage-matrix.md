# Phase 2 — fleet triage matrix (gate G2)

**17 assertions x 71 packages = 1207 cells** — 1034 pass, 18 fail, 5 skip, 150 not applicable.

Generated — do not hand-edit. Reproduce with:

```bash
php tools/fleet-matrix.php
```

Every inspector runs over every in-scope plugin, **one process per package**:
constants are immutable and `register_module()` has no inverse, so a shared process
would let plugin *n* contaminate plugin *n+1*. Plugin classes are resolved from each
package's composer PSR-4 map, never guessed from the package name. The fleet is every
package whose composer `type` is `myadmin-plugin`.

A cell is `pass` when the check ran, observed something and found it clean; `not
applicable` when the check ran and this package has nothing of that kind — no routes,
no `getMenu()`, no queue templates; and `skip` when the check **could not run**. Those
last two were one grey dash until R-4, and all 155 of the dashes meant the harmless
one, so the state that hides bugs was invisible inside the state that does not. A cell
is `missing` when its process produced no verdict at all; that is a broken run, not a
result, and it is counted separately above rather than folded into the denominator.

## Census

| id | pass | fail | skip | n/a | note |
|---|---|---|---|---|---|
| A-1 | 71 | 0 | 0 | 0 |  |
| A-2 | 71 | 0 | 0 | 0 |  |
| A-3 | 71 | 0 | 0 | 0 |  |
| A-4 | 71 | 0 | 0 | 0 |  |
| A-5 | 71 | 0 | 0 | 0 |  |
| A-6 | 71 | 0 | 0 | 0 |  |
| A-7 | 70 | 1 | 0 | 0 | cloudlinux dead hooks |
| A-8 | 71 | 0 | 0 | 0 |  |
| A-9 | 71 | 0 | 0 | 0 | 0 yield — regression guard |
| B-9 | 71 | 0 | 0 | 0 | 0 yield — regression guard |
| B-9b | 70 | 1 | 0 | 0 | cloudlinux dead hooks |
| B-10 | 56 | 15 | 0 | 0 | dangling requirement paths |
| B-11 | 28 | 0 | 0 | 43 | n/a: registers no routes, or no function.requirements handler |
| B-12 | 56 | 1 | 0 | 14 | n/a: orphaned getSettings — core can never invoke it |
| B-13 | 43 | 0 | 0 | 28 | n/a: no getMenu() |
| B-14 | 1 | 0 | 5 | 65 | n/a: no getQueue(), or not a service · the 5 skips are dynamic dispatch B-14 cannot read |
| B-15 | 71 | 0 | 0 | 0 |  |

## Escape hatches

No package overrides a contract default. Every cell above was measured
against the assertion as written.

## Failing cells, classified (all P-bugs — report only, per D7)

### A-7 — 1 package(s)

- **detain/myadmin-cloudlinux-licensing**
  - [A-7] Detain\MyAdminCloudlinux\Plugin registers hook key "plugin.install", whose prefix is "plugin", but the plugin declares $module = "licenses". The hook registers under a prefix nothing dispatches to. Expected the key to start with "licenses." or to be one of the global hooks. (class='Detain\\MyAdminCloudlinux\\Plugin', key='plugin.install', prefix='plugin', module='licenses', problem='prefix-mismatch', dispatched=false)
  - [A-7] Detain\MyAdminCloudlinux\Plugin registers hook key "plugin.uninstall", whose prefix is "plugin", but the plugin declares $module = "licenses". The hook registers under a prefix nothing dispatches to. Expected the key to start with "licenses." or to be one of the global hooks. (class='Detain\\MyAdminCloudlinux\\Plugin', key='plugin.uninstall', prefix='plugin', module='licenses', problem='prefix-mismatch', dispatched=false)

### B-9b — 1 package(s)

- **detain/myadmin-cloudlinux-licensing**
  - [B-9b] hook "plugin.install" is never dispatched: it is not one of the literal keys (account.activated, ui.menu, system.settings, mailinglist.subscribe, function.requirements, api.register, licenses.deactivate_key, licenses.deactivate_ip, licenses.change_ip) and its suffix is not one of the per-module suffixes (load_processing, load_addons, queue, activate, settings, deactivate, reactivate, terminate). The handler is dead code — either the dispatch site was removed, or the key is a typo, or a new dispatch site needs adding to TierB9bHookKeysDispatched (plugin='Detain\\MyAdminCloudlinux\\Plugin', hook='plugin.install')
  - [B-9b] hook "plugin.uninstall" is never dispatched: it is not one of the literal keys (account.activated, ui.menu, system.settings, mailinglist.subscribe, function.requirements, api.register, licenses.deactivate_key, licenses.deactivate_ip, licenses.change_ip) and its suffix is not one of the per-module suffixes (load_processing, load_addons, queue, activate, settings, deactivate, reactivate, terminate). The handler is dead code — either the dispatch site was removed, or the key is a typo, or a new dispatch site needs adding to TierB9bHookKeysDispatched (plugin='Detain\\MyAdminCloudlinux\\Plugin', hook='plugin.uninstall')

### B-10 — 15 package(s)

- **detain/myadmin-cpanel-licensing**
  - [B-10] requirement "unbilled_cpanel_old" registers /../vendor/detain/myadmin-cpanel-licensing/src/unbilled_cpanel_old.php, which resolves to /home/sites/mystage/include/../vendor/detain/myadmin-cpanel-licensing/src/unbilled_cpanel_old.php — no such file; function_requirements('unbilled_cpanel_old') will fatal (plugin='MyAdmin\\Licenses\\Cpanel\\Plugin', function='unbilled_cpanel_old', root='/home/sites/mystage/include', source='/../vendor/detain/myadmin-cpanel-licensing/src/unbilled_cpanel_old.php', resolved='/home/sites/mystage/include/../vendor/detain/myadmin-cpanel-licensing/src/unbilled_cpanel_old.php')
- **detain/myadmin-drbl-backups**
  - [B-10] requirement "class.Drbl" registers /../vendor/detain/myadmin-drbl-backups/src/Drbl.php, which resolves to /home/sites/mystage/include/../vendor/detain/myadmin-drbl-backups/src/Drbl.php — no such file; function_requirements('class.Drbl') will fatal (plugin='Detain\\MyAdminDrbl\\Plugin', function='class.Drbl', root='/home/sites/mystage/include', source='/../vendor/detain/myadmin-drbl-backups/src/Drbl.php', resolved='/home/sites/mystage/include/../vendor/detain/myadmin-drbl-backups/src/Drbl.php')
  - [B-10] requirement "deactivate_kcare" registers /../vendor/detain/myadmin-drbl-backups/src/abuse.inc.php, which resolves to /home/sites/mystage/include/../vendor/detain/myadmin-drbl-backups/src/abuse.inc.php — no such file; function_requirements('deactivate_kcare') will fatal (plugin='Detain\\MyAdminDrbl\\Plugin', function='deactivate_kcare', root='/home/sites/mystage/include', source='/../vendor/detain/myadmin-drbl-backups/src/abuse.inc.php', resolved='/home/sites/mystage/include/../vendor/detain/myadmin-drbl-backups/src/abuse.inc.php')
  - [B-10] requirement "deactivate_abuse" registers /../vendor/detain/myadmin-drbl-backups/src/abuse.inc.php, which resolves to /home/sites/mystage/include/../vendor/detain/myadmin-drbl-backups/src/abuse.inc.php — no such file; function_requirements('deactivate_abuse') will fatal (plugin='Detain\\MyAdminDrbl\\Plugin', function='deactivate_abuse', root='/home/sites/mystage/include', source='/../vendor/detain/myadmin-drbl-backups/src/abuse.inc.php', resolved='/home/sites/mystage/include/../vendor/detain/myadmin-drbl-backups/src/abuse.inc.php')
  - [B-10] requirement "get_abuse_licenses" registers /../vendor/detain/myadmin-drbl-backups/src/abuse.inc.php, which resolves to /home/sites/mystage/include/../vendor/detain/myadmin-drbl-backups/src/abuse.inc.php — no such file; function_requirements('get_abuse_licenses') will fatal (plugin='Detain\\MyAdminDrbl\\Plugin', function='get_abuse_licenses', root='/home/sites/mystage/include', source='/../vendor/detain/myadmin-drbl-backups/src/abuse.inc.php', resolved='/home/sites/mystage/include/../vendor/detain/myadmin-drbl-backups/src/abuse.inc.php')
- **detain/myadmin-fantastico-licensing**
  - [B-10] requirement "vps_add_fantastico" registers /vps/addons/vps_add_fantastico.php, which resolves to /home/sites/mystage/include/vps/addons/vps_add_fantastico.php — no such file; function_requirements('vps_add_fantastico') will fatal (plugin='Detain\\MyAdminFantastico\\Plugin', function='vps_add_fantastico', root='/home/sites/mystage/include', source='/vps/addons/vps_add_fantastico.php', resolved='/home/sites/mystage/include/vps/addons/vps_add_fantastico.php')
- **detain/myadmin-gluster-backups**
  - [B-10] requirement "class.Gluster" registers /../vendor/detain/myadmin-gluster-backups/src/Gluster.php, which resolves to /home/sites/mystage/include/../vendor/detain/myadmin-gluster-backups/src/Gluster.php — no such file; function_requirements('class.Gluster') will fatal (plugin='Detain\\MyAdminGluster\\Plugin', function='class.Gluster', root='/home/sites/mystage/include', source='/../vendor/detain/myadmin-gluster-backups/src/Gluster.php', resolved='/home/sites/mystage/include/../vendor/detain/myadmin-gluster-backups/src/Gluster.php')
  - [B-10] requirement "deactivate_kcare" registers /../vendor/detain/myadmin-gluster-backups/src/abuse.inc.php, which resolves to /home/sites/mystage/include/../vendor/detain/myadmin-gluster-backups/src/abuse.inc.php — no such file; function_requirements('deactivate_kcare') will fatal (plugin='Detain\\MyAdminGluster\\Plugin', function='deactivate_kcare', root='/home/sites/mystage/include', source='/../vendor/detain/myadmin-gluster-backups/src/abuse.inc.php', resolved='/home/sites/mystage/include/../vendor/detain/myadmin-gluster-backups/src/abuse.inc.php')
  - [B-10] requirement "deactivate_abuse" registers /../vendor/detain/myadmin-gluster-backups/src/abuse.inc.php, which resolves to /home/sites/mystage/include/../vendor/detain/myadmin-gluster-backups/src/abuse.inc.php — no such file; function_requirements('deactivate_abuse') will fatal (plugin='Detain\\MyAdminGluster\\Plugin', function='deactivate_abuse', root='/home/sites/mystage/include', source='/../vendor/detain/myadmin-gluster-backups/src/abuse.inc.php', resolved='/home/sites/mystage/include/../vendor/detain/myadmin-gluster-backups/src/abuse.inc.php')
  - [B-10] requirement "get_abuse_licenses" registers /../vendor/detain/myadmin-gluster-backups/src/abuse.inc.php, which resolves to /home/sites/mystage/include/../vendor/detain/myadmin-gluster-backups/src/abuse.inc.php — no such file; function_requirements('get_abuse_licenses') will fatal (plugin='Detain\\MyAdminGluster\\Plugin', function='get_abuse_licenses', root='/home/sites/mystage/include', source='/../vendor/detain/myadmin-gluster-backups/src/abuse.inc.php', resolved='/home/sites/mystage/include/../vendor/detain/myadmin-gluster-backups/src/abuse.inc.php')
- **detain/myadmin-google-analytics**
  - [B-10] requirement "class.Google" registers /../vendor/detain/myadmin-google-analytics/src/Google.php, which resolves to /home/sites/mystage/include/../vendor/detain/myadmin-google-analytics/src/Google.php — no such file; function_requirements('class.Google') will fatal (plugin='Detain\\MyAdminGoogle\\Plugin', function='class.Google', root='/home/sites/mystage/include', source='/../vendor/detain/myadmin-google-analytics/src/Google.php', resolved='/home/sites/mystage/include/../vendor/detain/myadmin-google-analytics/src/Google.php')
  - [B-10] requirement "deactivate_kcare" registers /../vendor/detain/myadmin-google-analytics/src/abuse.inc.php, which resolves to /home/sites/mystage/include/../vendor/detain/myadmin-google-analytics/src/abuse.inc.php — no such file; function_requirements('deactivate_kcare') will fatal (plugin='Detain\\MyAdminGoogle\\Plugin', function='deactivate_kcare', root='/home/sites/mystage/include', source='/../vendor/detain/myadmin-google-analytics/src/abuse.inc.php', resolved='/home/sites/mystage/include/../vendor/detain/myadmin-google-analytics/src/abuse.inc.php')
  - [B-10] requirement "deactivate_abuse" registers /../vendor/detain/myadmin-google-analytics/src/abuse.inc.php, which resolves to /home/sites/mystage/include/../vendor/detain/myadmin-google-analytics/src/abuse.inc.php — no such file; function_requirements('deactivate_abuse') will fatal (plugin='Detain\\MyAdminGoogle\\Plugin', function='deactivate_abuse', root='/home/sites/mystage/include', source='/../vendor/detain/myadmin-google-analytics/src/abuse.inc.php', resolved='/home/sites/mystage/include/../vendor/detain/myadmin-google-analytics/src/abuse.inc.php')
  - [B-10] requirement "get_abuse_licenses" registers /../vendor/detain/myadmin-google-analytics/src/abuse.inc.php, which resolves to /home/sites/mystage/include/../vendor/detain/myadmin-google-analytics/src/abuse.inc.php — no such file; function_requirements('get_abuse_licenses') will fatal (plugin='Detain\\MyAdminGoogle\\Plugin', function='get_abuse_licenses', root='/home/sites/mystage/include', source='/../vendor/detain/myadmin-google-analytics/src/abuse.inc.php', resolved='/home/sites/mystage/include/../vendor/detain/myadmin-google-analytics/src/abuse.inc.php')
- **detain/myadmin-googlewallet-payments**
  - [B-10] requirement "class.Googlewallet" registers /../vendor/detain/myadmin-googlewallet-payments/src/Googlewallet.php, which resolves to /home/sites/mystage/include/../vendor/detain/myadmin-googlewallet-payments/src/Googlewallet.php — no such file; function_requirements('class.Googlewallet') will fatal (plugin='Detain\\MyAdminGooglewallet\\Plugin', function='class.Googlewallet', root='/home/sites/mystage/include', source='/../vendor/detain/myadmin-googlewallet-payments/src/Googlewallet.php', resolved='/home/sites/mystage/include/../vendor/detain/myadmin-googlewallet-payments/src/Googlewallet.php')
  - [B-10] requirement "deactivate_kcare" registers /../vendor/detain/myadmin-googlewallet-payments/src/abuse.inc.php, which resolves to /home/sites/mystage/include/../vendor/detain/myadmin-googlewallet-payments/src/abuse.inc.php — no such file; function_requirements('deactivate_kcare') will fatal (plugin='Detain\\MyAdminGooglewallet\\Plugin', function='deactivate_kcare', root='/home/sites/mystage/include', source='/../vendor/detain/myadmin-googlewallet-payments/src/abuse.inc.php', resolved='/home/sites/mystage/include/../vendor/detain/myadmin-googlewallet-payments/src/abuse.inc.php')
  - [B-10] requirement "deactivate_abuse" registers /../vendor/detain/myadmin-googlewallet-payments/src/abuse.inc.php, which resolves to /home/sites/mystage/include/../vendor/detain/myadmin-googlewallet-payments/src/abuse.inc.php — no such file; function_requirements('deactivate_abuse') will fatal (plugin='Detain\\MyAdminGooglewallet\\Plugin', function='deactivate_abuse', root='/home/sites/mystage/include', source='/../vendor/detain/myadmin-googlewallet-payments/src/abuse.inc.php', resolved='/home/sites/mystage/include/../vendor/detain/myadmin-googlewallet-payments/src/abuse.inc.php')
  - [B-10] requirement "get_abuse_licenses" registers /../vendor/detain/myadmin-googlewallet-payments/src/abuse.inc.php, which resolves to /home/sites/mystage/include/../vendor/detain/myadmin-googlewallet-payments/src/abuse.inc.php — no such file; function_requirements('get_abuse_licenses') will fatal (plugin='Detain\\MyAdminGooglewallet\\Plugin', function='get_abuse_licenses', root='/home/sites/mystage/include', source='/../vendor/detain/myadmin-googlewallet-payments/src/abuse.inc.php', resolved='/home/sites/mystage/include/../vendor/detain/myadmin-googlewallet-payments/src/abuse.inc.php')
- **detain/myadmin-hotjar-analytics**
  - [B-10] requirement "class.Hotjar" registers /../vendor/detain/myadmin-hotjar-analytics/src/Hotjar.php, which resolves to /home/sites/mystage/include/../vendor/detain/myadmin-hotjar-analytics/src/Hotjar.php — no such file; function_requirements('class.Hotjar') will fatal (plugin='Detain\\MyAdminHotjar\\Plugin', function='class.Hotjar', root='/home/sites/mystage/include', source='/../vendor/detain/myadmin-hotjar-analytics/src/Hotjar.php', resolved='/home/sites/mystage/include/../vendor/detain/myadmin-hotjar-analytics/src/Hotjar.php')
  - [B-10] requirement "deactivate_kcare" registers /../vendor/detain/myadmin-hotjar-analytics/src/abuse.inc.php, which resolves to /home/sites/mystage/include/../vendor/detain/myadmin-hotjar-analytics/src/abuse.inc.php — no such file; function_requirements('deactivate_kcare') will fatal (plugin='Detain\\MyAdminHotjar\\Plugin', function='deactivate_kcare', root='/home/sites/mystage/include', source='/../vendor/detain/myadmin-hotjar-analytics/src/abuse.inc.php', resolved='/home/sites/mystage/include/../vendor/detain/myadmin-hotjar-analytics/src/abuse.inc.php')
  - [B-10] requirement "deactivate_abuse" registers /../vendor/detain/myadmin-hotjar-analytics/src/abuse.inc.php, which resolves to /home/sites/mystage/include/../vendor/detain/myadmin-hotjar-analytics/src/abuse.inc.php — no such file; function_requirements('deactivate_abuse') will fatal (plugin='Detain\\MyAdminHotjar\\Plugin', function='deactivate_abuse', root='/home/sites/mystage/include', source='/../vendor/detain/myadmin-hotjar-analytics/src/abuse.inc.php', resolved='/home/sites/mystage/include/../vendor/detain/myadmin-hotjar-analytics/src/abuse.inc.php')
  - [B-10] requirement "get_abuse_licenses" registers /../vendor/detain/myadmin-hotjar-analytics/src/abuse.inc.php, which resolves to /home/sites/mystage/include/../vendor/detain/myadmin-hotjar-analytics/src/abuse.inc.php — no such file; function_requirements('get_abuse_licenses') will fatal (plugin='Detain\\MyAdminHotjar\\Plugin', function='get_abuse_licenses', root='/home/sites/mystage/include', source='/../vendor/detain/myadmin-hotjar-analytics/src/abuse.inc.php', resolved='/home/sites/mystage/include/../vendor/detain/myadmin-hotjar-analytics/src/abuse.inc.php')
- **detain/myadmin-kayako-chat**
  - [B-10] requirement "class.Kayako" registers /../vendor/detain/myadmin-kayako-chat/src/Kayako.php, which resolves to /home/sites/mystage/include/../vendor/detain/myadmin-kayako-chat/src/Kayako.php — no such file; function_requirements('class.Kayako') will fatal (plugin='Detain\\MyAdminKayakoChat\\Plugin', function='class.Kayako', root='/home/sites/mystage/include', source='/../vendor/detain/myadmin-kayako-chat/src/Kayako.php', resolved='/home/sites/mystage/include/../vendor/detain/myadmin-kayako-chat/src/Kayako.php')
  - [B-10] requirement "deactivate_kcare" registers /../vendor/detain/myadmin-kayako-chat/src/abuse.inc.php, which resolves to /home/sites/mystage/include/../vendor/detain/myadmin-kayako-chat/src/abuse.inc.php — no such file; function_requirements('deactivate_kcare') will fatal (plugin='Detain\\MyAdminKayakoChat\\Plugin', function='deactivate_kcare', root='/home/sites/mystage/include', source='/../vendor/detain/myadmin-kayako-chat/src/abuse.inc.php', resolved='/home/sites/mystage/include/../vendor/detain/myadmin-kayako-chat/src/abuse.inc.php')
  - [B-10] requirement "deactivate_abuse" registers /../vendor/detain/myadmin-kayako-chat/src/abuse.inc.php, which resolves to /home/sites/mystage/include/../vendor/detain/myadmin-kayako-chat/src/abuse.inc.php — no such file; function_requirements('deactivate_abuse') will fatal (plugin='Detain\\MyAdminKayakoChat\\Plugin', function='deactivate_abuse', root='/home/sites/mystage/include', source='/../vendor/detain/myadmin-kayako-chat/src/abuse.inc.php', resolved='/home/sites/mystage/include/../vendor/detain/myadmin-kayako-chat/src/abuse.inc.php')
  - [B-10] requirement "get_abuse_licenses" registers /../vendor/detain/myadmin-kayako-chat/src/abuse.inc.php, which resolves to /home/sites/mystage/include/../vendor/detain/myadmin-kayako-chat/src/abuse.inc.php — no such file; function_requirements('get_abuse_licenses') will fatal (plugin='Detain\\MyAdminKayakoChat\\Plugin', function='get_abuse_licenses', root='/home/sites/mystage/include', source='/../vendor/detain/myadmin-kayako-chat/src/abuse.inc.php', resolved='/home/sites/mystage/include/../vendor/detain/myadmin-kayako-chat/src/abuse.inc.php')
- **detain/myadmin-kayako-support**
  - [B-10] requirement "class.Kayako" registers /../vendor/detain/myadmin-kayako-support/src/Kayako.php, which resolves to /home/sites/mystage/include/../vendor/detain/myadmin-kayako-support/src/Kayako.php — no such file; function_requirements('class.Kayako') will fatal (plugin='Detain\\MyAdminKayako\\Plugin', function='class.Kayako', root='/home/sites/mystage/include', source='/../vendor/detain/myadmin-kayako-support/src/Kayako.php', resolved='/home/sites/mystage/include/../vendor/detain/myadmin-kayako-support/src/Kayako.php')
  - [B-10] requirement "deactivate_kcare" registers /../vendor/detain/myadmin-kayako-support/src/abuse.inc.php, which resolves to /home/sites/mystage/include/../vendor/detain/myadmin-kayako-support/src/abuse.inc.php — no such file; function_requirements('deactivate_kcare') will fatal (plugin='Detain\\MyAdminKayako\\Plugin', function='deactivate_kcare', root='/home/sites/mystage/include', source='/../vendor/detain/myadmin-kayako-support/src/abuse.inc.php', resolved='/home/sites/mystage/include/../vendor/detain/myadmin-kayako-support/src/abuse.inc.php')
  - [B-10] requirement "deactivate_abuse" registers /../vendor/detain/myadmin-kayako-support/src/abuse.inc.php, which resolves to /home/sites/mystage/include/../vendor/detain/myadmin-kayako-support/src/abuse.inc.php — no such file; function_requirements('deactivate_abuse') will fatal (plugin='Detain\\MyAdminKayako\\Plugin', function='deactivate_abuse', root='/home/sites/mystage/include', source='/../vendor/detain/myadmin-kayako-support/src/abuse.inc.php', resolved='/home/sites/mystage/include/../vendor/detain/myadmin-kayako-support/src/abuse.inc.php')
  - [B-10] requirement "get_abuse_licenses" registers /../vendor/detain/myadmin-kayako-support/src/abuse.inc.php, which resolves to /home/sites/mystage/include/../vendor/detain/myadmin-kayako-support/src/abuse.inc.php — no such file; function_requirements('get_abuse_licenses') will fatal (plugin='Detain\\MyAdminKayako\\Plugin', function='get_abuse_licenses', root='/home/sites/mystage/include', source='/../vendor/detain/myadmin-kayako-support/src/abuse.inc.php', resolved='/home/sites/mystage/include/../vendor/detain/myadmin-kayako-support/src/abuse.inc.php')
- **detain/myadmin-novnc-plugin**
  - [B-10] requirement "class.Novnc" registers /../vendor/detain/myadmin-novnc-plugin/src/Novnc.php, which resolves to /home/sites/mystage/include/../vendor/detain/myadmin-novnc-plugin/src/Novnc.php — no such file; function_requirements('class.Novnc') will fatal (plugin='Detain\\MyAdminNovnc\\Plugin', function='class.Novnc', root='/home/sites/mystage/include', source='/../vendor/detain/myadmin-novnc-plugin/src/Novnc.php', resolved='/home/sites/mystage/include/../vendor/detain/myadmin-novnc-plugin/src/Novnc.php')
  - [B-10] requirement "deactivate_kcare" registers /../vendor/detain/myadmin-novnc-plugin/src/abuse.inc.php, which resolves to /home/sites/mystage/include/../vendor/detain/myadmin-novnc-plugin/src/abuse.inc.php — no such file; function_requirements('deactivate_kcare') will fatal (plugin='Detain\\MyAdminNovnc\\Plugin', function='deactivate_kcare', root='/home/sites/mystage/include', source='/../vendor/detain/myadmin-novnc-plugin/src/abuse.inc.php', resolved='/home/sites/mystage/include/../vendor/detain/myadmin-novnc-plugin/src/abuse.inc.php')
  - [B-10] requirement "deactivate_abuse" registers /../vendor/detain/myadmin-novnc-plugin/src/abuse.inc.php, which resolves to /home/sites/mystage/include/../vendor/detain/myadmin-novnc-plugin/src/abuse.inc.php — no such file; function_requirements('deactivate_abuse') will fatal (plugin='Detain\\MyAdminNovnc\\Plugin', function='deactivate_abuse', root='/home/sites/mystage/include', source='/../vendor/detain/myadmin-novnc-plugin/src/abuse.inc.php', resolved='/home/sites/mystage/include/../vendor/detain/myadmin-novnc-plugin/src/abuse.inc.php')
  - [B-10] requirement "get_abuse_licenses" registers /../vendor/detain/myadmin-novnc-plugin/src/abuse.inc.php, which resolves to /home/sites/mystage/include/../vendor/detain/myadmin-novnc-plugin/src/abuse.inc.php — no such file; function_requirements('get_abuse_licenses') will fatal (plugin='Detain\\MyAdminNovnc\\Plugin', function='get_abuse_licenses', root='/home/sites/mystage/include', source='/../vendor/detain/myadmin-novnc-plugin/src/abuse.inc.php', resolved='/home/sites/mystage/include/../vendor/detain/myadmin-novnc-plugin/src/abuse.inc.php')
- **detain/myadmin-payum-payments**
  - [B-10] requirement "webuzo_configure" registers /../vendor/detain/myadmin-payum-payments/src/webuzo_configure.php, which resolves to /home/sites/mystage/include/../vendor/detain/myadmin-payum-payments/src/webuzo_configure.php — no such file; function_requirements('webuzo_configure') will fatal (plugin='Detain\\MyAdminPayum\\Plugin', function='webuzo_configure', root='/home/sites/mystage/include', source='/../vendor/detain/myadmin-payum-payments/src/webuzo_configure.php', resolved='/home/sites/mystage/include/../vendor/detain/myadmin-payum-payments/src/webuzo_configure.php')
- **detain/myadmin-piwik-analytics**
  - [B-10] requirement "class.Piwik" registers /../vendor/detain/myadmin-piwik-analytics/src/Piwik.php, which resolves to /home/sites/mystage/include/../vendor/detain/myadmin-piwik-analytics/src/Piwik.php — no such file; function_requirements('class.Piwik') will fatal (plugin='Detain\\MyAdminPiwik\\Plugin', function='class.Piwik', root='/home/sites/mystage/include', source='/../vendor/detain/myadmin-piwik-analytics/src/Piwik.php', resolved='/home/sites/mystage/include/../vendor/detain/myadmin-piwik-analytics/src/Piwik.php')
  - [B-10] requirement "deactivate_kcare" registers /../vendor/detain/myadmin-piwik-analytics/src/abuse.inc.php, which resolves to /home/sites/mystage/include/../vendor/detain/myadmin-piwik-analytics/src/abuse.inc.php — no such file; function_requirements('deactivate_kcare') will fatal (plugin='Detain\\MyAdminPiwik\\Plugin', function='deactivate_kcare', root='/home/sites/mystage/include', source='/../vendor/detain/myadmin-piwik-analytics/src/abuse.inc.php', resolved='/home/sites/mystage/include/../vendor/detain/myadmin-piwik-analytics/src/abuse.inc.php')
  - [B-10] requirement "deactivate_abuse" registers /../vendor/detain/myadmin-piwik-analytics/src/abuse.inc.php, which resolves to /home/sites/mystage/include/../vendor/detain/myadmin-piwik-analytics/src/abuse.inc.php — no such file; function_requirements('deactivate_abuse') will fatal (plugin='Detain\\MyAdminPiwik\\Plugin', function='deactivate_abuse', root='/home/sites/mystage/include', source='/../vendor/detain/myadmin-piwik-analytics/src/abuse.inc.php', resolved='/home/sites/mystage/include/../vendor/detain/myadmin-piwik-analytics/src/abuse.inc.php')
  - [B-10] requirement "get_abuse_licenses" registers /../vendor/detain/myadmin-piwik-analytics/src/abuse.inc.php, which resolves to /home/sites/mystage/include/../vendor/detain/myadmin-piwik-analytics/src/abuse.inc.php — no such file; function_requirements('get_abuse_licenses') will fatal (plugin='Detain\\MyAdminPiwik\\Plugin', function='get_abuse_licenses', root='/home/sites/mystage/include', source='/../vendor/detain/myadmin-piwik-analytics/src/abuse.inc.php', resolved='/home/sites/mystage/include/../vendor/detain/myadmin-piwik-analytics/src/abuse.inc.php')
- **detain/myadmin-powerdns**
  - [B-10] requirement "add_domain" registers /../vendor/detain/myadmin-powerdns/src/add_domain.php, which resolves to /home/sites/mystage/include/../vendor/detain/myadmin-powerdns/src/add_domain.php — no such file; function_requirements('add_domain') will fatal (plugin='Detain\\MyAdminPowerDns\\Plugin', function='add_domain', root='/home/sites/mystage/include', source='/../vendor/detain/myadmin-powerdns/src/add_domain.php', resolved='/home/sites/mystage/include/../vendor/detain/myadmin-powerdns/src/add_domain.php')
  - [B-10] requirement "list_domains" registers /../vendor/detain/myadmin-powerdns/src/list_domains.php, which resolves to /home/sites/mystage/include/../vendor/detain/myadmin-powerdns/src/list_domains.php — no such file; function_requirements('list_domains') will fatal (plugin='Detain\\MyAdminPowerDns\\Plugin', function='list_domains', root='/home/sites/mystage/include', source='/../vendor/detain/myadmin-powerdns/src/list_domains.php', resolved='/home/sites/mystage/include/../vendor/detain/myadmin-powerdns/src/list_domains.php')
- **detain/myadmin-raid-backups**
  - [B-10] requirement "class.Raid" registers /../vendor/detain/myadmin-raid-backups/src/Raid.php, which resolves to /home/sites/mystage/include/../vendor/detain/myadmin-raid-backups/src/Raid.php — no such file; function_requirements('class.Raid') will fatal (plugin='Detain\\MyAdminRaid\\Plugin', function='class.Raid', root='/home/sites/mystage/include', source='/../vendor/detain/myadmin-raid-backups/src/Raid.php', resolved='/home/sites/mystage/include/../vendor/detain/myadmin-raid-backups/src/Raid.php')
  - [B-10] requirement "deactivate_kcare" registers /../vendor/detain/myadmin-raid-backups/src/abuse.inc.php, which resolves to /home/sites/mystage/include/../vendor/detain/myadmin-raid-backups/src/abuse.inc.php — no such file; function_requirements('deactivate_kcare') will fatal (plugin='Detain\\MyAdminRaid\\Plugin', function='deactivate_kcare', root='/home/sites/mystage/include', source='/../vendor/detain/myadmin-raid-backups/src/abuse.inc.php', resolved='/home/sites/mystage/include/../vendor/detain/myadmin-raid-backups/src/abuse.inc.php')
  - [B-10] requirement "deactivate_abuse" registers /../vendor/detain/myadmin-raid-backups/src/abuse.inc.php, which resolves to /home/sites/mystage/include/../vendor/detain/myadmin-raid-backups/src/abuse.inc.php — no such file; function_requirements('deactivate_abuse') will fatal (plugin='Detain\\MyAdminRaid\\Plugin', function='deactivate_abuse', root='/home/sites/mystage/include', source='/../vendor/detain/myadmin-raid-backups/src/abuse.inc.php', resolved='/home/sites/mystage/include/../vendor/detain/myadmin-raid-backups/src/abuse.inc.php')
  - [B-10] requirement "get_abuse_licenses" registers /../vendor/detain/myadmin-raid-backups/src/abuse.inc.php, which resolves to /home/sites/mystage/include/../vendor/detain/myadmin-raid-backups/src/abuse.inc.php — no such file; function_requirements('get_abuse_licenses') will fatal (plugin='Detain\\MyAdminRaid\\Plugin', function='get_abuse_licenses', root='/home/sites/mystage/include', source='/../vendor/detain/myadmin-raid-backups/src/abuse.inc.php', resolved='/home/sites/mystage/include/../vendor/detain/myadmin-raid-backups/src/abuse.inc.php')
- **detain/myadmin-slack-chat**
  - [B-10] requirement "class.Slack" registers /../vendor/detain/myadmin-slack-chat/src/Slack.php, which resolves to /home/sites/mystage/include/../vendor/detain/myadmin-slack-chat/src/Slack.php — no such file; function_requirements('class.Slack') will fatal (plugin='Detain\\MyAdminSlack\\Plugin', function='class.Slack', root='/home/sites/mystage/include', source='/../vendor/detain/myadmin-slack-chat/src/Slack.php', resolved='/home/sites/mystage/include/../vendor/detain/myadmin-slack-chat/src/Slack.php')
  - [B-10] requirement "deactivate_kcare" registers /../vendor/detain/myadmin-slack-chat/src/abuse.inc.php, which resolves to /home/sites/mystage/include/../vendor/detain/myadmin-slack-chat/src/abuse.inc.php — no such file; function_requirements('deactivate_kcare') will fatal (plugin='Detain\\MyAdminSlack\\Plugin', function='deactivate_kcare', root='/home/sites/mystage/include', source='/../vendor/detain/myadmin-slack-chat/src/abuse.inc.php', resolved='/home/sites/mystage/include/../vendor/detain/myadmin-slack-chat/src/abuse.inc.php')
  - [B-10] requirement "deactivate_abuse" registers /../vendor/detain/myadmin-slack-chat/src/abuse.inc.php, which resolves to /home/sites/mystage/include/../vendor/detain/myadmin-slack-chat/src/abuse.inc.php — no such file; function_requirements('deactivate_abuse') will fatal (plugin='Detain\\MyAdminSlack\\Plugin', function='deactivate_abuse', root='/home/sites/mystage/include', source='/../vendor/detain/myadmin-slack-chat/src/abuse.inc.php', resolved='/home/sites/mystage/include/../vendor/detain/myadmin-slack-chat/src/abuse.inc.php')
  - [B-10] requirement "get_abuse_licenses" registers /../vendor/detain/myadmin-slack-chat/src/abuse.inc.php, which resolves to /home/sites/mystage/include/../vendor/detain/myadmin-slack-chat/src/abuse.inc.php — no such file; function_requirements('get_abuse_licenses') will fatal (plugin='Detain\\MyAdminSlack\\Plugin', function='get_abuse_licenses', root='/home/sites/mystage/include', source='/../vendor/detain/myadmin-slack-chat/src/abuse.inc.php', resolved='/home/sites/mystage/include/../vendor/detain/myadmin-slack-chat/src/abuse.inc.php')

### B-12 — 1 package(s)

- **detain/myadmin-powerdns**
  - [B-12] Detain\MyAdminPowerDns\Plugin::getSettings() ran but registered no settings at all (class='Detain\\MyAdminPowerDns\\Plugin', method='getSettings', settings=0)

## Grid

`.` pass · `o` not applicable (ran; nothing of this kind here) · `F` fail · `-` skip (could not run) · `?` not run

| package | A-1 | A-2 | A-3 | A-4 | A-5 | A-6 | A-7 | A-8 | A-9 | B-9 | B-9b | B-10 | B-11 | B-12 | B-13 | B-14 | B-15 |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| abuse-plugin | . | . | . | . | . | . | . | . | . | . | . | . | . | . | . | o | . |
| amazon-payments | . | . | . | . | . | . | . | . | . | . | . | . | o | . | . | o | . |
| authorizenet-payments | . | . | . | . | . | . | . | . | . | . | . | . | . | . | . | o | . |
| backups-module | . | . | . | . | . | . | . | . | . | . | . | . | o | . | o | o | . |
| cloudlinux-licensing | . | . | . | . | . | . | **F** | . | . | . | **F** | . | . | . | . | o | . |
| cpanel-licensing | . | . | . | . | . | . | . | . | . | . | . | **F** | . | . | . | o | . |
| cpanel-vps-addon | . | . | . | . | . | . | . | . | . | . | . | . | . | . | o | o | . |
| cpanel-webhosting | . | . | . | . | . | . | . | . | . | . | . | . | . | . | . | o | . |
| directadmin-licensing | . | . | . | . | . | . | . | . | . | . | . | . | . | . | . | o | . |
| directadmin-storage | . | . | . | . | . | . | . | . | . | . | . | . | o | . | . | o | . |
| directadmin-vps-addon | . | . | . | . | . | . | . | . | . | . | . | . | . | . | o | o | . |
| directadmin-webhosting | . | . | . | . | . | . | . | . | . | . | . | . | o | . | . | o | . |
| docker-vps | . | . | . | . | . | . | . | . | . | . | . | . | o | . | o | - | . |
| domains-module | . | . | . | . | . | . | . | . | . | . | . | . | o | . | o | o | . |
| drbl-backups | . | . | . | . | . | . | . | . | . | . | . | **F** | o | o | . | o | . |
| fantastico-licensing | . | . | . | . | . | . | . | . | . | . | . | **F** | . | . | . | o | . |
| fantastico-vps-addon | . | . | . | . | . | . | . | . | . | . | . | . | . | . | o | o | . |
| floating-ips-module | . | . | . | . | . | . | . | . | . | . | . | . | o | . | o | o | . |
| fraudrecord-plugin | . | . | . | . | . | . | . | . | . | . | . | . | . | . | . | o | . |
| globalsign-ssl | . | . | . | . | . | . | . | . | . | . | . | . | o | . | . | o | . |
| gluster-backups | . | . | . | . | . | . | . | . | . | . | . | **F** | o | o | . | o | . |
| google-analytics | . | . | . | . | . | . | . | . | . | . | . | **F** | o | o | . | o | . |
| googlecheckout-payments | . | . | . | . | . | . | . | . | . | . | . | . | . | . | . | o | . |
| googlewallet-payments | . | . | . | . | . | . | . | . | . | . | . | **F** | o | . | . | o | . |
| hd-vps-addon | . | . | . | . | . | . | . | . | . | . | . | . | . | . | o | o | . |
| hotjar-analytics | . | . | . | . | . | . | . | . | . | . | . | **F** | o | o | . | o | . |
| hyperv-vps | . | . | . | . | . | . | . | . | . | . | . | . | o | . | o | o | . |
| icontact-mailinglist | . | . | . | . | . | . | . | . | . | . | . | . | o | . | o | o | . |
| ip-webhosting-addon | . | . | . | . | . | . | . | . | . | . | . | . | o | . | o | o | . |
| ips-vps-addon | . | . | . | . | . | . | . | . | . | . | . | . | . | . | o | o | . |
| kayako-chat | . | . | . | . | . | . | . | . | . | . | . | **F** | o | o | . | o | . |
| kayako-support | . | . | . | . | . | . | . | . | . | . | . | **F** | o | . | . | o | . |
| ksplice-licensing | . | . | . | . | . | . | . | . | . | . | . | . | o | . | . | o | . |
| kvm-vps | . | . | . | . | . | . | . | . | . | . | . | . | o | . | o | . | . |
| licenses-module | . | . | . | . | . | . | . | . | . | . | . | . | o | . | o | o | . |
| litespeed-licensing | . | . | . | . | . | . | . | . | . | . | . | . | . | . | . | o | . |
| lxc-vps | . | . | . | . | . | . | . | . | . | . | . | . | o | . | o | - | . |
| mail-module | . | . | . | . | . | . | . | . | . | . | . | . | o | . | o | o | . |
| maxmind-plugin | . | . | . | . | . | . | . | . | . | . | . | . | . | . | . | o | . |
| modernbill-plugin | . | . | . | . | . | . | . | . | . | . | . | . | . | o | . | o | . |
| monitoring-plugin | . | . | . | . | . | . | . | . | . | . | . | . | . | o | . | o | . |
| novnc-plugin | . | . | . | . | . | . | . | . | . | . | . | **F** | o | o | . | o | . |
| opensrs-domains | . | . | . | . | . | . | . | . | . | . | . | . | o | . | . | o | . |
| openvz-vps | . | . | . | . | . | . | . | . | . | . | . | . | o | . | o | - | . |
| parallels-licensing | . | . | . | . | . | . | . | . | . | . | . | . | o | . | o | o | . |
| patchman-licensing | . | . | . | . | . | . | . | . | . | . | . | . | . | . | . | o | . |
| paypal-payments | . | . | . | . | . | . | . | . | . | . | . | . | . | . | . | o | . |
| payum-payments | . | . | . | . | . | . | . | . | . | . | . | **F** | . | o | . | o | . |
| payza-payments | . | . | . | . | . | . | . | . | . | . | . | . | . | . | . | o | . |
| piwik-analytics | . | . | . | . | . | . | . | . | . | . | . | **F** | o | o | . | o | . |
| plesk-webhosting | . | . | . | . | . | . | . | . | . | . | . | . | o | . | . | o | . |
| pleskautomation-webhosting | . | . | . | . | . | . | . | . | . | . | . | . | o | . | . | o | . |
| powerdns | . | . | . | . | . | . | . | . | . | . | . | **F** | . | **F** | . | o | . |
| quickservers-module | . | . | . | . | . | . | . | . | . | . | . | . | o | . | o | o | . |
| raid-backups | . | . | . | . | . | . | . | . | . | . | . | **F** | o | o | . | o | . |
| sendy-mailinglist | . | . | . | . | . | . | . | . | . | . | . | . | o | . | o | o | . |
| servers-module | . | . | . | . | . | . | . | . | . | . | . | . | o | . | o | o | . |
| slack-chat | . | . | . | . | . | . | . | . | . | . | . | **F** | o | o | . | o | . |
| softaculous-licensing | . | . | . | . | . | . | . | . | . | . | . | . | . | . | . | o | . |
| softaculous-vps-addon | . | . | . | . | . | . | . | . | . | . | . | . | . | . | o | o | . |
| ssl-module | . | . | . | . | . | . | . | . | . | . | . | . | o | . | o | o | . |
| swift-backups | . | . | . | . | . | . | . | . | . | . | . | . | o | . | . | o | . |
| virtuozzo-vps | . | . | . | . | . | . | . | . | . | . | . | . | o | . | o | - | . |
| vps-module | . | . | . | . | . | . | . | . | . | . | . | . | o | . | o | o | . |
| webhosting-module | . | . | . | . | . | . | . | . | . | . | . | . | o | . | o | o | . |
| webuzo-vps | . | . | . | . | . | . | . | . | . | . | . | . | . | o | . | o | . |
| whmsonic-licensing | . | . | . | . | . | . | . | . | . | . | . | . | . | . | . | o | . |
| xen-vps | . | . | . | . | . | . | . | . | . | . | . | . | o | . | o | - | . |
| zonemta-mail | . | . | . | . | . | . | . | . | . | . | . | . | o | . | . | o | . |
| payssion-payments | . | . | . | . | . | . | . | . | . | . | . | . | . | o | . | o | . |
| scrub-ips-module | . | . | . | . | . | . | . | . | . | . | . | . | o | . | o | o | . |
