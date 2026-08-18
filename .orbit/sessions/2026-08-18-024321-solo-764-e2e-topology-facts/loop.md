# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/2/todo/i08-a-own-e2e-topolo--764`
- Worktree: `/Users/nckrtl/orbit/.worktrees/solo-764-e2e-topology-facts`
- Branch: `solo-764-e2e-topology-facts`

## Goal

E2E topology support facts (per-kind logical roles, physical role mappings, role aliases, and capability/support facts) are owned by ONE typed value model keyed by topology kind, so the four current call sites (E2EPreparedTopology, IncusTopologyTemplate, DockerTopologyBuilder, DockerTopologyProvider) read the same facts instead of maintaining 4 divergent copies of the kind→roles table, 3 copies of the websocket-kind predicate, and 2 copies of the canonical WireGuard address map — with every existing role/alias/support output byte-for-byte unchanged across the full Incus, Docker, prepared, and dev matrices and every alias.

## Scope

- Owned: a new typed topology-facts value model in apps/e2e/app/E2E/Support (owning per-kind logical roles, artifact↔docker↔incus physical role maps, the canonical role-alias table, the websocket-kind set, the canonical 10.6.0.x WireGuard address map, and the prod-hosts-ingress co-location fact), and migration of the four call sites (apps/e2e/app/E2E/Support/{E2EPreparedTopology,IncusTopologyTemplate,DockerTopologyBuilder,DockerTopologyProvider}.php) to read from it; matrix tests under apps/gateway/tests/Feature/E2ESupport and apps/e2e/tests/Feature/Topology extended to assert all four sources agree per kind and per alias.
- Constraints: preserve ALL intentional co-location/aliasing exactly — OperatorGatewayAppprodIngress folds ingress→prod while OperatorGatewayAppdevAppprodIngress keeps a dedicated ingress; websocket→dev co-location (Incus rolesFor + websocket-on-app-dev-1); runtime ingress=prod instance aliasing in the provider; the canonical WireGuard bytes (gateway .2, operator .3, dev .4, prod .5, agent .6, ingress .7, websocket .8). Keep provider-specific build/stage orchestration local (only facts are centralized). No behavior change; identical serialized/role/alias output for every kind. declare(strict_types=1); Mago/Rector clean. Do NOT run composer test:e2e*.
- Out of scope: E2ETopologyKind enum cases and its input-alias/deprecated-value maps; provider build/stage/lease orchestration logic beyond reading facts; any change to the topology matrix itself (no added/removed kinds or roles).

## Proof

- Verification:
  - focused: passed - `bin/orbit-gateway-pest --compact tests/Feature/E2ESupport` 1149 passed, 2 skipped; `apps/e2e composer test` 459 passed
  - broader: passed - `composer quality-check` on the clean candidate 456b4dc5 exit 0, all 45 subgates zero, git.dirty false, receipt `.orbit/quality-gates/quality-check-2026-08-18T003421Z-cb4f64267248.json`
  - runtime: passed - candidate=456b4dc5307cd3030d4b97cc00a97b12e2daee68; venue=retained-incus; environment=dev-fixture; expected=refactored E2ETopologyFacts boots a correct operator_gateway_app-dev topology (roles operator/gateway/dev, canonical WireGuard bytes gateway .2 dev .4) and all four sources resolve every kind and alias identically to the canonical facts on the runtime; observed=Part A topology dev-0d557d booted with roles operator/gateway/dev and observed WireGuard gateway 10.6.0.2 / dev 10.6.0.4 matching the facts + Part B 48 characterization tests (544 assertions) passed inside the operator VM runtime; result=passed; command=`ssh beast incus exec orbit-e2e-dev-0d557d-operator -- sudo -u orbit bash -lc 'cd /home/orbit/orbit/apps/gateway && php artisan test tests/Feature/E2ESupport/E2ETopologyFactsCharacterizationTest.php'`; evidence=`.orbit/evidence/solo-764-retained-incus-proof.md`
- Blast radius: complete - evidence=Explore duplication sweep + diff review of the four call sites and the new E2ETopologyFacts value model; result=four duplicated fact tables (kind->roles x4, websocket predicate x3, WireGuard map x2, physical slug maps) collapsed into one typed model with all four sources proven equal per kind/alias, co-location facts preserved; see `.orbit/evidence/solo-764-blast-radius-inventory.md`
- Review: passed - human-judgment=not-required; independent Claude reviewer VERDICT PASS (E2ETopologyFacts owns the facts; four call sites delegate with duplicate tables deleted; residual 10.6.0.2/AgentWebsocket hits are legitimate non-duplicates; all co-location/alias facts preserved incl. leaseInstancesFor ingress=prod at :1711-1714; enum unchanged 11 kinds parity; quality 456b4dc5 45/45 zero; retained-incus dev-0d557d Part A+B verified)
- Reviewed feature tip: 456b4dc5307cd3030d4b97cc00a97b12e2daee68
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 456b4dc5307cd3030d4b97cc00a97b12e2daee68
- Accepted main tip: 089e0e0feab7030991e62878ca27d43e7b62b1ff

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
