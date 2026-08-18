# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none
- Worktree: /Users/nckrtl/orbit/.worktrees/solo-hardening-migration-replay
- Branch: solo-hardening-migration-replay

## Goal

Gateway frozen migrations replay and roll back without silent desync or live-class dependence: 2026_08_15 bindings rollback fails loud, orphaned vs ambiguous abort copy is distinct, and 2026_08_16/17 migrations are self-contained or pin the live surface they still call.

## Scope

- Owned: `apps/gateway/database/migrations/2026_08_15_124510_move_app_bindings_to_instances.php`, `apps/gateway/database/migrations/2026_08_16_120000_drop_app_placement_shadow_columns.php`, `apps/gateway/database/migrations/2026_08_16_231522_persist_proxy_route_instance_ownership.php`, `apps/gateway/database/migrations/2026_08_17_120000_enforce_singleton_local_gateway_settings.php`, new/updated Pest coverage under `apps/gateway/tests/Feature/Migrations/`
- Constraints: data effect of every already-run migration must stay byte-identical; existing migration tests stay green unchanged except new coverage; no E2E; no merge/push
- Out of scope: live-node verification, product docs, LAND/merge

## Proof

- Verification:
  - focused: passed - migration bundle 93 passed 392 assertions on the pre-merge tip including new replay-safety and pinning suites
  - broader: passed - `composer quality-check` on clean merged commit 47ec903d7caf7af679bb72c500239c8eb2f184c5 exit 0, 45/45 subgates (`.orbit/quality-gates/quality-check-2026-08-18T185201Z-bf55af208c5a.json`); pre-merge full gateway suite 6972 passed 2 skipped
  - runtime: passed - candidate=47ec903d7caf7af679bb72c500239c8eb2f184c5; venue=retained-incus; environment=dev-fixture; target=orbit-e2e-dev-e9c490-gateway; expected=exact candidate replays the guarded bindings migration idempotently with loud irreversible rollback and frozen app-class copies byte-identical in effect in the routed retained gateway environment; observed=matching bindings migration sha256 fe8f8ba15677 and 79 tests passed 294 assertions in the retained gateway instance after checkout.migrate replayed the chain; result=passed; evidence=`.orbit/evidence/solo-hardening-migration-replay-retained-incus-runtime.txt`
- Blast radius: complete - evidence=frozen-copy pinning tests plus full gateway Pest suite; result=four migrations gain replay or rollback guards with data effects proven unchanged, frozen SINGLETON_KEY and ownership maps pinned against live-class drift, remaining live dependency AppProxyRouteRuntimeUpstreamBackfill pinned by surface test, full suite 6972 passed
- Review: passed - orchestrator Claude reviewer VERDICT PASS: irreversible down plus idempotent up guard close complementary holes on the bindings migration, abort message now distinguishes orphaned from ambiguous bindings, inlined copies byte-identical with pinning tests failing loud on drift; human-judgment=not-required
- Reviewed feature tip: 47ec903d7caf7af679bb72c500239c8eb2f184c5
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 47ec903d7caf7af679bb72c500239c8eb2f184c5
- Accepted main tip: 1a3431e688729481229ad5e59c661e0c7c3a0a7c

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
