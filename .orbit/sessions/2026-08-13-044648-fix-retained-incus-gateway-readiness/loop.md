# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none - compact local loop
- Worktree: /Users/nckrtl/orbit/.worktrees/fix-retained-incus-gateway-readiness
- Branch: fix/retained-incus-gateway-readiness

## Goal

Retained Incus acquisition reports success only after the operator can reach the overlaid gateway API.

## Scope

- Owned: `apps/e2e/app/Console/Commands/E2EDevTopologyCommand.php`, focused E2E command coverage, and the retained-topology testing contract; primitive=retained topology acquisition; transitions=success:operator can reach the overlaid gateway API before success|failure:readiness timeout fails acquisition and cleans the lease|retry:the existing gateway API readiness poll retries until its bounded deadline|stop-restart:gateway API restart during checkout overlay must become reachable|stale:no success manifest is written for an unreachable topology
- Constraints: Use the existing `E2EGatewayApi::waitForGatewayApi` contract; preserve Incus cleanup on acquisition failure; use Beast only through `nckrtl@192.168.6.20`; never run `composer test:e2e*`.
- Out of scope: Change the shared 120-second readiness deadline or redesign WireGuard convergence.

## Proof

- Verification:
  - focused: passed - 19 Pest tests / 137 assertions, scoped Mago format and lint, docs-lint
  - broader: passed - `composer quality-check`, 45/45 subgates, candidate `7a7686dace854ec91a9b7b2119b67853f85cc5c5`
  - runtime: passed - candidate=7a7686dace854ec91a9b7b2119b67853f85cc5c5; venue=retained-incus; environment=dev-fixture; command=composer e2e:incus retained operator_gateway acquisition; expected=success only after operator reaches overlaid gateway API; observed=gateway-api.ready waited 94.36s and immediate node:list succeeded; result=passed; evidence=`.orbit/evidence/retained-incus-gateway-readiness-dev-1d0079.json`
- Blast radius: not-required - local retained-topology command timing label and readiness boundary; bounded repository search found no fixed timing-label consumer
- Review: passed - Claude Opus; human-judgment=not-required
- Reviewed feature tip: 7a7686dace854ec91a9b7b2119b67853f85cc5c5
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 7a7686dace854ec91a9b7b2119b67853f85cc5c5
- Accepted main tip: c13c3040a8e265a105cdf600ec0279f551a9a2a4

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
