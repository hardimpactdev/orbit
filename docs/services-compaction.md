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
~/.swamp/deno/deno run --allow-read --allow-write bin/compact-services.ts > /tmp/services-compaction-preview.json
~/.swamp/deno/deno run --allow-read --allow-write bin/compact-services.ts --apply > /tmp/services-compaction-result.json
swamp data query 'modelName == "orbit-gateway"' --select name --json > /tmp/services-compaction-query.json
```

Unset server URL variables for the local query. On restart, run local
`swamp run doctor --fix --json` once before resuming schedules, then restore
only the schedules in the saved snapshot. Check the active/queued run counts,
Gateway receipts and one existing group's owner before resuming normal work.
A shipped `runtime-retention` workflow runs every five minutes at second 20,
under its own model lock. Each pass removes at most 5,000 paths through native
data deletion and preserves the shared catalog. It excludes active model writers,
keeps at least one hour of fresh versions, and rechecks each producer's latest
version before deleting. New writes defer that name until the next pass. That
budget exceeds the bounded four-drive write rate; the offline initial pass
clears the existing backlog before schedules resume. Terminal histories and
unused auto-definitions age out after seven days. This thread changes no live
schedule or service; the workflow takes effect when Nick deploys it.

The repository declares seven-day workflow/output retention. Its own scheduled
pruner enforces it, with native automatic global GC disabled: it does not know which finished child runs our
live receipts reference. Do not substitute a broad `data gc`, `run gc`,
`data prune`, `behavior delete` or a recursive directory deletion. None can
express this receipt-protection rule. `swamp datastore compact` only vacuums
its SQLite catalog; it does not reclaim these version/history files.
