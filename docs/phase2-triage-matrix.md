# Phase 2 — fleet triage matrix (gate G2)

**18 assertions x 71 packages = 1278 cells** — 1038 pass, 3 fail, 5 skip, 232 not applicable.

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
| B-10 | 53 | 0 | 0 | 18 | dangling requirement paths · n/a: registers no requirement paths at all |
| B-11 | 27 | 0 | 0 | 44 | n/a: registers no routes, or no function.requirements handler |
| B-12 | 56 | 0 | 0 | 15 | n/a: orphaned getSettings — core can never invoke it |
| B-13 | 43 | 0 | 0 | 28 | n/a: no getMenu() |
| B-14 | 1 | 0 | 5 | 65 | n/a: no getQueue(), or not a service · the 5 skips are dynamic dispatch B-14 cannot read |
| B-15 | 71 | 0 | 0 | 0 |  |
| B-16 | 8 | 1 | 0 | 62 | n/a: no apiRegister() — 9 of 71 publish an API surface · the 1 failure registers the hook and then registers nothing |

## Escape hatches

No package overrides a contract default. Every cell above was measured
against the assertion as written.

## Deferrals

No package defers a catalogue assertion. Every failing cell above is an
open P-bug with nobody's agreement to leave it open.

## Failing cells, classified (all P-bugs — report only, per D7)

### A-7 — 1 package(s)

- **detain/myadmin-cloudlinux-licensing**
  - [A-7] Detain\MyAdminCloudlinux\Plugin registers hook key "plugin.install", whose prefix is "plugin", but the plugin declares $module = "licenses". The hook registers under a prefix nothing dispatches to. Expected the key to start with "licenses." or to be one of the global hooks. (class='Detain\\MyAdminCloudlinux\\Plugin', key='plugin.install', prefix='plugin', module='licenses', problem='prefix-mismatch', dispatched=false)
  - [A-7] Detain\MyAdminCloudlinux\Plugin registers hook key "plugin.uninstall", whose prefix is "plugin", but the plugin declares $module = "licenses". The hook registers under a prefix nothing dispatches to. Expected the key to start with "licenses." or to be one of the global hooks. (class='Detain\\MyAdminCloudlinux\\Plugin', key='plugin.uninstall', prefix='plugin', module='licenses', problem='prefix-mismatch', dispatched=false)

### B-9b — 1 package(s)

- **detain/myadmin-cloudlinux-licensing**
  - [B-9b] hook "plugin.install" is never dispatched: it is not one of the literal keys (account.activated, ui.menu, system.settings, mailinglist.subscribe, function.requirements, api.register, licenses.deactivate_key, licenses.deactivate_ip, licenses.change_ip) and its suffix is not one of the per-module suffixes (load_processing, load_addons, queue, activate, settings, deactivate, reactivate, terminate). The handler is dead code — either the dispatch site was removed, or the key is a typo, or a new dispatch site needs adding to TierB9bHookKeysDispatched (plugin='Detain\\MyAdminCloudlinux\\Plugin', hook='plugin.install')
  - [B-9b] hook "plugin.uninstall" is never dispatched: it is not one of the literal keys (account.activated, ui.menu, system.settings, mailinglist.subscribe, function.requirements, api.register, licenses.deactivate_key, licenses.deactivate_ip, licenses.change_ip) and its suffix is not one of the per-module suffixes (load_processing, load_addons, queue, activate, settings, deactivate, reactivate, terminate). The handler is dead code — either the dispatch site was removed, or the key is a typo, or a new dispatch site needs adding to TierB9bHookKeysDispatched (plugin='Detain\\MyAdminCloudlinux\\Plugin', hook='plugin.uninstall')

### B-16 — 1 package(s)

- **detain/myadmin-webhosting-module**
  - [B-16] Detain\MyAdminWebhosting\Plugin::apiRegister() is registered on "api.register" but ran and registered no API calls, complex types or arrays at all. Core dispatches this handler on every API request; an empty one is either dead scaffolding that should be deleted along with its hook entry, or a surface that was lost. (class='Detain\\MyAdminWebhosting\\Plugin', method='apiRegister', registrations=0, hookKeys='api.register')

## Grid

`.` pass · `o` not applicable (ran; nothing of this kind here) · `F` fail · `-` skip (could not run) · `?` not run

| package | A-1 | A-2 | A-3 | A-4 | A-5 | A-6 | A-7 | A-8 | A-9 | B-9 | B-9b | B-10 | B-11 | B-12 | B-13 | B-14 | B-15 | B-16 |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| abuse-plugin | . | . | . | . | . | . | . | . | . | . | . | . | . | . | . | o | . | o |
| amazon-payments | . | . | . | . | . | . | . | . | . | . | . | . | o | . | . | o | . | o |
| authorizenet-payments | . | . | . | . | . | . | . | . | . | . | . | . | . | . | . | o | . | o |
| backups-module | . | . | . | . | . | . | . | . | . | . | . | o | o | . | o | o | . | o |
| cloudlinux-licensing | . | . | . | . | . | . | **F** | . | . | . | **F** | . | . | . | . | o | . | o |
| cpanel-licensing | . | . | . | . | . | . | . | . | . | . | . | . | . | . | . | o | . | o |
| cpanel-vps-addon | . | . | . | . | . | . | . | . | . | . | . | . | . | . | o | o | . | o |
| cpanel-webhosting | . | . | . | . | . | . | . | . | . | . | . | . | . | . | . | o | . | . |
| directadmin-licensing | . | . | . | . | . | . | . | . | . | . | . | . | . | . | . | o | . | o |
| directadmin-storage | . | . | . | . | . | . | . | . | . | . | . | . | o | . | . | o | . | . |
| directadmin-vps-addon | . | . | . | . | . | . | . | . | . | . | . | . | . | . | o | o | . | o |
| directadmin-webhosting | . | . | . | . | . | . | . | . | . | . | . | . | o | . | . | o | . | . |
| docker-vps | . | . | . | . | . | . | . | . | . | . | . | o | o | . | o | - | . | o |
| domains-module | . | . | . | . | . | . | . | . | . | . | . | o | o | . | o | o | . | o |
| drbl-backups | . | . | . | . | . | . | . | . | . | . | . | . | o | o | . | o | . | o |
| fantastico-licensing | . | . | . | . | . | . | . | . | . | . | . | . | . | . | . | o | . | o |
| fantastico-vps-addon | . | . | . | . | . | . | . | . | . | . | . | . | . | . | o | o | . | o |
| floating-ips-module | . | . | . | . | . | . | . | . | . | . | . | o | o | . | o | o | . | o |
| fraudrecord-plugin | . | . | . | . | . | . | . | . | . | . | . | . | . | . | . | o | . | o |
| globalsign-ssl | . | . | . | . | . | . | . | . | . | . | . | . | o | . | . | o | . | o |
| gluster-backups | . | . | . | . | . | . | . | . | . | . | . | . | o | o | . | o | . | o |
| google-analytics | . | . | . | . | . | . | . | . | . | . | . | . | o | o | . | o | . | o |
| googlecheckout-payments | . | . | . | . | . | . | . | . | . | . | . | . | . | . | . | o | . | o |
| googlewallet-payments | . | . | . | . | . | . | . | . | . | . | . | . | o | . | . | o | . | o |
| hd-vps-addon | . | . | . | . | . | . | . | . | . | . | . | . | . | . | o | o | . | o |
| hotjar-analytics | . | . | . | . | . | . | . | . | . | . | . | . | o | o | . | o | . | o |
| hyperv-vps | . | . | . | . | . | . | . | . | . | . | . | o | o | . | o | o | . | o |
| icontact-mailinglist | . | . | . | . | . | . | . | . | . | . | . | o | o | . | o | o | . | o |
| ip-webhosting-addon | . | . | . | . | . | . | . | . | . | . | . | o | o | . | o | o | . | o |
| ips-vps-addon | . | . | . | . | . | . | . | . | . | . | . | . | . | . | o | o | . | o |
| kayako-chat | . | . | . | . | . | . | . | . | . | . | . | . | o | o | . | o | . | o |
| kayako-support | . | . | . | . | . | . | . | . | . | . | . | . | o | . | . | o | . | . |
| ksplice-licensing | . | . | . | . | . | . | . | . | . | . | . | . | o | . | . | o | . | o |
| kvm-vps | . | . | . | . | . | . | . | . | . | . | . | o | o | . | o | . | . | o |
| licenses-module | . | . | . | . | . | . | . | . | . | . | . | . | o | . | o | o | . | . |
| litespeed-licensing | . | . | . | . | . | . | . | . | . | . | . | . | . | . | . | o | . | o |
| lxc-vps | . | . | . | . | . | . | . | . | . | . | . | o | o | . | o | - | . | o |
| mail-module | . | . | . | . | . | . | . | . | . | . | . | o | o | . | o | o | . | o |
| maxmind-plugin | . | . | . | . | . | . | . | . | . | . | . | . | . | . | . | o | . | o |
| modernbill-plugin | . | . | . | . | . | . | . | . | . | . | . | . | . | o | . | o | . | o |
| monitoring-plugin | . | . | . | . | . | . | . | . | . | . | . | . | . | o | . | o | . | o |
| novnc-plugin | . | . | . | . | . | . | . | . | . | . | . | . | o | o | . | o | . | o |
| opensrs-domains | . | . | . | . | . | . | . | . | . | . | . | . | o | . | . | o | . | o |
| openvz-vps | . | . | . | . | . | . | . | . | . | . | . | o | o | . | o | - | . | o |
| parallels-licensing | . | . | . | . | . | . | . | . | . | . | . | . | o | . | o | o | . | o |
| patchman-licensing | . | . | . | . | . | . | . | . | . | . | . | . | . | . | . | o | . | o |
| paypal-payments | . | . | . | . | . | . | . | . | . | . | . | . | . | . | . | o | . | o |
| payum-payments | . | . | . | . | . | . | . | . | . | . | . | . | o | o | . | o | . | o |
| payza-payments | . | . | . | . | . | . | . | . | . | . | . | . | . | . | . | o | . | o |
| piwik-analytics | . | . | . | . | . | . | . | . | . | . | . | . | o | o | . | o | . | o |
| plesk-webhosting | . | . | . | . | . | . | . | . | . | . | . | . | o | . | . | o | . | o |
| pleskautomation-webhosting | . | . | . | . | . | . | . | . | . | . | . | . | o | . | . | o | . | o |
| powerdns | . | . | . | . | . | . | . | . | . | . | . | . | . | o | . | o | . | . |
| quickservers-module | . | . | . | . | . | . | . | . | . | . | . | o | o | . | o | o | . | o |
| raid-backups | . | . | . | . | . | . | . | . | . | . | . | . | o | o | . | o | . | o |
| sendy-mailinglist | . | . | . | . | . | . | . | . | . | . | . | o | o | . | o | o | . | o |
| servers-module | . | . | . | . | . | . | . | . | . | . | . | o | o | . | o | o | . | o |
| slack-chat | . | . | . | . | . | . | . | . | . | . | . | . | o | o | . | o | . | o |
| softaculous-licensing | . | . | . | . | . | . | . | . | . | . | . | . | . | . | . | o | . | o |
| softaculous-vps-addon | . | . | . | . | . | . | . | . | . | . | . | . | . | . | o | o | . | o |
| ssl-module | . | . | . | . | . | . | . | . | . | . | . | o | o | . | o | o | . | o |
| swift-backups | . | . | . | . | . | . | . | . | . | . | . | . | o | . | . | o | . | o |
| virtuozzo-vps | . | . | . | . | . | . | . | . | . | . | . | o | o | . | o | - | . | o |
| vps-module | . | . | . | . | . | . | . | . | . | . | . | . | o | . | o | o | . | . |
| webhosting-module | . | . | . | . | . | . | . | . | . | . | . | . | o | . | o | o | . | **F** |
| webuzo-vps | . | . | . | . | . | . | . | . | . | . | . | . | . | o | . | o | . | o |
| whmsonic-licensing | . | . | . | . | . | . | . | . | . | . | . | . | . | . | . | o | . | o |
| xen-vps | . | . | . | . | . | . | . | . | . | . | . | o | o | . | o | - | . | o |
| zonemta-mail | . | . | . | . | . | . | . | . | . | . | . | . | o | . | . | o | . | . |
| payssion-payments | . | . | . | . | . | . | . | . | . | . | . | . | . | o | . | o | . | o |
| scrub-ips-module | . | . | . | . | . | . | . | . | . | . | . | o | o | . | o | o | . | o |
