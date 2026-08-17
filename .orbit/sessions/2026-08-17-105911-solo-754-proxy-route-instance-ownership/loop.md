# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/2/todo/p08-a-persist-proxyr--754`
- Worktree: `/Users/nckrtl/orbit/.worktrees/solo-754-proxy-route-instance-ownership`
- Branch: `solo-754-proxy-route-instance-ownership`

## Goal

Persist one concrete Instance owner on every instance-backed ProxyRoute, derive
its App and serving Node from that Instance without candidate scans, and fail
closed when legacy ownership cannot be proven unique.

## Scope

- Owned: Gateway ProxyRoute schema/model/services/controllers/tests and the App, Instance, Workspace, and Proxy product contracts; primitive=instance-backed ProxyRoute ownership; transitions=success:one durable instance_id drives app and node identity|failure:migration aborts with an actionable ownership error before guessing|retry:safe migration rerun converges already-backed rows|stop-restart:durable instance_id survives process restart|stale:deleted owners resolve as missing without candidate fallback
- Constraints: Exact base `c8ed09c595b18a84ac5d1bf1a4b7df859bed9d2b`; strict test-first RED/GREEN evidence; SQLite-safe FK/index and provably unique legacy backfill; preserve legitimate non-instance-owned routes; no human-only E2E commands.
- Out of scope: Solo state changes, parent checkout changes, live fleet mutation, proxy command UX redesign, and unrelated ownership families.

## Proof

- Verification:
  - focused: passed - reviewer RED produced `7` Gateway failures across inactive analytics ownership, exact stable-config conflicts, and forced ingress cleanup, `8` CLI failures for invalid workspace slugs, then `2` Doctor repair errors for stale conflict entries; final affected Gateway bundle passed `246` tests with `841` assertions and the CLI application-log bundle passed `59` tests with `229` assertions
  - broader: passed - `composer quality-check` on exact clean candidate `3761c3ec015a642c6d0efa885bfe847eb6ea51a6` exited zero with all 45 subgates zero; receipt=`.orbit/quality-gates/quality-check-2026-08-17T084544Z-0ad71ee4c826.json` (sha256 `530a0fcd6c2216159bd35cbab30bc98d6a340b25538daaf6d686f03c6105a44e`)
  - runtime: passed - candidate=3761c3ec015a642c6d0efa885bfe847eb6ea51a6; venue=retained-incus; environment=dev-fixture; command=orbit proxy:list --filter=instance --json; expected=primary App, Workspace, public analytics, and public WebSocket persist one exact instance_id through restart and migration rollback/reapply with deleted-owner fail-closed and no candidate fallback; observed=six-surface matrix all pass on retained topology dev-589c8f (App and Workspace rows instance_id=2, migration up/down/up fills 4 families, ambiguous route fails before schema mutation, deleted owner resolves null with sibling present); result=passed; evidence=`.orbit/evidence/solo-754-retained-incus-receipt.md`
- Required verification:
  - Retained topology proof: passed - route=`retained-incus`; candidate-bound receipt binds `3761c3ec015a642c6d0efa885bfe847eb6ea51a6` on topology `dev-589c8f`; six-surface matrix all pass; receipt=`.orbit/evidence/solo-754-retained-incus-receipt.md`; plan=`.orbit/evidence/solo-754-retained-incus-proof-plan.md`
  - `composer quality-check`: passed - bound exact clean candidate `3761c3ec015a642c6d0efa885bfe847eb6ea51a6`, dirty=false, exit 0, all 45 subgates zero; receipt=`.orbit/quality-gates/quality-check-2026-08-17T084544Z-0ad71ee4c826.json`
- Blast radius: complete - evidence=`.orbit/evidence/solo-754-blast-radius-inventory.md`; result=101 direct scoped matches plus AppRemoveCommand.php, AppRootCommand.php, ToolCatalog.php, NodeRoleAssignmentService.php, and the metrics migration classified for 106 total files
- Review: passed - human-judgment=not-required; reviewer=fresh Solo Claude 2472; BLAST_RADIUS=complete; independently verified fail-closed ownership resolver, migration computes assignments before schema mutation with ambiguous/competing guards intact, no orphaned resolver refs, 332 meaningful tests green, docs match, quality+runtime receipts match; one non-blocking INFO on the safe single-candidate first-run fallback
- Reviewed feature tip: 3761c3ec015a642c6d0efa885bfe847eb6ea51a6
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 3761c3ec015a642c6d0efa885bfe847eb6ea51a6
- Accepted main tip: c8ed09c595b18a84ac5d1bf1a4b7df859bed9d2b

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`

Allowed states are `frame`, `build`, `prove`, `accept`, `accepted`, `land`, and
`blocked`. Review must be `passed`, `fix`, or `escalate`. Acceptance must be
`pending`, `accepted`, `not-applicable`, or `changes-requested`. A terminal
review PASS must record `human-judgment=required|not-required` and the exact
committed HEAD in `Reviewed feature tip`. Blast radius must be `not-required -
concrete reason` or `complete - evidence=repository-wide search, inventory, or
lintable check; result=summary` before acceptance; `gaps` returns to BUILD.
For stateful, lifecycle, or concrete UX work, optionally append one compact
clause on the existing Scope `Owned` row (do not add a permanent new row):
`primitive=exact requested primitive; transitions=success:terminal success|failure:terminal failure|retry:retry behavior|stop-restart:stop or restart|stale:stale-state or n/a`.
Omit the clause for ordinary/local changes. When `primitive=` or `transitions=`
is present, deterministic lint requires both fields, the five known transition
keys without duplicates or empty values, and rejects template placeholders; it
does not grade prose or decide whether the feature is stateful. Explicit `n/a`
values are fine when a transition does not apply. After FRAME, run
`bin/orbit-feature-acceptance route` for the read-only
diff-derived venue before expensive PROVE work. For non-`automated` venues,
`Verification.runtime: passed` must use one candidate-bound structured receipt
on that same single line. Required fields are candidate=, venue=, environment=,
expected=, observed=, result=passed, and evidence= as one exact inline-code path
under the worktree evidence or quality-gates trees. Use exactly one of target= or command=.
Live/production claims require exact environment=live; ordinary retained
topology may use environment=dev-fixture. Semicolons separate fields,
so values must not embed raw semicolon-delimited pseudo-fields. Known keys
only. Example evidence citation: write a real receipt and cite one exact regular
file below the worktree evidence tree (not a directory root). A failed,
excluded, still-required, or deferred final hop cannot be recorded as passed;
remain in PROVE, disarm any armed or recorded acceptance, and follow FIX ->
BUILD -> PROVE before ACCEPT. Keep a still-valid Review and Reviewed feature tip
on proof-only retries; a HEAD change still needs a refreshed review. Automated
venues keep `runtime: not applicable`. Proof files retained by the compact
archive must be cited as one exact inline-code path; prose, directories, padded
code spans, and partial paths are not proof citations.
