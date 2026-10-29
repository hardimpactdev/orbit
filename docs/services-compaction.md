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
~/.swamp/deno/deno run --allow-read=/proc,. --allow-write=. bin/compact-services.ts > /tmp/services-compaction-preview.json
~/.swamp/deno/deno run --allow-read=/proc,. --allow-write=. bin/compact-services.ts --apply > /tmp/services-compaction-result.json
swamp data query 'modelName == "orbit-gateway"' --select name --json > /tmp/services-compaction-query.json
```

Unset server URL variables for the local query. On restart, run local
`swamp run doctor --fix --json` once before resuming schedules, then restore
only the schedules in the saved snapshot. Check the active/queued run counts,
Gateway receipts and one existing group's owner before resuming normal work.
A shipped `runtime-retention` workflow runs hourly at minute 17, second 20,
under its own model lock. Serve dispatches a separate native CLI process; it
does not import the planner or parse history. The executor has a two-minute
deadline and its own native model lock. Every run uses local repository APIs,
with remote URL environment variables cleared. Each pass removes at most 5,000 paths through native
data deletion and preserves the shared catalog. It excludes active model writers,
keeps at least one hour of fresh versions, and rechecks each producer's latest
version before deleting. New writes defer that name until the next pass. Use the offline initial pass to clear an existing backlog before schedules resume;
the online pass has a fixed budget and does not promise to drain a backlog. Terminal histories age out after seven days; the offline compactor also retires unused auto-definitions. This thread changes no live
schedule or service; the workflow takes effect when Nick deploys it.

The repository declares seven-day workflow/output retention. Its own scheduled
pruner enforces it, with native automatic global GC disabled: it does not know which finished child runs our
live receipts reference. Do not substitute a broad `data gc`, `run gc`,
`data prune`, `behavior delete` or a recursive directory deletion. None can
express this receipt-protection rule. `swamp datastore compact` only vacuums
its SQLite catalog; it does not reclaim these version/history files.

## Saturation correction (2026-10-28)

The first online pruner loaded all workflow snapshots into serve and filtered
the full snapshot array once per referenced run. The observed pass took
531 seconds; it starved the event loop even though deletions were capped.
The corrected executor indexes snapshots, run IDs, model names and output
provenance once. Planning runs outside serve with a fixed deadline. A child
failure preserves the remaining history for the next run; no receipt clearing
or broad GC is part of recovery. A deployment restart stops the old in-process
pass; updating code alone cannot move that already-running JavaScript frame.

## Incremental online planning

The 120-second child deadline is unchanged. Each pass uses at most 80 seconds for bounded scan, graph and prune work, leaving shutdown headroom. Progress is checkpointed in a private derived SQLite index at `.swamp/retention-planner.db`. It holds catalog and directory cursors, pending files, parsed records, graph edges and an expansion queue. Catalog and directory batches have at most 100 entries. A file or graph node completes individually; the next run continues its remaining queue. Large directories persist one complete listing in the checkpoint and consume it in 100-entry lexical batches, with a 250,000-entry safety bound. Writes during a listing pass do not reset its cursor. Only directories and YAML records enter the queue; logs remain governed by their owner records. Validation compares the names and fingerprints of YAML records and child directories before deletion. Log-only changes do not restart the reference graph. Changed YAML records still trigger a refresh, and unchanged file fingerprints do not requeue parsed records. New files before the old cursor and atomically replaced records are discovered by that refresh. No phase reparses the complete history into one array.

A budget yield publishes `partial: true` and succeeds with its actual removal counts. Nothing is deleted until receipt reachability is complete. Before pruning resumes, changed source directories and newly published data are refreshed, and new references are expanded. Source files remain authoritative; their inode, size, mtime and ctime invalidate cached parses. Malformed files, symlinks and redirected datastores fail closed. Latest state, current durable receipts, referenced completed children, their exact versions, fresh data and active writers remain protected. The online pass conservatively retains finished receipt roots too. Definition retirement remains in the existing offline compactor; the online fix does not remove model definitions.

Deletion uses native version APIs, catalog batches of at most 100 candidates, an 80-second work budget and a 5,000-path pass cap. Finished, unreferenced history older than seven days is pruned with its owned logs and evaluated snapshots; deferred cleanup records survive a restart. The `progress` resource reports phase, work units and actual removed versions/paths. Keep the checkpoint index across budget yields. Removing it merely restarts planning; it never authorizes deletion.

Upstream guidance: [Swamp repository retention](https://github.com/swamp-club/swamp/blob/main/design/surfaces/repo.md#retention) uses bounded batches and resumes at batch boundaries. [swamp-club#3178](https://github.com/swamp-club/swamp/pull/3178) fixes native cleanup cost and bounds automatic passes; it is already in the installed runtime. The newest checked release, `963e0d28`, changes remote datastore recovery and does not fix this extension's full-history YAML planner. Native `automatic: true` remains unsuitable here: it cannot follow Orbit's durable receipt references to finished runs and exact versions. No runtime upgrade or broader retention policy is needed for this correction.
