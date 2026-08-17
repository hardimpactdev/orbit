# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/2/todo/p02-a-enforce-one-ca--757`
- Worktree: `/Users/nckrtl/orbit/.worktrees/solo-757-local-gateway-settings-singleton`
- Branch: `solo-757-local-gateway-settings-singleton`

## Goal

LocalGatewaySettings is a durable singleton: a deterministic migration consolidates any existing rows to one canonical row (preserving the currently-authoritative newest identity/trust values) and adds a singleton/unique constraint, and one canonical accessor replaces every unordered first/create so gateway identity and trust settings are deterministic regardless of row history.

## Scope

- Owned: apps/gateway LocalGatewaySettings model + canonical accessor, a new consolidation+constraint migration, the settings importer and every ad-hoc selection site (GatewayNodeCreator, controllers, AppServiceProvider), and focused migration/model/registration/settings Pest tests.
- Constraints: gateway PHP only; SQLite-safe migration; deterministic duplicate-selection rule matching current intent (newest by created_at, tiebreak highest id); preserve identity/trust field values; declare(strict_types=1); Mago/Rector clean.
- Out of scope: gateway identity/trust semantics themselves, node registration UX, and unrelated settings tables.

## Proof

- Verification:
  - focused: passed - TDD RED 9 failing tests (missing singleton constraint/accessor), then GREEN; new EnforceSingletonLocalGatewaySettingsTest (zero/one/duplicate/second-init) and LocalGatewaySettingsTest plus GatewayNodeCreatorArchitectureTest all green; mago analyze/format clean after the type-safe nullability fix
  - broader: passed - `composer quality-check` on exact clean candidate `99522c332ba63cf350f1753ba7db58d3d4510429` exited zero with all 45 subgates zero; receipt=`.orbit/quality-gates/quality-check-2026-08-17T100922Z-9912383c051c.json` (sha256 `207bc55f41f848890cf987553d3bb854f059494d5241ff212659a57dbfb136c8`)
  - runtime: passed - candidate=99522c332ba63cf350f1753ba7db58d3d4510429; venue=retained-incus; environment=dev-fixture; command=artisan migrate on disposable gateway DB copies plus current() via tinker; expected=zero rows create one canonical row, one row idempotent, duplicate rows consolidate to the newest values, and a second or null singleton_key is rejected; observed=on topology dev-bf43eb the gateway holds one canonical row and disposable copies consolidate 3 rows to 1 keeping the newest values with unique and NOT NULL constraints rejecting duplicates and nulls, and current() creates one row from zero then returns the same row on a second call; result=passed; evidence=`.orbit/evidence/solo-757-retained-incus-receipt.md`
- Blast radius: complete - evidence=repository-wide `rg` for `LocalGatewaySettings::query()`/`first`/`create`; result=no production ad-hoc selection remains (GatewayNodeCreator and all sites use the canonical `current()`; GatewayNodeCreatorArchitectureTest forbids `LocalGatewaySettings::query()`); remaining matches are test assertions; 12 non-test files reference the class
- Review: passed - human-judgment=required; reviewer=fresh Solo Claude 2478; BLAST_RADIUS=complete; independently verified deterministic SQLite-safe consolidation (newest-wins, five identity/trust fields copied atomically from one row, guarded/idempotent), current() as the single race-safe selection path across all production sites, four meaningful test cases, focused bundle 614 passed; one human-judgment item: the deliberate newest-wins authority semantics (with a narrow genesis-race edge case, trust re-derivable) warrants explicit user acceptance
- Reviewed feature tip: 99522c332ba63cf350f1753ba7db58d3d4510429
- Acceptance venue: retained-incus
- Acceptance: accepted - user @ solo://proj/2/todo/p02-a-enforce-one-ca--757
- Accepted feature tip: 99522c332ba63cf350f1753ba7db58d3d4510429
- Accepted main tip: d0683c8c405857ae708255b7adcbaa67221003dc

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
