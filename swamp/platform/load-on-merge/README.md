# Normal Node209 load

The publisher calls `interlocked_load.run(announcement)` after it has announced the exact target and staged the existing signed-off source feed. The Gateway holds its real release lock across the foreground host load, native manifest reconciliation, configured-token metadata proof and final feed acceptance. The publisher must retain its local custody lock for this entire call.

The host load also takes the existing `serve-load.lock`. Production deploys and an actual QUIET record defer every publication mode before source changes. Workflow changes restart Serve directly while Lab #4223 is open. Other changes reload; an incomplete or mismatched audit permits one immediate Serve restart. A failed restart halts. There is no restart spacing floor. Worker capacity and configuration are not changed.

`config/gateway-workflow-grants.json` is the only scope list. Native grant and group metadata is backed up before missing exact actions are created. The existing issuer operator token file supplies administrative authority. The Gateway's unchanged configured run token then proves the principal, exact grant/action/resource/condition decisions and registered workflow identities without starting a business run or reading a vault value. The feed is consumed only after this proof. Runtime evidence is saved separately from `last-proof.json`, the accepted pointer read by the existing publisher; an unfinished token proof therefore remains eligible for continuation. Existing unrelated grants and groups remain unchanged.

A pending mutation or failed checkpoint requires inspection. Do not rerun a create or reload after an uncertain response. A completed `applied` checkpoint can continue with reads only. A failed configured-token proof leaves the ready feed pending and does not trigger another restart. Deleted-source served types are listed as accepted Lab #4223 exceptions; a mismatch or missing type still present in source blocks acceptance.

## Deployment mapping

These files track the existing operational loader plus the new grant boundary. Merging source does not install them. In the next authorized loader adoption, install `loader.py` on Node209 as `/usr/local/libexec/orbit-swamp-main-load.py`, with `manifest_grants.py`, `guarded_registry_restart.py`, `immediate_load_policy.py`, `behavior_versions.py`, `behavior_versions_reader.ts`, `current_source_inventory.py` and `load_events.py` beside it. Install the Python files as root-owned code, not writable by the Node account. The existing SSH sudo path runs the loader as root; the loader uses the existing Node account for repository and runtime metadata commands.

On the publisher host, install `interlocked_load.py`, `gateway_load.php` and `configured_policy_proof.php` together in the existing publisher module directory. Preserve the publisher, feed, SSH route, token files, units, pauses and configuration. Replace the helper set together under publisher custody and the real Gateway release interlock. Do not run the new entry point against a partially installed helper set.

The fixed production paths in this module describe the existing Node209 installation. They are not a new fleet installer or Gateway command. This PR makes no installed-runtime or live-policy acceptance claim.

## Tests

From the repository root:

```sh
python3 -B -m unittest discover -s swamp/platform/load-on-merge -p 'test_*.py'
php swamp/platform/load-on-merge/test_configured_policy_proof.php
```
