# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad:
- Worktree: /Users/nckrtl/orbit/.worktrees/fix-e2e-lease-isolation
- Branch: fix-e2e-lease-isolation

## Goal

The apps/e2e in-memory suite completes instead of hanging: lifecycle-lock acquisition is deadline-bounded and reaps a dead holder as a prompt failure, the stop tests fake the lock holder like their siblings, and every test process uses a throwaway lease directory so tests never contend with or pollute the repository-shared pool.

## Scope

- Owned: `apps/e2e/app/E2E/Support/SourceMountedCheckoutLifecycleLock.php` bounded ready-wait; `apps/e2e/tests/Pest.php` lease-directory isolation; the three stop tests' Process fakes; new dying-holder lock suite; lease-pool isolation guard test
- Constraints: genuine lock acquisition, hold, and release semantics unchanged; the real shared pool untouched by any test process; stale real-pool leases cleaned manually as operational hygiene
- Out of scope: reclaim of leases for retired host aliases (noted as follow-up), pest vendor result-cache permissions in retained overlays

## Proof

- Verification:
  - focused: passed - previously-hanging E2EIncusCommandTest 25 passed in 0.64s, full in-memory suite `composer test` 461 passed 2916 assertions exit 0 in ~10s (previously indefinite hang), dying-holder lock test passes in under 10s
  - broader: passed - `composer quality-check` on clean commit 0c1613d72cfe7b3b85a3bc6974d61f4a781cea45 exit 0, 45/45 subgates (`.orbit/quality-gates/quality-check-2026-08-20T062722Z-21492f4e8dbe.json`)
  - runtime: passed - candidate=0c1613d72cfe7b3b85a3bc6974d61f4a781cea45; venue=retained-incus; environment=dev-fixture; target=orbit-e2e-dev-e9e82c-operator; expected=exact candidate runs the previously-hanging command suite, the dying-holder bound, and the lease isolation guard in the routed retained environment where flock exists and genuine lock acquisition executes; observed=matching lock sha256 969da3d7d3c9 and 29 tests passed 242 assertions in the retained operator instance; result=passed; evidence=`.orbit/evidence/fix-e2e-lease-isolation-retained-incus-runtime.txt`
- Blast radius: complete - evidence=systematic bisection isolating the three stop tests plus caller inventory of the lifecycle lock and lease pool; result=bounded wait changes only the acquire phase with hold and release semantics untouched, sync and recheck callers already faked and stay green, lease isolation applies to test processes only via the Pest bootstrap, stale real-pool leases from dead pids removed as hygiene, retired-host lease reclaim gap noted as follow-up
- Review: passed - orchestrator Claude reviewer VERDICT PASS: root cause chain fully evidenced (unfaked holder because preventStrayProcesses only guards faked factories, zombie holder polled by unbounded waitUntil, shared lease pool contended by test-created real leases), fix addresses trigger, mechanism, and contamination layers with the genuine-acquire path re-proven on Linux; human-judgment=not-required
- Reviewed feature tip: 0c1613d72cfe7b3b85a3bc6974d61f4a781cea45
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 0c1613d72cfe7b3b85a3bc6974d61f4a781cea45
- Accepted main tip: 07d1f799f3409d914665c3cd21e0ee988db7de65

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
