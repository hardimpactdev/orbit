# Swamp runtime maintenance

The canonical native Linux worker artifact is `Swamp` in `extensions/behaviors/node-worker/install.ts`. Keep its version, SHA-256 and byte count together. `.swamp.yaml` records the serve runtime version. The retired Rust worker installer is frozen and is not the native rollout path.

## Pinned release

[20261031.063736.0-sha.e459d219](https://github.com/swamp-club/swamp/releases/tag/v20261031.063736.0-sha.e459d219), published2026-10-31, replaces serve and worker release1602a617. The Linux x86_64 artifact is405,875,640bytes with SHA-256 `bfd72a112412ce48d4c11e8e80a35caefa811683146f7e30795d498382b20768`. Verify both the digest and `--version` before publication.

The official updater stable channel returned1a4e8b36 during this upgrade, while GitHub already published e459d219. Check both sources; a daily report must not call a lagging channel current.

- Upstream PR3523, included through1a4e8b36, reconciles repository-local extensions indexed by another CLI. Reload now refreshes local validators and dispatch bundles, continues other reload work after a local refresh failure, and rebuilds audit sinks for the resulting configuration. Report reload errors; an ACK alone does not establish registry equality.
- Scheduler PR3431/lab4048 shipped in731913b2 on2026-10-27 and is already included in1602a617. Every queued cron admission rechecks the workflow pause state. Preserve existing pauses and do not add a scheduler deny grant.
- e459d219 adds `collective:<slug>` grant subjects. Default deny is unchanged. Upgrade every serve host sharing a datastore before using these subjects; an older host ignores them, including denies. This rollout adds no grant.
- The release range adds v3 team/follower, lease and offline behavior. Do not change datastore generation, enable team mode or migrate storage as part of a binary upgrade. Snapshot the existing datastore and config before starting the new runtime. Never restore a snapshot over later writes.

## Load and upgrade

Publish clean current main with native `swamp serve reload`, batching merges at most once per five minutes. Preserve runtime configuration, credentials, grants, pause state and protected material. Compare changed behavior versions served by the running registry with disk after each reload; same-version helper changes also need the normal source consistency checks. Version equality alone does not prove identical executed JavaScript.

A native binary update needs a restart. Announce it first, batch all merged changes, and permit at most one full restart per60minutes. Drain with native shutdown handling. Stop all209 processes using the shared home binary in the same maintenance window, retain the old artifact and a consistent datastore backup, replace atomically, start, and check configured-CA HTTPS, authenticated WSS, worker roster and served/disk behavior versions. Restore the old binary on startup failure before investigating new code. Keep source and pause state unchanged during recovery.

Use the native installer with existing exact Node identity and `credentials=null` to upgrade persistent workers. Preserve credential custody. Roll209 in the announced serve window, then9,13,12,17,4,14 sequentially after health, memory and roster checks. Nodes1 and18 remain excluded. Do not run full Node converge or copy worker binaries manually. Serve and its three migration/services workers share `/home/orbit/.local/bin/swamp`; persistent workers use `/usr/local/bin/swamp`.

Update the Beast CLI and install the correct architecture-specific Darwin CLI on mini using verified official artifacts. After rollout, each family owns one safe workflow verification on the new runtime. Daily release checks report availability and never update or restart automatically.
