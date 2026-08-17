# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/2/todo/p11-a-delete-the-obs--760`
- Worktree: `/Users/nckrtl/orbit/.worktrees/solo-760-remove-update-all-direct`
- Branch: `solo-760-remove-update-all-direct`

## Goal

The obsolete direct `POST /api/update/all` execution lane is gone: the route (routes/api.php:338), `UpdateAllController`, and the SDK request/response/stream-client stack that targets `/api/update/all` (`UpdateAllRequest`, `UpdateAllStreamRequest`, `UpdateAllResponse`, `UpdateAllGatewayStreamClient` + its test) plus the gateway `UpdateAllGatewayStream` contract/binding/`SdkUpdateAllGatewayStream` if exclusively tied to it, are removed, while `POST /api/update/all/start` (`UpdateAllStartController`) and all Services/Updates durable logic keep working and the OpenAPI schema no longer advertises the old route.

## Scope

- Owned: apps/gateway old route + `UpdateAllController`; the old-route SDK stack under packages/sdk (`Requests/Operations/UpdateAllRequest`, `UpdateAllStreamRequest`, `Responses/Operations/UpdateAllResponse`, `UpdateAllGatewayStreamClient` + `tests/Unit/UpdateAllGatewayStreamClientTest`); the gateway `UpdateAllGatewayStream` contract, `SdkUpdateAllGatewayStream`, and AppServiceProvider binding IF exclusively serving the old route; obsolete apps/cli test harness references to `/api/update/all` (`update_all_gateway_liveness_router.php`, GatewayApiClientTest cases); Scramble/OpenAPI snapshot; focused route/operation/SDK tests.
- Constraints: FIRST trace the real runtime path of `orbit update:all` and confirm it uses `/api/update/all/start`, not the old direct route — if any live consumer (CLI command, contract binding used at runtime) still routes through the old stack, STOP and report rather than break it. Keep `/start` + Services/Updates untouched. gateway+SDK+cli PHP; declare(strict_types=1); Mago/Rector clean; mago baselines pruned of removed-file entries. Removal must not leave dangling references or broken OpenAPI.
- Out of scope: `/api/update/all/start` behavior, Services/Updates durable logic, unrelated update commands, and any new functionality.

## Proof

- Verification:
  - focused: passed - gateway `UpdateAllDirectRouteRemovedTest` (POST /api/update/all -> 404; POST /api/update/all/start -> 202 with operation_run.type=update:all) + `UpdateAllStartControllerTest`; sdk `RetiredUpdateAllDirectStackTest` (4 retired classes class_exists=false) + full sdk Pest; cli GatewayApiClientTest; OpenAPI schema/surface; RED-first (absence tests failed while old route/classes existed), GREEN after removal
  - broader: passed - `composer quality-check` exit 0 on 98148e077bf377907d6d3a86c9b178f60b16f48b, dirty=false, 45/45 subgates zero (docs-lint, gateway/sdk/cli Pest + Mago + Rector, Cargo); receipt `.orbit/quality-gates/quality-check-2026-08-17T200454Z-40b7aefdf612.json`
  - runtime: passed - candidate=98148e077bf377907d6d3a86c9b178f60b16f48b; venue=retained-incus; environment=dev-fixture; command=on retained topology dev-56ff19 gateway (docker orbit-gateway) ran route:list + class_exists tinker + HTTP POST from the gateway node (candidate bound by sha256); expected=obsolete POST /api/update/all removed (route unregistered, controller+contract gone) while POST /api/update/all/start keeps working; observed=route:list shows only api.update.all.start, UpdateAllController+UpdateAllGatewayStream class_exists=false and UpdateAllStartController=true, HTTP POST /api/update/all returns 404 while POST /api/update/all/start returns 202; result=passed; evidence=`.orbit/evidence/solo-760-retained-incus-route-removal-proof.json`
- Blast radius: complete - evidence=repository-wide `rg "UpdateAllController|UpdateAllStreamRequest|UpdateAllRequest|UpdateAllResponse|UpdateAllGatewayStreamClient|UpdateAllGatewayStream|SdkUpdateAllGatewayStream"` across apps/packages (non-vendor); result=old direct lane fully removed (route api.php:338, UpdateAllController, UpdateAllGatewayStream contract + SdkUpdateAllGatewayStream + AppServiceProvider binding, SDK UpdateAllRequest/UpdateAllStreamRequest/UpdateAllResponse/UpdateAllGatewayStreamClient + old test); only survivors are the intended retirement-guard tests and the preserved UpdateAllStart* /start lane; OpenAPI + command-catalog.json + docs update-all.md no longer advertise the old route; mago baselines (gateway+sdk) pruned; committed candidate clean (gitignored vendor autoload caches excluded)
- Review: passed - fresh Claude reviewer 2485 (independent): client-side removal safe (CLI uses /start; old stream contract binding-only, no runtime consumer left dangling), blast radius clean (rg across apps/packages non-vendor returns only the two retirement-guard tests), durable /start lane + Services/Updates fully intact, OpenAPI/schema/catalog drop only the old route (SDK surface 175->174), new tests meaningful+RED-first (gateway 14/14, SDK 1/1 verified GREEN), no e2e lane touched. No issues found; evidence independently confirmed. human-judgment=not-required. VERDICT: PASS
- Reviewed feature tip: 98148e077bf377907d6d3a86c9b178f60b16f48b
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 98148e077bf377907d6d3a86c9b178f60b16f48b
- Accepted main tip: 42a0073d4481001da11623460ace97987712dfe1

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
