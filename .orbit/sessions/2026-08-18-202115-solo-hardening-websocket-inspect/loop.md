# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none
- Worktree: /Users/nckrtl/orbit/.worktrees/solo-hardening-websocket-inspect
- Branch: solo-hardening-websocket-inspect

## Goal

Websocket `container:apply` keeps the anti-churn inspect-failure fallback, but reports a three-way current-image result (`matches` / `differs` / `could_not_verify`) and surfaces an explicit warning when `orbit-reverb:current` cannot be inspected.

## Scope

- Owned: `apps/cli` websocket runtime apply path, the gateway apply-warning re-emission, their Pest coverage, and any matching websocket docs output-contract line.
- Constraints: inspect failure must not recreate a hash-matching running container; human and JSON apply output must both carry the unverified signal.
- Out of scope: live-node changes, E2E lanes, merge to main, doctor family additions unless existing probe output already documents this.

## Proof

- Verification:
  - focused: passed - `bin/orbit-cli-pest --compact tests/Feature/InternalWebSocketRuntimeCommandTest.php` 21 passed 212 assertions; gateway `WebSocketRuntimeContainerManagerTest` 5 passed 19 assertions on merged tip cb707a87e
  - broader: passed - `composer quality-check` on clean merged commit cb707a87e8c15fee801d76044f2b519f291b7ec5 exit 0, 45/45 subgates (`.orbit/quality-gates/quality-check-2026-08-18T182026Z-b15840a4a55b.json`)
  - runtime: passed - candidate=cb707a87e8c15fee801d76044f2b519f291b7ec5; venue=retained-incus; environment=dev-fixture; target=orbit-e2e-dev-a42f40-operator; expected=exact candidate reports the three-way current-image result with could-not-verify warning on both apply surfaces and the gateway manager re-emits the warning as an operation progress step in the routed retained environment; observed=matching LocalWebSocketRuntimeAction sha256 edba9e33ec70 with 21 CLI tests passed in the operator instance and 5 gateway manager tests passed in the gateway instance; result=passed; evidence=`.orbit/evidence/solo-hardening-websocket-inspect-retained-incus-runtime.txt`
- Blast radius: complete - evidence=repository-wide search for containerUsesCurrentImage callers and warning-channel consumers plus full CLI and gateway Pest suites; result=three-way enum replaces the boolean at its only call site, could-not-verify never recreates, warning carried on JSON meta and human output and re-emitted by the gateway manager via the existing appendStep channel, matches and differs stay silent, full CLI suite 2617 and gateway suite 6944 passed
- Review: passed - orchestrator Claude reviewer VERDICT PASS: enum requiresRecreation limited to Differs, inspect-failure path reuses without churn while surfacing the signal, gateway warning helper is fail-safe on malformed JSON and scoped to the latest apply run per node, no doctor probe contract invented; human-judgment=not-required
- Reviewed feature tip: cb707a87e8c15fee801d76044f2b519f291b7ec5
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: cb707a87e8c15fee801d76044f2b519f291b7ec5
- Accepted main tip: c50726e3d8922f548da4f0a5748a08c5b6d7d025

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
