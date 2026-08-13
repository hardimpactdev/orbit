# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/82/scratchpad/proxy-route-probe-fa--433`
- Worktree: `/Users/nckrtl/orbit/.worktrees/fix-proxy-route-probe-unverifiable`
- Branch: `fix/proxy-route-probe-unverifiable`

## Goal

Report unavailable proxy route observation as blocked inspection instead of auto-restorable missing route drift.

## Scope

- Owned: proxy route-file observation contract, unavailable-evidence classification, proxy Doctor issue catalog/docs, and focused contract/report tests.
- Constraints: Preserve verified missing-route drift, healthy route/hash/TLS/upstream checks, valid sibling observations, public issue order, scope fences, and Caddy-first restore ordering.
- Out of scope: proxy rendering or repair behavior, WebSocket/S3/analytics/DNS probes, route adoption, public command shape, and unrelated large-file extraction.

## Proof

- Verification:
  - focused: passed - proxy contract/probe/catalog/restore 105 tests, 919 assertions; Doctor runner/API 149 tests, 1,183 assertions; scoped Mago lint/analyze and docs lint passed
  - broader: passed - `composer quality-check` candidate-bound receipt `.orbit/quality-gates/quality-check-2026-08-13T085439Z-45b1371fe300.json`; all subgates exit 0
  - runtime: passed - candidate=6c300fdd044839fe200c2ebc9ebf0907438569d7; venue=retained-incus; environment=dev-fixture; target=dev-561e1f operator_gateway_app-dev app-dev-1 via nckrtl@192.168.6.20; expected=stopped Caddy yields blocked route inspection without inferred route repair and restart returns healthy; observed=proxy.route_probe_failed blocked and non-restorable plus proxy.caddy_container_down genuine, no missing mismatch or TLS issue, restart healthy and cleanup complete; result=passed; evidence=`.orbit/evidence/proxy-route-probe-fail-closed-retained-incus.json`
- Blast radius: complete - evidence=repository-wide search for route-file probe fixtures plus full Doctor runner and API characterization; result=all old frame producers updated and no repair path changed
- Review: passed - Claude Opus confirmed contract, framing, sibling evidence, repair safety, docs, catalog, and defensive certificate parsing; human-judgment=not-required
- Reviewed feature tip: 6c300fdd044839fe200c2ebc9ebf0907438569d7
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 6c300fdd044839fe200c2ebc9ebf0907438569d7
- Accepted main tip: 29d7f3e1288601a8ff62420a0ec0c5ff1d39c233

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
