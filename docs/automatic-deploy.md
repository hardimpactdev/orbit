# Automatic production deployments

Swamp owns the production automatic-deploy policy after the Orbit cutover release for [ADR 0238](https://github.com/hardimpactdev/orbit/blob/main/docs/decisions/0238-trial-two-swamp-slices-before-a-wider-move.md). The project owner dropped the shadow comparison on 2026-10-28. Hauzer and Mealou production Instances have no users. We switch directly and observe real deployments.

## How it runs

`automatic-production-deploy` runs every minute at second 30 in UTC (`30 * * * * *`), preserving the PHP runner's one-minute cadence. Each tick reads the Gateway's complete Node list and eligible Instance list (`GET /api/v1/instances?auto_deploy=true`). It selects every active Instance with `auto_deploy.enabled` on a Node with an active `app-prod` role and no `app-dev` role. Pending, failed and removing production roles are ineligible. An optional `instances` input narrows that discovered set; an empty input covers all production Instances. Development defaults stay with PHP.

One stable behavior, `automatic-production-deploy`, serializes the fleet tick. Each Instance has `state-<id>` inside it. A failed Instance does not stop the other Instances. The method throws after processing the fleet if any Instance failed. There is no worker held during backoff. The official software factory is unchanged.

Swamp reads source heads, bounded first-parent walks, exact Actions check evidence, history, controls and keyed admission receipts through the contracts from [orbit#1548](https://github.com/hardimpactdev/orbit/pull/1548). It never reads GitHub or a database directly. Truncated walks, mixed history versions and moved heads fail closed. Stable `auto.<instance>.<full-commit>.<attempt>` keys never reset after manual deployments. The policy saves its admission intent before the automatic POST. Deploy status and success remain Gateway facts.

The first live tick imports the public projection and durable rollback blocks. Retry counts come from retained deployment history. The current Gateway DTO does not expose PHP's delivered alert keys. An existing eligible failure or pause can therefore alert once again at cutover; later Swamp ticks use durable deduplication and keyed delivery.

The Swamp Node needs its active WireGuard identity and directed access to the Gateway. Gateway authority covers the fleet reads and automatic-deploy contracts; separate access edges to each production Node are not required. The client uses `https://gateway.orbit` and the Orbit root CA. It adds no bearer credential or caller identity field.

```sh
swamp workflow validate automatic-production-deploy --json
swamp workflow run automatic-production-deploy
# Optional narrower scope; this cannot add an ineligible Instance.
swamp workflow run automatic-production-deploy --input 'instances=[101,102]'
swamp data query 'behavior("automatic-production-deploy") && name == "state-101"' --select content
```

## Release order and ownership

Merge [orbit-swamp#48](https://github.com/hardimpactdev/orbit-swamp/pull/48) and redeploy `swamp serve` on Services before merging [orbit#1555](https://github.com/hardimpactdev/orbit/pull/1555). Confirm Swamp is idle under PHP ownership first. The Gateway auto-releases on merge, so merging #1555 first leaves no writer until Services runs this version.

PHP remains the owner until the Orbit cutover release removes its production loop and changes the code-owned writer to Swamp. Swamp checks the observed owner and republishes a changed Gateway projection through guarded `PUT /api/v1/instances/{id}/auto-deploy/policy`, using the exact observed resume cursor and history version. A PHP owner reported by the Gateway, or `instance.auto_deploy_policy_owner` from publication or event admission, ends that tick without a deploy POST. A rejected ownership probe stops before admission. When the projection is unchanged, Swamp skips the probe PUT; automatic admission and policy-event admission still check ownership on the Gateway. Failed ownership probes never advance local policy state.

The deployed Gateway guards publication, policy-event admission and automatic-deploy admission by policy owner. The observed owner check protects local policy progression; each admission endpoint independently rejects an ownership change before an effect. Skipping an unchanged publication does not bypass admission checks. The Gateway rechecks the active `app-prod` role, Instance status and enabled control at admission and in the worker. `instance.auto_deploy_role_inactive` is a known refusal, so Swamp clears an unadmitted intent without an effect hold and skips that Instance. Filtered discovery uses the same eligibility rule. Development behavior stays Gateway-owned.

After evaluation Swamp publishes changed fields through the same guarded contract. Unchanged guard and final projections make no PUT. Each PUT records a command Activity row, so skipping them keeps stable minute ticks quiet. A final publication refused with `instance.auto_deploy_observation_changed` is an expected concurrent-history skip: the durable admission receipt is kept and the next tick reads fresh state. Other publication errors and failed deployment decisions still fail the run. Notable Activity and failed, paused and stalled alerts use keyed `instance.policy_event` admissions. Their durable payloads and operation receipts live with the policy state. A lost event response retries the same key and payload. A known receipt is followed instead of creating another event. Terminal event errors and partial notifier results remain available for inspection. They never produce a replacement delivery key.

## Holds and operator actions

Known queued, running or waiting deploy receipts are followed without another start. Every terminal receipt clears the admission intent. A first-attempt failed receipt with `deployment.paused`, `instance.auto_deploy_role_inactive` or `instance.auto_deploy_policy_owner`, and no deployment ID, proves that the Gateway refused before starting a deployment; it creates no hold. Other terminal receipts without matching terminal deployment history create an effect hold. Resumed attempts and worker loss keep the existing effect-evidence rules. A newer commit or resume alone cannot clear a hold.

For a known terminal outcome, inspect the operation and Instance history, make a manual deployment, then resume with `orbit instance:update <id> --auto-deploy=true`. Any manual deployment status pauses automation. The later manual attempt resolves an effect hold; explicit resume allows selection again. The same rule applies if the manual attempt happened before Swamp first saw the terminal receipt.

For a lost automatic-start response, Swamp uses the read-only keyed receipt lookup. A missing receipt means the old request might still arrive. It keeps the intent and never POSTs a replacement deploy. After ten minutes it calls `POST /api/v1/instances/{id}/deploy/automatic/resolve` (MCP `instance-deploy-automatic-resolve`) with the original `request_key` and `commit`, using the same Gateway-peer identity. This returns the existing operation, or atomically fences an absent admission with a retained cancelled operation: zero attempts, `error_code=operation.admission_fenced`, and `result.admission_fenced=true`. G06 returns this same receipt. A late admission with the old key returns that receipt and starts nothing.

Swamp reads fresh history after resolution. A real operation follows the normal queued, terminal and effect-hold rules. Only the proven zero-attempt fence clears an absent admission with no effect hold. The next tick can use a fresh attempt key. A lost or unreadable resolution response keeps the original intent. The ten-minute timer starts before the original POST; imported intents without a timer start it on their first unresolved observation.

An operator can accelerate this exit: disable auto-deploy for the Instance, inspect its key and commit in `state-<id>`, and call the Gateway CLI resolution action with that Instance, key, commit and original caller Node ID. The cutover CLI command is `orbit instance:deploy:automatic:resolve <id> <request-key> <full-commit-sha> --caller-node=<original-node-id>`. An existing operation is returned unchanged. Inspect it before resuming. For a fence, re-enable auto-deploy and let Swamp read the receipt; it clears the intent and waits until the next tick for a new request. Manual deployment and resume alone do not prove a missing admission safe. Never edit Swamp state to bypass this boundary.

Inspect `state-<id>` for the held request key, commit and operation ID. Inspect Gateway operation receipts and deployment history before intervening. A read outage keeps durable intents. `operation.queue_unavailable` proves no admission and clears the request. A dispatch failure with an operation ID is reconciled through its receipt.

## Observe and switch back

After the paired release, watch real production deployments, public policy state, Activity and release alerts. Exercise manual pause/resume and lost-response recovery in a disposable environment. Record failures, stuck runs, unknown effects and operator time. This PR claims no completed live reliability window or production acceptance.

Use this switch-back order, shared with orbit#1555:

1. Disable `auto_deploy` on the affected Instances.
2. Wait until no automatic operation is running or queued.
3. Revert orbit#1555 (code-only).
4. Re-enable `auto_deploy` (PHP owns again).

Keep this PR deployed through the switch-back. Swamp stays idle because the restored Gateway refuses policy publication under PHP ownership. Reverting this PR afterwards is optional cleanup. Reverting this PR alone, or before #1555, leaves no writer and must not be done. Pausing a Swamp schedule alone is not a drain: the pinned scheduler can still start already queued fires. There is no runtime owner switch or automatic fallback.

## Decisions taken

- Keep one-minute ticks, offset to second 30. The fleet method makes zero `runModel` calls: discovery and per-Instance calls use the direct Gateway HTTP client, and state reads and writes use its own resource context. Swamp's 100 cross-behavior calls per execution therefore remain unused for every discovered Instance; the cadence change does not increase that per-execution count.
- Use the Gateway's eligible Instance filter for active-role discovery. Treat the Gateway's role refusal as a known non-admission without a hold; retain genuine unknown-outcome holds.
- Ship Swamp on Services before the auto-releasing Gateway cutover. Keep Swamp deployed while switching back through the four steps above.

## Checks

Use Swamp defaults: Deno 2.9.8, `jsr:@swamp-club/swamp-testing@0.20261016.58`, `jsr:@std/assert@1.0.19` and `npm:zod@4.4.3`. Swamp is pinned to `20261022.021804.0-sha.983e2929`. The factory remains the unforked `@swamp/software-factory` `2026.10.13.1`.

```sh
deno fmt --check extensions/behaviors/automatic-deploy tests/automatic_deploy_test.ts tests/_support/automatic_deploy.ts
deno lint extensions/behaviors/automatic-deploy tests/automatic_deploy_test.ts tests/_support/automatic_deploy.ts
deno check extensions/behaviors/automatic-deploy/mod.ts extensions/behaviors/automatic-deploy/mod_test.ts extensions/behaviors/automatic-deploy/gateway_test.ts tests/automatic_deploy_test.ts
deno test --allow-read=. extensions/behaviors/automatic-deploy/mod_test.ts extensions/behaviors/automatic-deploy/gateway_test.ts tests/automatic_deploy_test.ts
swamp workflow validate automatic-production-deploy --json
```

Manual deployment operations may encode empty input as `[]`; Swamp normalizes only an empty array to `{}` and still rejects non-empty arrays. The frozen P01–P43 ledger remains covered. HTTP fixtures use Gateway DTOs and official Swamp test contexts. They cover discovery, ownership refusals, event delivery, guarded publication, receipts and read-version changes. These tests do not prove production access or live effects. Deploying Services and publishing to the Swamp registry are outside this change.
