# Services runtime retention and compaction

The group supervisor used to admit every active factory entry every minute,
including unchanged human waits. Each generation writes claim, owner, tick,
method-output and workflow-report history. Discovery also ran a full-history
`run doctor --fix` and materialized factory status on every minute. The new
path reads state, excludes terminal/completed work, skips unchanged human waits,
and rotates at most four supervisors per pass. Native per-group ownership and
all effect keys remain unchanged.

Per-tick and driver resource policies keep one version. Latest launch receipts
have infinite lifetime: an old but still-live launch must never expire. Existing
metadata keeps its old policy until written again. The receipt-aware compactor
also enforces the new policy against those existing versions.

## What compaction removes

`bin/compact-services.ts` supports the pinned filesystem datastore only. The offline entry
refuses redirected datastores, symlinks and an active local Swamp process, including
serve started elsewhere with `--repo-dir`. It holds the native datastore lock. It
uses the native catalog and parses the actual workflow records. Default is a
read-only JSON plan; `--apply` recomputes the plan before applying it.

- For `@nckrtl/orbit-group-run`, `orbit-group-runs`, `orbit-gateway`,
  `orbit-driver` and workflow data: superseded versions, except exact versions
  referenced by unfinished workflows or current live receipts.
- Generated reports older than seven days, except pinned versions. Latest
  durable receipts, factory state/evidence/artifacts/approvals, credentials,
  worker enrollments and access grants are preserved. Dependency v1 indexes
  and reports keep their separate existing retention policy.
- Terminal workflow records older than seven days, unless a live receipt or
  unfinished workflow references them. Referenced children are preserved too.
  Their per-run evaluated snapshots are removed with them. Shared evaluated
  definitions remain. Unreferenced method-output records older than seven days
  are removed; live-run method outputs remain.
- Auto-created group and recovery definitions whose factory states are all
  terminal for seven days, with no live owner/reference, move into
  `.swamp/retired-group-definitions/`. Their latest receipt data remains intact.
  Tracked definitions are never removed.

Older terminal history can name a superseded diagnostic version that has been
compacted. Recovery evidence for live work is preserved. The script records
its exact removal plan, invalidates the derived catalog before deleting any
version, and preserves version high-water marks. The next native query rebuilds
the catalog after an offline pass. Online passes use native repository deletion
and never invalidate the shared catalog. A crash can leave extra data; it cannot make deleted versions look
present in a stale catalog or cause their version numbers to be reused.

## Operator procedure

Run on Services as its existing account. No credential file is read by this
script. Use the existing deploy/runbook procedure to pause schedules, drain or
checkpoint live work, and stop serve. Preserve the existing paused-schedule
snapshot; do not clear receipts or reset factories. Take a recoverable datastore
snapshot before applying compaction. Keep other Swamp CLI writers stopped too.

From the Services checkout, using its installed Deno runtime:

```sh
~/.swamp/deno/deno run -A bin/compact-services.ts > /tmp/services-compaction-preview.json
~/.swamp/deno/deno run -A bin/compact-services.ts --apply > /tmp/services-compaction-result.json
swamp data query 'modelName == "orbit-gateway"' --select name --json > /tmp/services-compaction-query.json
```

Unset server URL variables for the local query. On restart, run local
`swamp run doctor --fix --json` once before resuming schedules, then restore
only the schedules in the saved snapshot. Check the active/queued run counts,
Gateway receipts and one existing group's owner before resuming normal work.
The online `runtime-retention` schedule is paused by Nick's decision on 2026-10-29: its bounded passes produced no removals and consumed CPU. Keep it paused. Preserve every other schedule's saved state, including the historically paused `subtasks-driver`. Do not pause lane 13's separate `observation-retention` schedule. The planner code and checkpoint remain until a later cleanup PR.

## Repeat-compaction runbook

Check Services free space at least every 12 hours through the existing fleet observation or `df -B1` on the Services checkout. The recent datastore growth sample was about 72 MB/h apparent and 173 MB/h allocated: up to about 2.1 GB per 12 hours. This is a short-window estimate; remeasure after each compaction. Arrange a maintenance window before available disk reaches **4 GB**, or sooner if projected growth will cross that threshold before the next window. Use decimal bytes for these thresholds.

Keep room for a fresh backup as well. The current maintenance helper requires free space greater than the datastore's apparent size plus 1 GiB before archiving; that reserve can trigger compaction earlier than 4 GB. A compressed archive may be much smaller, but do not assume a compression ratio. If the reserve is unavailable, stop before applying: use an approved backup destination or ask Nick which old verified backup may be removed. Never remove a backup or recovery evidence merely to force the check through.

1. On Services as `orbit`, save all schedule states in a protected snapshot. Pause the enabled schedules, let active runs and queued fires drain, then stop only `swamp-serve` (Process 281). Leave the databases, PowerSync, workers and other Processes running. Use the existing `bin/deploy-services` lock, pause/drain and restoration procedure; every failure must restore the saved states and restart serve. Keep other datastore writers out of the window.
2. With serve stopped, record `du -sb .swamp`, `du -s --block-size=1 .swamp` and `df -B1 .`. Take a fresh mode-0600 `.tar.zst` archive of `.swamp` in `/home/orbit/backups`. Record its path, byte size and SHA256. Require `zstd --test`, a complete `tar -tf` listing and a read-only catalog `PRAGMA quick_check` to pass before compaction. Verify the protected receipt manifest before proceeding; the 2026-10-29 baseline has 412 files. Preserve that manifest and include any new receipt roots in later windows.
3. Run only the preview first. Store its JSON outside `.swamp`. Stop if the command fails.

   ```sh
   unset SWAMP_SERVER_URL SWAMP_SERVE_URL
   ~/.swamp/deno/deno run -A bin/compact-services.ts > /tmp/services-compaction-preview.json
   ```

4. **Stop and check the preview before apply.** Compare every planned removal with the protected receipt manifest. A protected file or any parent version directory in the plan blocks compaction. The compactor does not perform this manifest comparison for you. Keep writers stopped; proceed only after this check passes. Run each command below only if the preceding command succeeded. Apply recomputes the plan; verify the receipt hashes immediately afterward, before continuing to the query. Never clear receipts, reset factories or run native GC.

   ```sh
   ~/.swamp/deno/deno run -A bin/compact-services.ts --apply > /tmp/services-compaction-result.json
   ```

   After the apply succeeds and the receipt hashes still match:

   ```sh
   set -eu
   swamp data query 'modelName == "orbit-gateway"' --select name --json > /tmp/services-compaction-query.json
   swamp run doctor --fix --json > /tmp/services-compaction-doctor.json
   ```

5. The query rebuilds the invalidated derived catalog before serve resumes. Record the after-size and free-space measurements. Restart serve and require authenticated readiness. Restore every saved schedule state, then explicitly keep `runtime-retention` paused. Compare all states with the snapshot, allowing only that requested pause. Verify the receipt hashes after restart and record a 20-second serve CPU/RSS sample, actual removed-version count and backup details. Clear the maintenance marker only after restoration is verified; on failure print the marker and exact saved resume commands for recovery.

Do not make compaction a new unattended schedule. Repeat it through an approved maintenance window based on free space and current growth. Keep `UPSTREAM-RETENTION.md` as a local draft; do not file it. Retiring the inactive online planner and its checkpoint is a separate later cleanup PR.

Native automatic metadata GC is enabled: workflow runs keep seven days, finished evaluated snapshots and method outputs keep one day. Native collectors retain unfinished runs and their snapshots. Ended native bookkeeping uses a one-hour grace; live work and referenced tokens remain protected by the native collector. The `data` retention key stays absent, so this policy never collects the exact data versions that receipts reference. Keep the receipt-aware offline compactor for data version cleanup. Do not substitute `data gc`, `data prune`, `behavior delete` or a recursive directory deletion for protected data compaction. Native `run gc` handles only run metadata, snapshots and outputs; see [serve liveness](serve-liveness.md) for its backup-first emergency procedure. `swamp datastore compact` only vacuums its SQLite catalog; it does not reclaim version/history files.

## Saturation correction (2026-10-28)

The first online pruner loaded all workflow snapshots into serve and filtered
the full snapshot array once per referenced run. The observed pass took
531 seconds; it starved the event loop even though deletions were capped.
The inactive executor indexes snapshots, run IDs, model names and output
provenance once. Planning runs outside serve with a fixed deadline. A child
failure preserves the remaining history for the next run; no receipt clearing
or broad GC is part of recovery. A deployment restart stops the old in-process
pass; updating code alone cannot move that already-running JavaScript frame.

## Inactive online planner

The 120-second child deadline is unchanged. Each pass uses at most 80 seconds for bounded scan, graph and prune work, leaving shutdown headroom. Progress is checkpointed in a private derived SQLite index at `.swamp/retention-planner.db`. It holds catalog and directory cursors, pending files, parsed records, graph edges and an expansion queue. Catalog and directory batches have at most 100 entries. A file or graph node completes individually; the next run continues its remaining queue. Large directories persist one complete listing in the checkpoint and consume it in 100-entry lexical batches, with a 250,000-entry safety bound. Writes during a listing pass do not reset its cursor. Only directories and YAML records enter the queue; logs remain governed by their owner records. Validation compares the names and fingerprints of YAML records and child directories before deletion. Log-only changes do not restart the reference graph. Changed YAML records still trigger a refresh, and unchanged file fingerprints do not requeue parsed records. New files before the old cursor and atomically replaced records are discovered by that refresh. No phase reparses the complete history into one array.

A budget yield publishes `partial: true` and succeeds with its actual removal counts. Nothing is deleted until receipt reachability is complete. Before pruning resumes, changed source directories and newly published data are refreshed, and new references are expanded. Source files remain authoritative; their inode, size, mtime and ctime invalidate cached parses. Malformed files, symlinks and redirected datastores fail closed. Latest state, current durable receipts, referenced completed children, their exact versions, fresh data and active writers remain protected. The online pass conservatively retains finished receipt roots too. Definition retirement remains in the existing offline compactor; the online fix does not remove model definitions.

Deletion uses native version APIs, catalog batches of at most 100 candidates, an 80-second work budget and a 5,000-path pass cap. Finished, unreferenced history older than seven days is pruned with its owned logs and evaluated snapshots; deferred cleanup records survive a restart. The `progress` resource reports phase, work units and actual removed versions/paths. Keep the checkpoint index across budget yields. Removing it merely restarts planning; it never authorizes deletion.

Upstream guidance: [Swamp repository retention](https://github.com/swamp-club/swamp/blob/main/design/surfaces/repo.md#retention) uses bounded batches and resumes at batch boundaries. [swamp-club#3178](https://github.com/swamp-club/swamp/pull/3178) fixes native cleanup cost and bounds automatic passes; it is already in the installed runtime. The newest checked release, `963e0d28`, changes remote datastore recovery and does not fix this extension's full-history YAML planner. Native automatic **data** retention remains unsuitable: it cannot follow Orbit's exact version references. Automatic metadata retention is enabled with no `data` key. No runtime upgrade or broader retention policy is needed for this correction.

### Indexed version-reference lookups

The native catalog has no index for a bare data ID or resource-name/version pair. A live lookup scanned its namespace and took 0.16–0.27 seconds, even for a resource outside the pruner's supported types. The planner now indexes reference keys and native row locators in its private checkpoint during the existing 100-row inventory batches. Graph expansion pages matching locators and cached aliases together in pages of 100 and checks each supported row by native rowid before reading its source. Pending promotion is checked on the authoritative row, including promotion without a new rowid; filtered pages still advance their cursor. A missing or changed row identity restarts the batched locator inventory before any deletion; parsed sources and graph pins remain intact. Unknown references refresh newly appended catalog rows before expansion completes.

An existing checkpoint backfills this metadata index through the inventory cursor. Parsed sources, graph pins, directory progress and the prune cursor are retained. Only locator metadata enters the index, with no resource bodies or credentials. The native catalog remains read-only and its schema is unchanged. Deadlines, removal limits and receipt protection rules stay the same.
