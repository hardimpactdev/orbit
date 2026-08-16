CHECKOUT_PROOF: cwd=/Users/nckrtl/orbit/.worktrees/solo-748-app-placement-shadows; branch=solo-748-app-placement-shadows; head=aae3c2abc491c616bc8dfe039fe77962dcd706b7; main=a7a5e03e356447241893015868a0c80370acd614; status=clean

# General review — Todo #748

- Reviewer: independent local Solo process `2417`
- Candidate: `aae3c2abc491c616bc8dfe039fe77962dcd706b7`
- Diff: `main...aae3c2abc491c616bc8dfe039fe77962dcd706b7`
- Scope: 285 changed files, drop migration and test, App model,
  WorkspacePlacement, registration/selectors/process runtime, product docs,
  quality receipts, and retained Incus proof.

## Goal coverage

- Instance is the sole placement authority. App drops the six shadow
  properties, fillable entries, adoption cast, `node()` relation, `url()`, and
  `documentRootPath()`. Placement and adoption reads use the concrete Instance.
- Synthetic runtime App objects are gone. `ProcessRuntimeApp` is deleted. The
  process runtime context uses the logical App and resolves placement from the
  process Instance.
- Registration no longer writes placement or adoption to App. Path collision
  checks are instance-authoritative.
- `php_version` remains the one App creation template copied to Instance.

## Migration safety

- The fail-closed preflight preserves columns on a missing or nonexistent node,
  a missing path, or a name-conflicting divergent Instance.
- The backfill runs in a transaction and `up()` is idempotent.
- The migration tests cover all five branches.
- The dropped foreign key and indexes match the repository migration chain.
  No later migration adds another shadow-column index.

## Blast-radius inventory

- A repository-wide source sweep found no remaining App shadow-column or
  App-node relation reads. Only historical migrations and the drop migration
  read raw legacy rows.
- App-targeted `with('node')` and `loadMissing('node')` calls are absent.
- No source call targets the removed App URL or document-root helpers.

## Evidence

- The quality receipt records exit 0, all subgates at 0, a clean worktree, and
  the exact candidate.
- The retained Incus proof matched candidate file hashes, showed the six
  columns absent in the real gateway database, showed placement and adoption
  on Instance, exercised idempotent registration, started the instance process,
  verified the source mount and Caddy target, and received `proof-748` with
  HTTP 200 through the instance route.

## Non-blocking observations

- `AppSelectorResolver` now filters domain matches in PHP. The reviewer judged
  this acceptable for control-plane scale.
- The migration test mirrors the historical schema. The reviewer independently
  confirmed the actual migration-chain indexes, and the full quality gate ran
  the real chain successfully.

No actionable finding remains. The runtime outcome is deterministic and all
remaining acceptance actions are agent-runnable.

BLAST_RADIUS: complete
HUMAN_JUDGMENT: not-required
VERDICT: PASS
