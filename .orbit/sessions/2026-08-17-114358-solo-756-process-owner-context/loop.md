# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/2/todo/p07-a-close-processo--756`
- Worktree: `/Users/nckrtl/orbit/.worktrees/solo-756-process-owner-context`
- Branch: `solo-756-process-owner-context`

## Goal

ProcessOwnerContext can only be constructed in a valid owner shape: a private constructor plus `forNode`, `forInstance`, and `forWorkspace` factories that derive redundant App/Workspace/owner data and make mixed or missing ownership unconstructible, so contradictions are rejected at construction rather than surviving to execution. One class, no hierarchy; all existing behavior preserved.

## Scope

- Owned: apps/gateway ProcessOwnerContext value object, its construction call sites (ProcessOwnerContextResolver and any `new ProcessOwnerContext`), and focused owner/lifecycle Pest tests.
- Constraints: gateway PHP only; keep one class; preserve every existing public method and behavior; cover every valid owner kind (node, instance, workspace) and reject mixed/missing ownership at construction; declare(strict_types=1); Mago/Rector clean.
- Out of scope: process lifecycle command UX, runtime enum semantics, node/topology changes, and unrelated ownership families.

## Proof

- Verification:
  - focused: passed - TDD RED 7 failing factory tests (missing factories / public constructor), then GREEN 7 factory tests plus 274 process/owner/lifecycle tests (1987 assertions); mago format clean
  - broader: passed - `composer quality-check` on exact clean candidate `a06d8d465e8bb1c38cc5ead7e2db73b5356879e5` exited zero with all 45 subgates zero; receipt=`.orbit/quality-gates/quality-check-2026-08-17T093803Z-24aa2bebec85.json` (sha256 `4372ae37b79b80fc36a58147fc611629a62e05abcfa5628dab3d6703b511d4cf`)
  - runtime: passed - candidate=a06d8d465e8bb1c38cc5ead7e2db73b5356879e5; venue=retained-incus; environment=dev-fixture; command=orbit process:list per owner kind plus gateway forWorkspace guard; expected=forNode, forInstance, and forWorkspace each construct a valid ProcessOwnerContext and mixed ownership is rejected at construction; observed=on topology dev-f603c1 all three owner kinds resolve correct process ownership and forWorkspace rejects a mismatched proofapp2 instance with InvalidArgumentException; result=passed; evidence=`.orbit/evidence/solo-756-retained-incus-receipt.md`
- Blast radius: complete - evidence=repository-wide `rg` for `new ProcessOwnerContext` and `ProcessOwnerContext::for*`; result=zero external direct constructions remain (all routed through forNode/forInstance/forWorkspace across 17 files), 41 non-test files reference the class
- Review: passed - human-judgment=not-required; reviewer=fresh Solo Claude 2476; BLAST_RADIUS=complete; independently verified private constructor with zero external `new ProcessOwnerContext`, correct factory derivation/rejection re-guarded by assertValidOwnerShape, all 8 production call sites routed to the correct factory with added guards, meaningful tests (3 valid kinds + reflection private-constructor check + 3 invalid-rejection cases), focused bundle 274 passed; two non-blocking behavior-preserving observations, no defects
- Reviewed feature tip: a06d8d465e8bb1c38cc5ead7e2db73b5356879e5
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: a06d8d465e8bb1c38cc5ead7e2db73b5356879e5
- Accepted main tip: 11109039f3af0c52d5adac96e850821ae4e6ce07

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
