---
title: "Port a kind to Swamp"
description: "Steps, template, native mapping and anti-patterns for porting a legacy Orbit command to a Swamp workflow."
---

# Port a kind to Swamp

Start every kind PR from this page. Review every kind PR against it and the [binding shape guide](https://github.com/hardimpactdev/orbit/blob/main/swamp/docs/swamp-shape.md). Ship the Gateway admission, native workflow and old executor deletion together. Agents load it through the [swamp-port skill](https://github.com/hardimpactdev/orbit/blob/main/.claude/skills/swamp-port/SKILL.md). Lane 26 owns this page and adds a lesson after every merged port.

## Translate a legacy command

Work through these steps in order before you fill the template.

1. **Start from the legacy sequence (F9).** The pre-Swamp Gateway action is the spec. Read it at the migration baseline with `git show 6291bb036:<path>`. Note each phase and the Node it changes.
2. **One legacy phase, one step.** Each legacy phase becomes one script-file step. It keeps the phase's legacy edge cases.
3. **Map each phase to Swamp.** Use the [mapping table](#legacy-pattern-to-native-feature). Prefer an official model (`@swamp/ssh`, `@swamp/cfgmgmt`) over a script. Record what you checked for the PR body.
4. **Write the intent.** Every non-secret field the steps need goes under `intent`, frozen at admission. Every secret becomes a `{vault, key}` descriptor under `execution_secrets`.
5. **Refuse before the first write.** The action script checks preconditions first and exits with a public `error_code`, as the #2266 branch check refuses `instance.branch_in_use`.
6. **Write the result and budget.** The action script prints one public JSON object. Set the [time budget](#set-the-time-budget).
7. **Wire the Gateway.** Admission, intent, execution copies, grants and the projector; see [Fill the Gateway declaration](#fill-the-gateway-declaration).
8. **Delete the old path in the same PR.** Remove the executor, its tests, scripts and callbacks, and its `swamp-shape-baseline.json` rows.

## Fill the workflow

Replace every `REPLACE_*` token, generate a new workflow UUID, and define the family's public intent schema. This example runs an action group on 209. For an effect on another Node, replace `command/shell` with official `@swamp/ssh` from 209, using the approved identity file and stored full host keys; see [Node execution](/reference/swamp-command-steps#node-execution-through-official-ssh). Use one selector in the effective placement: `target` only, and no `labels` at any inherited scope.

Copy shape from the smallest clean reference that fits. [`firewall.inspect`](https://github.com/hardimpactdev/orbit/blob/main/swamp/workflows/workflow-operation-firewall-inspect.yaml) is one pinned SSH read and a typed result. [`schedule.create`](https://github.com/hardimpactdev/orbit/blob/main/swamp/workflows/workflow-operation-schedule-create.yaml) uses `@swamp/cfgmgmt` with `forEach`. [`instance.create`](https://github.com/hardimpactdev/orbit/blob/main/swamp/workflows/workflow-operation-instance-create.yaml) shows shipped scripts, guards and an `assert` refusal. The guide's Template section still names `node.bootstrap_probe`, but do not copy its placement; it sets both selectors.

```yaml
id: REPLACE_WORKFLOW_UUID
name: operation-REPLACE_KIND
description: REPLACE_ACTION_GROUP
vaults: [orbit-control-inputs]
queueTimeout: REPLACE_QUEUE_SECONDS
inputs:
  type: object
  properties:
    operation_id: {type: integer, minimum: 1}
    node_id: {type: integer, minimum: 1}
    kind: {type: string, enum: [REPLACE_KIND]}
    intent:
      type: object
      properties:
        start_tag: {type: string, minLength: 1}
        public_value: {type: string}
      required: [start_tag, public_value]
      additionalProperties: false
    execution_secrets:
      type: object
      properties:
        credential:
          type: object
          properties:
            vault: {type: string, enum: [orbit-control-inputs]}
            key: {type: string, pattern: '^REPLACE_KEY_PATTERN$'}
          required: [vault, key]
          additionalProperties: false
      required: [credential]
      additionalProperties: false
  required: [operation_id, node_id, kind, intent, execution_secrets]
  additionalProperties: false
jobs:
  - name: execute
    steps:
      - name: assets
        target: orbit-node-209
        task:
          type: behavior
          behaviorType: '@nckrtl/REPLACE_KIND-assets'
          behaviorName: '${{ "assets-" + run.id }}'
          methodName: read
      - name: action
        target: orbit-node-209
        dependsOn: [{step: assets, condition: {type: succeeded}}]
        task:
          type: behavior
          behaviorType: command/shell
          behaviorName: '${{ "action-" + run.id }}'
          methodName: execute
          inputs:
            run: REPLACE_F1_RUN
            env:
              ORBIT_OPERATION_ID: '${{ string(inputs.operation_id) }}'
              ORBIT_START_TAG: '${{ inputs.intent.start_tag }}'
              ORBIT_PUBLIC_VALUE: '${{ inputs.intent.public_value }}'
              ORBIT_CREDENTIAL: '${{ vault.get(inputs.execution_secrets.credential.vault, inputs.execution_secrets.credential.key) }}'
```

`assets.read` is a family metadata method: resolve shipped files with `ctx.extensionFile`, record public paths with `ctx.writeResource`, and return their paths. List scripts and imports under manifest-relative `additionalFiles`. Keep this method and the command on the same worker; never assume a checkout exists there. TypeScript shapes inputs and outputs; operational code lives in script files.

F1 is pending; lane 16 owns it. When it lands, shipped scripts run through its shared wrapper, and its shared projector records `failed_step` and the last 4 KB of redacted stderr as `error.detail` for any failed step, including SSH, typed and `assert` steps. Until then, `REPLACE_F1_RUN` is a plain invocation of the shipped script from the asset paths. Add no family wrapper. Never print credentials, private keys or unredacted diagnostics.

A kind with more than one effect step adds a `result` step with `condition: always` as its `terminal_result`. It maps the first failed step to a public `error_code`, and a trailing `assert` on its `ready` output fails the run, as `instance.create` does. Without it, a failure in an earlier step projects only `operation.native_failed`.

One script file owns one action group, such as all git commands for a checkout. It runs as a placed `command/shell` step on 209, or through `@swamp/ssh` `script` for another Node. It is idempotent for the admitted `operation_id` and start tag. It refuses with a public `error_code` and a nonzero exit. It passes secrets to tools on stdin or in protected config files, never argv or tracing. Use native guards, resume, concurrency and method locks; Swamp has no step retry setting. Named apt and UFW host locks are allowed. Declare every vault the run reads, in parents and children, and check the actual grants.

## Fill the Gateway declaration

Add these fields to the family's existing registration and return the full input envelope from its projector.

```php
// In the family's existing direct-operations registration:
'REPLACE_KIND' => [
    // Keep its existing admission, workflow and projector registration.
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'execute', 'step' => 'action'],
],
// Only when the run reads a Gateway-owned secret:
//  'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^REPLACE_KEY_PATTERN$']],
// Projector input(): ['intent' => $fullPublicIntent,
//                     'execution_secrets' => $namesOnlyDescriptors]
```

Pass `lockKey: OperationStore::lockKey(REPLACE_TARGET_TYPE, $id)` to `StartOperationAction::execute` when the kind changes a shared target; it is an Operation column, not a declaration key. Add the workflow's `run` and `read` grant and any `execution_copies` vault to `swamp/config/gateway-workflow-grants.json`. Key patterns must pass Swamp's regex-safety check: expand optional groups into a flat alternation.

| Done | Gateway checklist |
| --- | --- |
| [ ] | Validate and record the complete immutable intent at admission. Use shared `StartSwampRun`; its builder sets Operation, kind and Node identity. Remove intent, private-material and status callbacks. |
| [ ] | Use shared `SwampCommandStep::executionCopy` when a Gateway-owned secret changes. Pass only `{vault, key}` descriptors. VM-created Pi and provider credentials stay on the VM. |
| [ ] | Declare one fixed terminal selector. The shared `projectTerminal` reads the run's history once and calls the family projector, which settles from native status and public outputs. |

## Set the time budget

The execution plan (`PLAN.md` on beast, F8) requires a budget for every kind. A port PR without one fails review.

| Item | Rule |
| --- | --- |
| Measured p95 | Take step `durationMs` and run start and end times from native history of the kind's runs. Use live runs when they exist, otherwise native harness runs. State the sample size. |
| Run budget | Set it from the measured p95 with headroom, not a guess. PLAN sets the `instance.create` policy: under 10 s, budget 60 s. That is a target, not measured proof. |
| Timeout at start | Pending: lane 16 adds the shared field, and the extensions lane tests native expiry. Until then, state the budget in the PR body. Add no family timer. |
| Expiry | Pending, as above. The intended result is a failed Operation with `operation.timed_out` plus F1 detail. |
| `queueTimeout` | Set it per kind in the workflow so a step fails fast when no worker is free. Keep it below the run budget. F8 replaces the guide's inherit-600 s rule. |

## Keep steps few

Step count is the dominant cost of a run. Op 6639 took 957 s, while its 15 official SSH methods took 4.6 s in total. The extensions lane is finding the cause. Until it reports, follow these rules.

| Rule | Detail |
| --- | --- |
| One script per Node per phase | Reads, precondition checks, writes and the result JSON go in one action-group script. |
| No extra decision steps | Make decisions and `assert`-style checks in the action script. Use a typed step only for a result the script cannot print, and a separate check only for another Node's read. |
| Justify each step | The PR names the resume point, Node change or guard that each extra step serves. |

## Fan out over computed data

Official e459 expands `forEach` before earlier step outputs exist. A `forEach.in` that reads `steps.<name>.outputs` fails, or silently expands to nothing behind a `has(steps...)` check. The manual does not say when `in` is evaluated, so treat this as the rule. `forEach.in` reads only `inputs` and literals. When the list depends on a step's observation, put the fan-out in a native child workflow. The parent step computes the plan; a `type: workflow` step passes it as child `inputs`, and the child's `forEach` reads its own inputs. `workflow-database-clone-mysql.yaml` passes step output into child inputs this way. A child run adds steps, so first try to compute the list at admission.

## Test layout

Put each test where its project's CI runs it.

| Where | What it proves |
| --- | --- |
| `apps/gateway/tests/Feature/<Family>/*NativeTest.php` or the family's admission test | Admission, validation, refusal, lock key, execution copy, the exact input envelope, and projection of success and failure from a history fixture. |
| `apps/gateway/tests/Feature/Operations/GatewayWorkflowGrantCheckTest.php` | The grant manifest covers the new workflow and its vault keys. |
| `swamp/extensions/behaviors/operations/<family>/*_test.ts` | Typed decision and result behaviors, with Deno. |
| `swamp/tests/<family>_native_test.py` or `operations/<family>/*_native_test.py` | The workflow on the shared native harness: success, refusal before any write, first-failure skip chain and public result. |
| `swamp/tests` script tests | Each shipped script file directly, plus shellcheck or ruff. |
| Parity tests ported from the legacy feature tests | Every legacy case, including edge cases, gives the same public result natively. |
| A generator drift test, when a generator writes the workflow | The committed YAML equals the generator output. |

## Check all seven boundary decisions

Apply every boundary decision to admission, execution and projection. Atomic state and idempotency checks stay. Root CA and GitHub App private keys stay only in the Gateway. Steps may call only the exact POST mint/sign primitives scoped to the admitted positive numeric Operation. Operator-native resume reconciles through decision 5's exact identity lookup.

| Decision | Required behavior |
| --- | --- |
| 1. Serialization | Keep the generic Operation admission `lock_key`, released by terminal projection. Use `OperationStore::lockKey`. Add no family busy hold or custom mutex. |
| 2. Model state | Official models may keep sensitive state in the `default` vault with scoped grants. Add no separate outputs vault. |
| 3. Key custody | TLS leaf keys and agent rekey secrets stay on their service Node. Only public CSRs, certificates and verifier hashes move. |
| 4. Cancellation | Use the existing native admin/operator path. Shared run tokens get no global admin or custom cancellation channel. |
| 5. Uncertain start | Make one native-history lookup. Bind only on an exact admitted-identity match. No polling or automatic resubmission. |
| 6. Schedules | Publish full intent and updates into native schedules. Scheduled steps never POST admissions to the Gateway. |
| 7. Interrupted | Native `interrupted` is Gateway-terminal, projected failed. Never resume or reopen an existing Operation. Recovery uses a new admission or the family's normal retry. |

## Legacy pattern to native feature

Use the native feature in the right column. Custom code is allowed only where the manual has a real gap; send that gap to the orchestrator with evidence before you build.

| Legacy pattern | Native replacement | Manual |
| --- | --- | --- |
| Gateway reads intent or private material; secret on stdin or argv | Full intent as `inputs`; `{vault, key}` names; whole `${{ vault.get(...) }}` in `task.inputs.env` or `@swamp/ssh` `stdin` | [Inputs](https://swamp-club.com/manual/reference/workflows#inputs), [Environment mounting](https://swamp-club.com/manual/reference/vaults#environment-variable-mounting) |
| Retry loop or `attempt < N` | Idempotent step. Recovery is a new admission (decision 7) or operator `swamp workflow resume --run`, which never reopens the Operation and reconciles through decision 5; Swamp has no step retry key | [Retry failed steps](https://swamp-club.com/manual/reference/workflows#retry-failed-steps) |
| Sleep or poll until ready | One check step that fails when not ready. Keep tool-owned waits such as `cloud-init status --wait` | [Guard](https://swamp-club.com/manual/reference/workflows#guard), [Retry failed steps](https://swamp-club.com/manual/reference/workflows#retry-failed-steps) |
| Wait for a free worker, or for a person | Per-kind `queueTimeout` (F8; serve default 600 s); `manual_approval` task | [queueTimeout](https://swamp-club.com/manual/reference/workflows#queuetimeout), [Manual approval](https://swamp-club.com/manual/reference/workflows#manual-approval-manual_approval) |
| Lock file, `flock`, family busy hold | Per-behavior method lock on one shared `behaviorName`, `concurrency`, or the generic admission `lock_key`. Named apt and UFW tool locks stay | [Method locking](https://swamp-club.com/manual/reference/behavior-definitions#per-behavior-method-locking), [Concurrency](https://swamp-club.com/manual/reference/workflows#concurrency) |
| "Already done" receipt or marker file | `guard` on public state or prior outputs | [Guard](https://swamp-club.com/manual/reference/workflows#guard) |
| Best-effort cleanup or compensation in code | A visible step that depends with `condition: failed` or `always`, or `allowFailure` | [Trigger conditions](https://swamp-club.com/manual/reference/workflows#trigger-conditions), [allowFailure](https://swamp-club.com/manual/reference/workflows#allowfailure) |
| Child Operation, or a loop over items in one program | `type: workflow` child; `forEach` over `inputs` with a `behaviorName` unique per run and item | [forEach](https://swamp-club.com/manual/reference/workflows#foreach-1), [Child workflows](https://swamp-club.com/manual/reference/workflows#workflow-workflow) |
| One program for many Nodes, or a call to another Node's agent | Placed steps in one run, each through `@swamp/ssh` from 209 | [Placement](https://swamp-club.com/manual/reference/workflows#target-2), [`@swamp/ssh`](https://github.com/swamp-club/swamp-extensions/tree/main/ssh) |
| Precondition refusal after a write | Check first in the same script and exit with an `error_code`; an `assert` step only for another Node's read | [Assert](https://swamp-club.com/manual/reference/workflows#assert-assert) |
| Custom timeout or process supervisor | `command/shell` `timeout` input, `@swamp/ssh` `timeoutSec`, native cancel | [Behavior definitions](https://swamp-club.com/manual/reference/behavior-definitions) |
| Script in a TypeScript string | Script file in manifest `additionalFiles`, resolved with `context.extensionFile`, or YAML `run: \|` | [additionalFiles](https://swamp-club.com/manual/reference/extensions/manifest#additionalfiles) |
| File, package, unit or firewall writes over SSH | `@swamp/cfgmgmt` `file`, `systemd`, `firewall`, `exec`, behind a host-key pin preflight as [`firewall.direct`](https://github.com/hardimpactdev/orbit/blob/main/swamp/workflows/workflow-operation-firewall-direct.yaml) does; else `@swamp/ssh` | [`@swamp/cfgmgmt`](https://github.com/swamp-club/swamp-extensions/tree/main/cfgmgmt) |
| Progress, result or admission POSTs to the Gateway | Step outputs and terminal projection; native schedules with full intent through `SwampSchedulePublisher` | [Data access](https://swamp-club.com/manual/reference/data#cel-access), [Schedules](https://swamp-club.com/manual/reference/workflows#triggerschedule) |
| Cancel flag polled by steps | Native `swamp workflow cancel`; steps honour `context.signal` | [Cancellation](https://swamp-club.com/manual/reference/workflows#execution-cancellation) |

## Anti-patterns

Each of these is a review BLOCK. Most come from the [conformance ledger](#changelog).

| Anti-pattern | Why it fails | Do this instead |
| --- | --- | --- |
| `target` and `labels` in one effective placement, or a step on the effect Node | Selectors can disagree, the top audit finding; lane 21 moves per-Node worker steps to 209 | `target: orbit-node-209`, then `@swamp/ssh` |
| `forEach` items or concurrent runs sharing one `behaviorName` | The model lock serializes them and later items time out | `behaviorName` unique per run and item |
| A many-action TypeScript behavior, an agent action call or port, or a spawn wrapper | Swamp cannot show, guard or resume the actions; the agent only observes | One step per action group in native `command/shell` or `@swamp/ssh` |
| `forEach.in` reading `steps.<name>.outputs` | e459 expands it before the outputs exist | Compute the list at admission, or fan out in a child workflow |
| A step for each read, decision or check | About a minute of overhead per step in op 6639 | One script per Node per phase |
| Dereferencing outputs of a failed or skipped step | CEL fails with `No such key` and hides the first error | Depend on `or [succeeded, skipped]` and guard on prior failure |
| Strict format checks on a third-party token | GitHub stateless tokens contain dots; the check refused a valid mint | Check only documented properties: length, scope, expiry |
| A kind without a measured p95 and run budget | Runs can wait for minutes, as op 6639 did | [Set the time budget](#set-the-time-budget) |
| A workflow that reads a vault without `vaults:` | Validate passes, the run fails (op 6413) | Declare every vault it reads; `vaults: []` when none is a convention |
| A secret in `run:`, argv, inputs or `set -x`; a GitHub App or CA key in a vault | History and `ps` expose it; root-of-trust keys stay in the Gateway | `env` from `vault.get`, then stdin or a 0600 file; the mint and sign primitives |
| A failure reporter, Gateway re-read timer, binding loop or retry | Duplicates Swamp and stalls Operations | Native status and one history read per terminal delivery |
| Redesigning the legacy sequence without a written reason | The legacy Gateway ran each kind reliably; a redesign loses edge cases | Keep the legacy phases, or give the reason in the PR |
| A non-default Swamp setting without a PLAN row | Hides a deviation from audit | Record reason and removal condition in PLAN |

## PR and review checklist

Continue ports in the approved shape while live admission proof is pending. Use this checklist for every kind PR and its review.

- [ ] Follow the [translation steps](#translate-a-legacy-command) and avoid every [anti-pattern](#anti-patterns).
- [ ] Keep the legacy phase order, or state the reason for each change. Name the core feature and official extension checked.
- [ ] Use defaults. Record each deviation's measured reason and removal condition in PLAN.
- [ ] Delete the old executor, embedded scripts, callbacks, and CLI, SDK and docs paths only it used, in the same PR. Leave `apps/agent` alone.
- [ ] Report Orbit app/test net lines and Swamp lines separately.
- [ ] State the measured p95, its sample, the run budget and `queueTimeout`. Without them the review fails.

Check the completed port and report what the evidence proves.

- [ ] Cover success and refusal. Check redacted failure detail, cancellation and interrupted projection.
- [ ] Validate completed YAML with the pinned native runtime. Run focused native tests and script lint.
- [ ] Run Gateway admission, identity and projection tests.
- [ ] Separate source conformance from checks, served-source uptake and live/grant evidence. Cite this page in the review.

## Changelog

Lane 26 adds a row after every merged port. Lane 17 wrote v0 in #2268.

| Source, newest first | Lesson |
| --- | --- |
| #2273 instance create | Op 6639 took 957 s for 4.6 s of SSH work: keep steps few. PHP encodes an empty map as `[]`: encode empty input maps as objects. |
| PLAN F9 | The legacy Gateway sequence is the spec: one legacy phase per script-file step, legacy edge cases carried over, legacy feature tests as parity tests. |
| Lane 5 | e459 expands `forEach` before earlier outputs exist. Fan out over `inputs`, or over a child workflow's inputs. |
| PLAN F8 | Every kind declares a measured p95, a run budget passed as the Swamp run timeout, and a per-kind `queueTimeout`; one step per action group, not per command. |
| v1 manual and reference check | Swamp has no step retry key. `command/shell` takes `workingDir` and `timeout`. Copy placement from `firewall.inspect`; `node.bootstrap_probe` sets both selectors. |
| #2266 instance create | Refuse a precondition such as `instance.branch_in_use` before the first write, inside the action script since #2273. Accept documented token formats; GitHub stateless tokens contain dots. |
| #2266 instance create | Op 6636 had no readable error: later steps read skipped outputs, and the failed step left no stderr. Guard on prior failure, read no skipped outputs, and use the F1 output. |
| Conformance ledger, 384 findings to 2026-11-01 | Placement, shared model names, embedded scripts, many-action behaviors, Gateway callbacks and vault lists are the most frequent defects. |
