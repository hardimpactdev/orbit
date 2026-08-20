# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad:
- Worktree: /Users/nckrtl/orbit/.worktrees/fix-lease-reclaim-sweep
- Branch: fix-lease-reclaim-sweep

## Goal

Stale lease reclaim sweeps the entire lease directory, so leases for hosts that left the configuration are reclaimed by the same retained-flag, live-pid, and staleness guards instead of lingering forever.

## Scope

- Owned: `apps/e2e/app/E2E/Support/E2EResourceLeasePool.php` reclaimStaleLeases directory sweep; retired-host regression in the lease pool suite
- Constraints: retained leases, live-owner leases, and fresh files stay protected; configured-host reclaim and acquisition semantics unchanged
- Out of scope: lease directory layout, wait and staleness defaults

## Proof

- Verification:
  - focused: passed - lease pool suite 4 passed 34 assertions with the retired-host regression red-proofed against the unpatched pool; full in-memory e2e suite exit 0
  - broader: passed - `composer quality-check` on clean commit ff985cf637dba9aac7bf790857d44c00ef3d0b55 exit 0, 45/45 subgates (`.orbit/quality-gates/quality-check-2026-08-20T064700Z-457ee76827e2.json`)
  - runtime: passed - candidate=ff985cf637dba9aac7bf790857d44c00ef3d0b55; venue=retained-incus; environment=dev-fixture; target=orbit-e2e-dev-9ec7a3-operator; expected=exact candidate reclaims a dead-owner lease for an unconfigured host during acquire while retained leases and leases with running owner pids survive in the routed retained environment; observed=matching E2EResourceLeasePool sha256 1ebeb9609808 and 4 tests passed 34 assertions in the retained operator instance; result=passed; evidence=`.orbit/evidence/fix-lease-reclaim-sweep-retained-incus-runtime.txt`
- Blast radius: complete - evidence=reclaim caller inventory plus full in-memory suite; result=the sweep adds one directory pass through the existing per-path guards consumed only by acquire paths, retained-lease and running-owner-pid protections re-proven by the existing suite, observed real-world victims were the nine dead-pid leases cleaned manually during todo 223
- Review: passed - orchestrator Claude reviewer VERDICT PASS: guard-protected directory sweep is strictly broader visitation with unchanged per-file semantics, red-proofed regression pins the retired-host case; human-judgment=not-required
- Reviewed feature tip: ff985cf637dba9aac7bf790857d44c00ef3d0b55
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: ff985cf637dba9aac7bf790857d44c00ef3d0b55
- Accepted main tip: 2b8d70850b5ea5552e974b83bab5ad6d4ed873f5

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
