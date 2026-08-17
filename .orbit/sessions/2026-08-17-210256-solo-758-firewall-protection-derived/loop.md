# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/2/todo/p04-a-derive-firewal--758`
- Worktree: `/Users/nckrtl/orbit/.worktrees/solo-758-firewall-protection-derived`
- Branch: `solo-758-firewall-protection-derived`

## Goal

Firewall rule protection is a computed property derived from ownership (protected == owner != 'user'), not a writable persisted shadow column: a migration drops the `protected` column, an accessor derives it, and mutation/deletion guards and API output read the derived value so a rule can never represent contradictory ownership and protection.

## Scope

- Owned: apps/gateway FirewallRule model (computed protected accessor, remove fillable/cast/saving-sync), a new column-drop migration, firewall mutation/deletion guards (Store/Destroy controllers), the firewall API resource/serializer, and focused firewall migration/model/API Pest tests.
- Constraints: gateway PHP only; SQLite-safe migration; keep serialized API `protected` output compatible; derive protection solely from owner; declare(strict_types=1); Mago/Rector clean.
- Out of scope: firewall rule semantics beyond ownership-derived protection, security-scope columns, and unrelated firewall behavior.

## Proof

- Verification:
  - focused: passed - `bin/orbit-gateway-pest --compact` firewall scope (Migrations/Models/Http/Api + Unit/Models/FirewallRuleOwnershipTest + Services/Firewall/FirewallRuleProbeTest); RED on legacy-contradiction/user/system/ownership-change before the accessor, GREEN after: 55 passed
  - broader: passed - `composer quality-check` exit 0 on 8727b62cc402561d0832731061510234cdbdd563, dirty=false, 45/45 subgates zero; receipt `.orbit/quality-gates/quality-check-2026-08-17T185709Z-69d0fb6ac872.json`
  - runtime: passed - candidate=8727b62cc402561d0832731061510234cdbdd563; venue=retained-incus; environment=dev-fixture; command=composer e2e:incus --sync --id=dev-4f814a then disposable-copy drop-column migration derivation proof; expected=protected column dropped and protection derived as owner not-equal user with legacy contradictions overridden, forced write blocked, serializer emits derived; observed=11/11 checks pass (system stored-false to derived-true, user stored-true to derived-false, node-security to true, write-block holds no column re-added, api system-true user-false); result=passed; evidence=`.orbit/evidence/solo-758-retained-incus-firewall-proof.json`
- Blast radius: complete - evidence=repository-wide `rg "'protected'"` + `rg "where('protected'|->protected ="` across apps/gateway/app and database; result=candidate 8727b62cc removes the `protected` column, drops it from fillable/cast/saving-sync/factory, and clears all five node-security/metrics/installer writers plus the dead FirewallRuleProbe adopt key; only survivors are the write-blocking mutator (FirewallRule.php:72) and the derived-read serializer (FirewallRuleQuery.php:178), both intended
- Review: passed - fresh Claude reviewer 2480 (independent): core derivation correct (get-mutator wins over stored column pre/post migration), no remaining write/persist path, where-clause removal semantically equivalent, both mutation/deletion guards + serializer read derived value, migration idempotent with intentional no-op down, blast radius independently verified, docs no drift, evidence confirmed matching SHA. human-judgment=not-required. VERDICT: PASS
- Reviewed feature tip: 8727b62cc402561d0832731061510234cdbdd563
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 8727b62cc402561d0832731061510234cdbdd563
- Accepted main tip: 6bcf2ede64aed6ec1915a6aec2ab0d96585e724a

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
