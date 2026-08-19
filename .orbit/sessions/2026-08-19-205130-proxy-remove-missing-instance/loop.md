# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad:
- Worktree: /Users/nckrtl/orbit/.worktrees/proxy-remove-missing-instance
- Branch: proxy-remove-missing-instance

## Goal

`proxy:remove --force` removes a direct-instance proxy route (app, app-analytics, app-websocket) whose owning Instance is genuinely gone — durable instance_id nulled by instance removal or the referenced row hard-deleted — while conflicting tuples with a living instance stay denied as repairable divergence.

## Scope

- Owned: `apps/gateway/app/Services/Proxy/ProxyRouteIntent.php` hasMissingOwner direct-instance orphan case; ProxyRouteIntentTest reshaped fail-closed dataset plus orphan-removal coverage; proxy-remove command doc; dated PRODUCT_DECISIONS refinement of the 2026-08-17 entry
- Constraints: automatic convergence still never classifies invalid ownership as removable; destructive consent never overrides a living owner; tool and workspace orphan semantics unchanged
- Out of scope: instance:remove cascade semantics, doctor --fix resolutions, the live hauzer.test cleanup (follows the next fleet update)

## Proof

- Verification:
  - focused: passed - ProxyRouteIntentTest 66 passed 317 assertions with the reshaped dataset and both new orphan-removal cases red-proofed against the unpatched intent
  - broader: passed - `composer quality-check` on clean commit 08c21be2ada7d8bbdeab68afa4fd4c7967237b88 exit 0, 45/45 subgates plus docs lint (`.orbit/quality-gates/quality-check-2026-08-19T185040Z-2126cf35d097.json`)
  - runtime: passed - candidate=08c21be2ada7d8bbdeab68afa4fd4c7967237b88; venue=retained-incus; environment=dev-fixture; target=orbit-e2e-dev-0367a7-gateway; expected=exact candidate removes FK-nulled and hard-deleted direct-instance orphans with consent while denying living and conflicting owners in the routed retained environment; observed=matching ProxyRouteIntent sha256 3d8479f5593a and 74 tests passed 352 assertions in the retained gateway instance; result=passed; evidence=`.orbit/evidence/proxy-remove-missing-instance-retained-incus-runtime.txt`
- Blast radius: complete - evidence=hasMissingOwner caller inventory plus full gateway suite; result=the orphan definition extends one private predicate consumed only by the remove lane behind destructive consent, S3 unpublish and tool orphan paths untouched, superseded living-denial test replaced by decided-contract coverage with the ledger refinement recording the supersession
- Review: passed - orchestrator Claude reviewer VERDICT PASS: orphanhood scoped to genuinely absent instances rather than resolver-null so conflicting tuples stay denied, consent gate location unchanged, docs and ledger moved with the contract; human-judgment=not-required
- Reviewed feature tip: 08c21be2ada7d8bbdeab68afa4fd4c7967237b88
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 08c21be2ada7d8bbdeab68afa4fd4c7967237b88
- Accepted main tip: 2838d25387422f0ebafa00b7b8a421c8e77e1cde

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
