# Swamp runtime maintenance

The canonical native Linux worker artifact is `Swamp` in `extensions/behaviors/node-worker/install.ts`. Keep its version, SHA-256 and byte count together. `.swamp.yaml` records the serve runtime version. The retired Rust worker installer is frozen and is not the native rollout path.

## Pinned release

[20261031.123000.0-sha.614ab5ff](https://github.com/hardimpactdev/swamp/releases/tag/v20261031.123000.0-sha.614ab5ff) is a reviewed fork build from exact source `614ab5ff535eb146879f907fbfd9eb17ac115205`, based on official e459d219. It is not an official stable release. The Linux x86_64 artifact is405,881,080bytes with SHA-256 `47546faadc8051df2ac59ce2259c2f8136c3ba15958632090fc65f3e1e04efca`. The canonical pin includes the release repository so the installer downloads the matching reviewed artifact. Verify the digest, byte count and `--version` before publication. The attached public manifest records source and checks without host paths or credentials.

The delta accepts remote `vault.put` values on stdin and supplies method context to vault authorization. Exact put-only grants cannot authorize delete or annotate, and restricted grants fail closed without method context. This source change creates no grant or credential. The workflow-only resolution scope remains a separate approval.

The unchanged official compile/dashboard sequence produced this artifact. Its source vault/access232 and reload58 tests passed. Compiled-binary scratch tests passed for remote scripts, environment, history, hot reload, exact-key stdin put and denied CA put/delete/annotate/raw read. These are synthetic tests, not live runtime acceptance. Source publication does not authorize a restart or worker rollout.

## Official base release

The base is [20261031.063736.0-sha.e459d219](https://github.com/swamp-club/swamp/releases/tag/v20261031.063736.0-sha.e459d219), published2026-10-31. Its Linux artifact is405,875,640bytes with SHA-256 `bfd72a112412ce48d4c11e8e80a35caefa811683146f7e30795d498382b20768`.

The official updater stable channel returned1a4e8b36 during this upgrade, while GitHub already published e459d219. Check both sources; a daily report must not call a lagging channel current.

- Upstream PR3523, included through1a4e8b36, reconciles repository-local extensions indexed by another CLI. Reload now refreshes local validators and dispatch bundles, continues other reload work after a local refresh failure, and rebuilds audit sinks for the resulting configuration. Report reload errors; an ACK alone does not establish registry equality.
- Scheduler PR3431/lab4048 shipped in731913b2 on2026-10-27 and is already included in1602a617. Every queued cron admission rechecks the workflow pause state. Preserve existing pauses and do not add a scheduler deny grant.
- e459d219 adds `collective:<slug>` grant subjects. Default deny is unchanged. Upgrade every serve host sharing a datastore before using these subjects; an older host ignores them, including denies. This rollout adds no grant.
- The release range adds v3 team/follower, lease and offline behavior. Do not change datastore generation, enable team mode or migrate storage as part of a binary upgrade. Snapshot the existing datastore and config before starting the new runtime. Never restore a snapshot over later writes.

## Load and upgrade

Publish clean current main with native `swamp serve reload`, batching merges at most once per five minutes. Preserve runtime configuration, credentials, grants, pause state and protected material. Compare changed behavior versions served by the running registry with disk after each reload; same-version helper changes also need the normal source consistency checks. Version equality alone does not prove identical executed JavaScript.

A native binary update needs a restart. Obtain runtime GO and announce it first, batch all merged changes, and permit at most one full restart per60minutes. Check actual `gateway-release-auto-status` and queued/running release records inside the existing `GatewayReleaseLock::run`; hold that lock through the foreground managed Node209 call so no Gateway/Caddy handoff overlaps the restart. Drain with native shutdown handling. Stop all209 processes using the shared home binary in the same maintenance window, retain the old artifact and a consistent datastore backup, replace atomically, start, and check configured-CA HTTPS, authenticated WSS, worker roster and served/disk behavior versions. Restore the old binary on startup failure before investigating new code. Keep source and pause state unchanged during recovery.

Use the native installer with existing exact Node identity and `credentials=null` to upgrade persistent workers. Preserve credential custody. The worker owner alone rolls209 in the announced serve window, then9,13,12,17,4,14 sequentially after health, memory and roster checks, once a supported authorized installer route exists. Include installed209-hosted proxy units1/18 in that owner’s coordination because they share the persistent worker binary. Local Nodes1 and18 remain masked and excluded. A frozen or denied installer route does not permit manual copying or full converge. Do not run full Node converge or copy worker binaries manually. Serve and its three migration/services workers share `/home/orbit/.local/bin/swamp`; persistent workers use `/usr/local/bin/swamp`.

Update the Beast CLI from the verified matching Linux artifact. The fork build currently provides Linux x86_64 only; keep mini’s verified official Darwin CLI until a matching architecture-specific artifact is built and verified. After rollout, each family owns one safe workflow verification on the new runtime. Daily release checks report availability and never update or restart automatically.
