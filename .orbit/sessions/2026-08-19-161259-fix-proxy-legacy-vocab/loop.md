# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad:
- Worktree: /Users/nckrtl/orbit/.worktrees/fix-proxy-legacy-vocab
- Branch: fix-proxy-legacy-vocab

## Goal

The proxy route ownership migration reads pre-rename app_instance route config vocabulary as deterministic legacy evidence, so fleet routes written before the 2026-07-20 rename resolve their configured instance instead of blocking the whole migration as ambiguous.

## Scope

- Owned: `apps/gateway/database/migrations/2026_08_16_231522_persist_proxy_route_instance_ownership.php` legacy-aware instance config and target-type evidence; `apps/gateway/tests/Feature/Migrations/PersistProxyRouteInstanceOwnershipTest.php` live-shaped regressions
- Constraints: genuine ambiguity and malformed JSON stay fail-closed; no data-effect change for post-rename configs; renamed spellings keep precedence
- Out of scope: the release candidate rebuild and fleet update rerun that follow

## Proof

- Verification:
  - focused: passed - PersistProxyRouteInstanceOwnershipTest 64 passed 199 assertions including the pre-rename evidence and still-fail-closed regressions, red-proofed against the unpatched migration reproducing the fleet blocker
  - broader: passed - `composer quality-check` on clean commit b00cc9b7975521af74b5af6057c3d0b67c97cfd5 exit 0, 45/45 subgates (`.orbit/quality-gates/quality-check-2026-08-19T141219Z-cf830a4e96a4.json`)
  - runtime: passed - candidate=b00cc9b7975521af74b5af6057c3d0b67c97cfd5; venue=retained-incus; environment=dev-fixture; target=orbit-e2e-dev-b33246-gateway; expected=exact candidate resolves pre-rename app_instance route vocabulary to the configured instance while genuine ambiguity stays fail-closed in the routed retained gateway environment; observed=matching migration sha256 8319fe82f976 and 77 tests passed 220 assertions in the retained gateway instance; result=passed; evidence=`.orbit/evidence/fix-proxy-legacy-vocab-retained-incus-runtime.txt`
- Blast radius: complete - evidence=read-only fleet route audit plus full suite; result=33 of 78 fleet routes carry app_instance vocabulary and only the two config spellings exist fleet-wide, both instanceConfig sites share the legacy-aware helper, target evidence accepts both type spellings, renamed spelling keeps precedence, post-rename fixtures unchanged
- Review: passed - orchestrator Claude reviewer VERDICT PASS: legacy spelling is the deterministic pre-rename serialization of the same evidence not guesswork, fail-closed semantics for malformed JSON and true ambiguity re-proven, fix covers all audited fleet rows; human-judgment=not-required
- Reviewed feature tip: b00cc9b7975521af74b5af6057c3d0b67c97cfd5
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: b00cc9b7975521af74b5af6057c3d0b67c97cfd5
- Accepted main tip: ae156dbcb8140ac9d17c07720c6a14332f20769c

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
