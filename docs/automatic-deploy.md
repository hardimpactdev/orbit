# Automatic production deployment policy

This is step 2 of slice 1 in [ADR 0238](https://github.com/hardimpactdev/orbit/blob/main/docs/decisions/0238-trial-two-swamp-slices-before-a-wider-move.md). The Gateway runner remains the active writer. This change adds an unscheduled Swamp workflow and local policy tests. It does not authorize cutover.

`automatic-production-deploy` takes one Instance ID. Its stable behavior name is `automatic-deploy-<instance>`. Swamp serializes method execution on that behavior. Each invocation performs one bounded tick. No worker is held during backoff. The method records its decision and throws on failure, so a failed policy read or a missing Gateway contract fails the native workflow run.

The extension's `Gateway` interface describes the evidence the policy needs. It is an internal port, not a proposed endpoint. The registered adapter uses only existing Gateway reads and the automatic deploy route. It currently reads the Instance and its deployment list, records G01–G06, and stops. It cannot reach admission with an enabled Instance until the missing contracts are implemented. It never substitutes the native tick's cached head for a fresh read, infers rollback kind, calls GitHub directly, or starts a manual deployment.

## Eight policy flows

The denominator stays eight, as the ADR requires.

| Flow | Policy behavior | Frozen cases |
| --- | --- | --- |
| Selection | Walk at most 20 first-parent candidates, newest first. Require descent from the deployed commit and an exact, completed, successful Actions check. Every matching run must qualify. Ungated Projects select the head. | P09, P41 |
| Unchanged/busy handling | Start nothing for an unchanged head. Preserve the holder's receipt ID. Distinguish `deploying` from `busy`. Disabled, inactive, and development Instances are left to their owner. | P01, P23, P27, P43 |
| Gate recheck | Read pending checks once per walk per tick. Cache failed answers for five minutes. Invalidate cached answers when the head, deployed commit, or blocked set changes. No-green answers remain settled until that key changes. | P02–P05, P07–P08, P25–P26 |
| Backoff/blocking | Retry transient failures after 10 and 20 minutes. Block after three failures or immediately for a lasting cause. A manual deployment resets failure accounting. Request ordinals remain distinct after that reset. | P06, P11–P13, P42 |
| Manual pause | Any manual status pauses until explicit resume. Wait for a successful first deployment. | P10, P24, P38 |
| Rollback/resume | Keep rolled-away blocks in durable policy state. Import Gateway blocks that were recorded before pruning. A queued rollback is busy without a stall, including when it wins admission after the policy read. | P14–P22, P39 |
| Alerts | Store one failure intent per commit, one pause intent per deployment, and one stall intent per waiting episode after 30 minutes. Skip unsupported alert repositories. End a stall when the commit deploys. | P28–P37 |
| State/history projection | Preserve the public field shape and stable `since`. Produce notable Activity intents only when result or commit changes. Keep policy state, effect receipts, and stalls separate. | P40 |

## Durable state and effects

`state-<instance>` stores policy memory. `decision-<instance>` stores its projection, new alert/Activity intents, or an explicit contract failure. Repeated unchanged ticks do not write policy state or produce another notable Activity intent. Decision snapshots can still be written. They are not deployment history rows.

Before an automatic POST, the policy stores an admission intent. The request key is `auto.<instance>.<full-commit>.<attempt>`. The ordinal increases for that Instance and commit. It never resets after a manual attempt. Failure counts still reset there. This prevents a later retry from receiving an old operation through key reuse.

The body is exactly `{ commit }`. The endpoint is `POST /api/v1/instances/{instance}/deploy/automatic` (MCP `instance-deploy-automatic`). Services' active WireGuard peer and its directed Gateway access edge provide authority. TLS uses the Orbit root CA. There is no caller identity field or new bearer credential.

After admission, the policy reads the recorded operation before fresh history. Every terminal receipt clears the saved admission request. A terminal receipt without matching deployment history creates a separate effect hold, regardless of error code, claim count, or failed/cancelled status. This includes environment-lock refusals, unexpected Gateway exceptions, and cancellations after a worker started. The same conservative rule covers a terminal success whose history evidence is missing. A commit-blocked receipt also preserves the rolled-away block. No error-code allowlist decides whether a terminal request is resolved.

A later manual deployment, in any status, clears the effect hold. That manual attempt pauses automation until explicit resume. Resume alone or a new commit does not resolve the hold. A manual deploy followed by resume also counts when it ran before the policy first observed the terminal receipt. The admission intent captures the manual-history cursor so both orders use one rule. Receipt-based and history-based worker-loss holds use this same resolution boundary.

**Intended divergence from PHP (ADR 0238):** a worker-loss history row or a terminal receipt without matching history stops newer commits on the Instance too. PHP blocks only the failed commit. The stricter target hold implements the ADR's unknown-write rule. A later manual deployment and resume reset that hold.

A lost response, unreadable receipt, idempotency conflict, or other outcome without a terminal receipt keeps the admission request. A known queued/running/waiting receipt is followed without starting another operation. Repeating the same automatic POST can recover an existing receipt, but can also admit a request that has not arrived yet. G06 therefore needs a lookup that cannot admit work late. A 503 `operation.queue_unavailable` is a refusal before any operation exists, so it clears the request. A dispatch failure supplies an operation ID: the next read resolves its terminal receipt using the same effect-hold rule. A target-busy refusal names another operation; that holder is read to identify a rollback. Gate read failures map to `instance.auto_deploy_gate_unavailable`; G01 still prevents live gate reads.

The Gateway's API middleware `NormalizeErrorDetails` sends empty error details as `{}`. The client accepts that wire shape and has no empty-array parsing path. This was checked against Orbit main `6c71412ea9500d1894160535a2845510fecabc6f`, its middleware registration, and `DomainExceptionRenderingTest`.

The Gateway remains authoritative for activation, release selection, locks, cancellation, output, and deployment success. Swamp records receipt IDs and never creates a second success status. P20 and P21 only exercise the policy's observation of paused and superseded receipts. The Gateway's under-lock check and rollback cancellation still need their existing effect tests and step 3 acceptance.

Alert subjects and summaries are durable in `state.notifications` alongside their deduplication keys. They are undelivered intents. No signed webhook, filed problem, notifier Activity, or policy Activity is emitted by this PR. P37 tests payload preparation. P39 supplies synthetic durable block evidence. P40 tests the local projection. None proves public-surface integration.

## Gateway gaps for step 3

| Gap | Missing supported contract | Cases affected |
| --- | --- | --- |
| G01 | A fresh credentialed branch head, first-parent walk, ancestry comparison, and exact check-run evidence through Gateway reads. `auto_deploy.head` is a cached native result. No read route exposes the resolver inputs. | P01–P13, P15–P18, P22–P23, P25–P26, P31–P32, P34–P35, P41–P43 |
| G02 | The authoritative `auto_deploy_resumed_after` cursor and `auto_deploy_state.blocked` list. `instance:show` exposes a pause derived by the native tick, but omits these fields. #1546 stores rollback blocks before pruning; it does not make them readable to Swamp. | P14–P22, P28–P30, P33, P38–P40, P42 |
| G03 | Deployment `kind` on list/show. The DTO exposes trigger, commit, status, selected release, failure boundary, and timestamps. It omits `kind`. Do not infer it from `selected_release`. | P14–P22, P30, P38–P40 |
| G04 | A supported admission/read contract for the release alert notifier and notable policy Activity, including durable delivery identity. The allowed automatic-start primitive does not deliver these. | P23, P28–P33, P36–P37, P40 |
| G05 | Supported composition of Swamp's projection into API/MCP/CLI/web Instance reads. They still read Gateway-owned state. No policy-state write endpoint is used or invented here. | P40 and all public policy-state observations |
| G06 | A receipt lookup by automatic request key that cannot admit work late, with bound caller/target/input and safe outcome resolution. The existing idempotent POST can return a receipt, but can also start work. Operation list/show omit the key. Matching a commit in a list cannot identify a lost admission response. Until resolved, hold the target. | E05, E10, E16 recovery; all later writes on that target |

Closing these gaps belongs to step 3 and requires another reviewed change. Do not enable or schedule this workflow, deploy serve for this PR, or remove the Gateway runner. No extension is published to the registry.

## Tests and pins

The contract is [the frozen parity ledger](https://github.com/hardimpactdev/orbit/blob/f9059e1028f7ce3fe69506b2f82abaae74b3bbfa/docs/reference/automatic-deploy-parity.md). `tests/automatic_deploy_test.ts` has one named test for every P01–P43 case. The fake supplies complete policy observations that the current Gateway cannot yet return. Deploy admission and operation reads use fake HTTP responses through the real adapter. `mod_test.ts` also checks today's incomplete read DTOs and the fail-closed boundary.

These are policy-layer tests. E05, E10, E12, E14, E16, and E17 have focused client/policy checks. They do not prove live effects, authorization enforcement, or public rendering. E01–E04, E06–E09, E11, E13, and E15 remain Gateway/client acceptance work. Development defaults remain Gateway-owned. No live reliability window, ten-run timing comparison, or removal saving is claimed.

| Component | Exact pin |
| --- | --- |
| Swamp | `20261022.021804.0-sha.983e2929` (`.swamp.yaml` release `20261022.021804.0`) |
| Deno | `2.9.8` |
| Official factory | Unmodified `@swamp/software-factory` `2026.10.13.1`, as recorded in `extensions/models/upstream_extensions.json`; this policy does not add factory code |
| Test kit | `jsr:@swamp-club/swamp-testing@0.20261016.58` |
| Assertions | `jsr:@std/assert@1.0.19` |
| Schema | `npm:zod@4.4.3` |

Run only the affected checks:

```sh
deno fmt --check extensions/behaviors/automatic-deploy tests/automatic_deploy_test.ts tests/_support/automatic_deploy.ts
deno lint extensions/behaviors/automatic-deploy tests/automatic_deploy_test.ts tests/_support/automatic_deploy.ts
deno check extensions/behaviors/automatic-deploy/mod.ts extensions/behaviors/automatic-deploy/mod_test.ts tests/automatic_deploy_test.ts
deno test --allow-read=. extensions/behaviors/automatic-deploy/mod_test.ts tests/automatic_deploy_test.ts
swamp workflow validate automatic-production-deploy --json
```

The new CI job runs one test worker and validates the workflow with the pinned Swamp binary. Path exclusions keep these isolated tests out of the unrelated maintenance worker jobs. The PR records UTC start/end, wall-clock, physical lines added by area, and the new job's measured duration. This step adds policy code; it removes no Orbit code and earns no removal or CI savings yet.
