# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/2/todo/p03-a-fail-closed-wh--746`
- Worktree: `/Users/nckrtl/orbit/.worktrees/solo-746-bulk-tool-update-target`
- Branch: `solo-746-bulk-tool-update-target`

## Goal

Bulk tool updates resolve and authorize exactly one active tool-host node before any mutation, and missing, invalid, conflicting, or unauthorized selectors fail without updating any node.

## Scope

- Owned: `apps/gateway/app/Http/Controllers/Api/ToolUpdateBulkController.php`, `apps/gateway/app/Services/Tools/ToolUpdater.php`, focused gateway tests, SDK/request contract if required, and the `tool:update` product contract.
- Constraints: Preserve valid `node` and concrete `instance` selectors; preserve gateway and authorized peer behavior; use literal RED tests before implementation; keep all mutation queries scoped to one resolved `Node`; preserve parent `.codex/config.toml`; use local Solo project 2 only.
- Out of scope: Single-tool update behavior, update-all orchestration, tool catalog changes, transport redesign, and manual E2E lanes.

## Proof

- Verification:
  - focused: passed - evidence=`.orbit/evidence/solo-746-proof-3252ebab00f4.txt`
  - broader: passed - evidence=`.orbit/quality-gates/quality-check-2026-08-16T190441Z-50fe519b5bcb.json`
  - runtime: passed - candidate=3252ebab00f49365295256732e592423d74a8ec2; venue=retained-incus; environment=dev-fixture; expected=one authorized concrete node mutates while omitted invalid and unauthorized targets fail without mutation; observed=explicit app-dev node bulk update succeeded and every rejected selector preserved the post-success tool inventory; result=passed; target=dev-259f9f; evidence=`.orbit/evidence/solo-746-retained-incus-3252ebab00f4.json`
- Blast radius: complete - evidence=`.orbit/evidence/solo-746-proof-3252ebab00f4.txt`; result=all four gateway updateAll call sites pass a concrete Node and no optional or string overload remains
- Review: passed - evidence=`.orbit/evidence/solo-746-review-3252ebab00f4.txt`; human-judgment=not-required
- Reviewed feature tip: 3252ebab00f49365295256732e592423d74a8ec2
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 3252ebab00f49365295256732e592423d74a8ec2
- Accepted main tip: f0cd3ac4a2d0707bfd20dbec68f1a66e6638a694

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
