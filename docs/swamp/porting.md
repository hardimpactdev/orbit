---
title: "Port a kind to Swamp"
description: "Steps, template, native mapping and anti-patterns for porting a legacy Orbit command to a Swamp workflow."
---

# Port a kind to Swamp

Start every kind PR from this page. Review every kind PR against it and the [binding shape guide](https://github.com/hardimpactdev/orbit/blob/main/swamp/docs/swamp-shape.md). Ship the Gateway admission, native workflow and old executor deletion together. Agents load it through the [swamp-port skill](https://github.com/hardimpactdev/orbit/blob/main/.claude/skills/swamp-port/SKILL.md). Lane 26 owns this page and adds a lesson after every merged port; the per-PR detail is in [Swamp port retros](/swamp/porting-retros). Every kind is ported inside a dev topology and its PR carries the receipt ([the loop](#port-inside-the-topology-f13)).

## Port inside the topology (F13)

Porting happens in a production-equivalent dev topology, never against production.

1. **Claim** the kind from `port-queue.md` in `~/swamp-execution` on beast; `port-queue-B.md` and `port-queue-C.md` feed it. Five topology slots exist, so at most five port lanes run at once.
2. **Read the legacy code and tests** at `6291bb036` (F9), and write the parity tests first.
3. **Start the topology** with `bin/swamp-proof up --topology <name>`. Never port against production serve or a 209 login.
4. **Port** in your beast worktree with the [translation steps](#translate-a-legacy-command) and the template.
5. **Run** the whole path in the topology as often as you need: request, admission, Swamp, Nodes and projection. Read full stderr and step timings.
6. **Write the receipt** with the final run (`bin/swamp-proof receipt`) once parity passes within budget (F8). The PR carries it. Then run `bin/swamp-proof down`.
7. **Review and merge.** Lane 26 reviews and the sub-orchestrator merges. Outage fixes and early proof PRs skip lane 26 until after merge. A live check confirms the load.

A topology is built from Node roles (F14). Its Gateway VM carries the `gateway` and `swamp` roles, with pinned official Swamp, a local datastore and vault. Its Nodes carry what the kind needs: `app-dev`, `app-prod`, `app-prod-2`, optional `+ingress`, `router` or `database`, joined by WireGuard like the fleet.

Topologies are versioned in `swamp/proof/topologies/`. A topology fault fixes the topology in the same PR and bumps its version (F12). Several kinds may share one run, and every receipt names the topology version. A topology dies after 60 minutes without a run, 8 hours at most; a one-shot proof run dies 45 minutes after creation. Known differences from production: test repositories instead of GitHub App installs, the Orbit CA instead of public certificates, and no production data or scale.

A change to a Swamp workflow, behavior or script that a kind runs needs a receipt, even titled "retire" or "remove" (#2257, #2259). So does a Gateway change to a kind's admission, intent, inputs, vault pushes, minting, signing or projection; three of four create bugs were Gateway-only (#2266, #2273, #2304). Other Gateway changes need failing-without tests, docs and lane 26 review. Only deletion, docs and test PRs fix forward without a receipt, after a deletion-speed review of tests and docs. An outage fix skips the receipt only if its PR body says "merges without a topology receipt" and names the live check that follows the load (#2332).

### Translate a legacy command

Use these steps in step 4 of the loop.
1. **Start from the legacy sequence (F9).** The legacy Gateway action and its tests are the spec. Keep every edge case, even values the Gateway rewrites first (#2304).
2. **One legacy phase, one step.** Each legacy phase becomes one script-file step on one Node. A phase-order change needs a [documented reason](/swamp/porting-retros#documented-f9-deviations) in the PR body.
3. **Map each phase to Swamp.** Use the [mapping table](#legacy-pattern-to-native-feature) and prefer an official model over a script. The PR names what you checked.
4. **Write the intent.** Every non-secret field the steps need goes under `intent`, frozen at admission. Secrets become `{vault, key}` descriptors under `execution_secrets`.
5. **Refuse first, then print the result.** The script checks preconditions before its first write. It prints one public JSON object and keeps a redacted stderr tail.
6. **Wire the Gateway.** Admission, intent, execution copies, grants and the projector; see [Fill the Gateway declaration](#fill-the-gateway-declaration).
7. **Delete the old path in the same PR.** Remove the executor and everything only it used. An F9 rebuild deletes the old behaviors and tests it replaces (F15).

## Fill the workflow

Replace every `REPLACE_*` token, generate a new workflow UUID, and define the family's public intent schema. This example runs an action group on 209. For an effect on another Node, replace `command/shell` with official `@swamp/ssh` from 209, using the approved identity file and stored full host keys; see [Node execution](/reference/swamp-command-steps#node-execution-through-official-ssh). Use one selector in the effective placement: `target` only, and no `labels` at any inherited scope.

Copy shape from the smallest clean reference that fits. [`firewall.inspect`](https://github.com/hardimpactdev/orbit/blob/main/swamp/workflows/workflow-operation-firewall-inspect.yaml) is one pinned SSH read and a typed result. [`schedule.create`](https://github.com/hardimpactdev/orbit/blob/main/swamp/workflows/workflow-operation-schedule-create.yaml) uses `@swamp/cfgmgmt` with `forEach`. [`instance.create`](https://github.com/hardimpactdev/orbit/blob/main/swamp/workflows/workflow-operation-instance-create.yaml) is the multi-step reference for shipped scripts, guards and a result step; do not copy its step count. The guide's Template section still names `node.bootstrap_probe`, but do not copy its placement; it sets both selectors.

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
      properties: {start_tag: {type: string, minLength: 1}, public_value: {type: string}}
      required: [start_tag, public_value]
      additionalProperties: false
    execution_secrets:
      type: object
      properties:
        credential: {type: object, required: [vault, key], additionalProperties: false, properties: {vault: {type: string, enum: [orbit-control-inputs]}, key: {type: string, pattern: '^REPLACE_KEY_PATTERN$'}}}
      required: [credential]
      additionalProperties: false
  required: [operation_id, node_id, kind, intent, execution_secrets]
  additionalProperties: false
jobs:
  - name: execute
    steps:
      - name: assets
        target: orbit-node-209
        task: {type: behavior, behaviorType: '@nckrtl/REPLACE_KIND-assets', behaviorName: '${{ "assets-" + run.id }}', methodName: read}
      - name: action
        target: orbit-node-209
        dependsOn: [{step: assets, condition: {type: succeeded}}]
        task:
          type: behavior
          behaviorType: command/shell
          behaviorName: '${{ "action-" + run.id }}'
          methodName: execute
          inputs:
            ignoreExitCode: true
            run: timeout REPLACE_PHASE_SECONDS REPLACE_F1_RUN
            env:
              ORBIT_OPERATION_ID: '${{ string(inputs.operation_id) }}'
              ORBIT_START_TAG: '${{ inputs.intent.start_tag }}'
              ORBIT_PUBLIC_VALUE: '${{ inputs.intent.public_value }}'
              ORBIT_CREDENTIAL: '${{ vault.get(inputs.execution_secrets.credential.vault, inputs.execution_secrets.credential.key) }}'
      - name: accepted
        target: orbit-node-209
        dependsOn: [{step: action, condition: {type: succeeded}}]
        task: {type: assert, expr: 'steps.action.outputs.exitCode == 0', severity: high, message: REPLACE_KIND did not complete.}
```

`assets.read` is a family metadata method: resolve shipped files with `ctx.extensionFile`, record public paths with `ctx.writeResource`, and return their paths, plus the script body for `@swamp/ssh`. List scripts and imports under manifest-relative `additionalFiles`. Keep this method and the command on the same worker; never assume a checkout exists there. TypeScript shapes inputs and outputs; operational code lives in script files.

F1 is pending; lane 16 owns it. When it lands, shipped scripts run through its shared wrapper, and its shared projector records `failed_step` and the last 4 KB of redacted stderr as `error.detail` for any failed step. Until then, `REPLACE_F1_RUN` plainly invokes the shipped script. Add no family wrapper. Private stdout never goes into native history. F1 failure output is stderr only: redact the whole stderr, keep the last 4 KiB, re-redact the tail's start for split matches, and drop lines that still hold a partial secret prefix. Scripts catch parser and HTTP errors and print safe text, because stderr becomes public detail (#2271).

On official e459 a `command/shell` step that exits nonzero keeps no outputs, so its stdout and `error_code` are lost. The action therefore sets `ignoreExitCode: true` and is the `terminal_result`; the `accepted` assert on its `exitCode` then fails the run. A kind with several effect steps instead adds a `result` step on `condition: always` that maps the first failed step to an `error_code`, as `instance.create` does.

One script file owns one action group, such as all git commands for a checkout. It runs as a placed `command/shell` step on 209, or through `@swamp/ssh` `exec` with the body in argv for another Node; use `script` only when the script reads no input or secret. It is idempotent for the admitted `operation_id` and start tag. It refuses with a public `error_code` and a nonzero exit. It passes secrets to tools on stdin or in protected config files, never argv or tracing. Use native guards, resume, concurrency and method locks; Swamp has no step retry setting. Named apt and UFW locks are allowed.

## Fill the Gateway declaration

Add these fields to the family's existing registration and return the full input envelope from its projector.

```php
'REPLACE_KIND' => [ // in the family's direct-operations.php; keep its admission, workflow and projector
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'execute', 'step' => 'action'],
    // only when the run reads a Gateway-owned secret:
    // 'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^REPLACE_KEY_PATTERN$']],
],
// Projector input(): ['intent' => $fullPublicIntent, 'execution_secrets' => $namesOnlyDescriptors]
```

Pass `lockKey: OperationStore::lockKey(REPLACE_TARGET_TYPE, $id)` to `StartOperationAction::execute` when the kind changes a shared target; it is an Operation column, not a declaration key. Add any `execution_copies` vault to `swamp/config/gateway-workflow-grants.json`; one `*` grant already gives the Gateway `run` and `read` on every workflow. Key patterns must pass Swamp's regex-safety check: expand optional groups into a flat alternation.

| Done | Gateway checklist |
| --- | --- |
| [ ] | Validate and record the complete immutable intent at admission. Use shared `StartSwampRun`; its builder sets Operation, kind and Node identity. Remove intent, private-material and status callbacks. |
| [ ] | Push a changed Gateway-owned secret with `SwampCommandStep::executionCopy` and pass only `{vault, key}` descriptors; VM-created Pi credentials stay on the VM. Declare one fixed terminal selector; `projectTerminal` reads history once and calls the family projector. |
| [ ] | Projection keeps an exact native terminal that met its budget, even after the Gateway expiry, and drops the `timed_out` mark. One history read; no timer, retry or second reader. |

## Set the time budget

The execution plan (`PLAN.md` on beast, F8) requires a budget for every kind. A port PR without one fails review.

A phase script may hold one fixed literal sleep shorter than a literal timeout, as the DNS grace does, or poll external state, such as a VM started, at a fixed interval under coreutils `timeout <phase budget>`. A bad state exits nonzero with a readable F1 error. The PR states the interval and budget. Never poll to retry an action, to wait on Gateway or Orbit state, or outside a phase script. The shape gate accepts it ([guide rule 6](https://github.com/hardimpactdev/orbit/blob/main/swamp/docs/swamp-shape.md), #2325): a literal timeout, a shorter fixed interval, an external GET that fails on HTTP errors, and no retried requests.

| Item | Rule |
| --- | --- |
| Measured p95 | Take step `durationMs` and run start and end times from native history of the kind's runs. Use live runs when they exist, otherwise native harness runs. State the sample size. |
| Run budget | Every kind expires; a measured p95 with headroom wins. Otherwise 30 minutes until the first success, then 1.5× the longest success. `instance.create` has 60 s (PLAN: under 10 s; policy, not proof). |
| Enforcement | Serve `max-run-duration` caps runs at the longest legitimate kind; the extensions lane sets it. At the kind budget the Gateway marks `operation.timed_out` and cancels (decision 4). Add no family timer or poller. |
| No-run rows | Expire after 600 s but keep the lock until the exact native lookup by Operation id proves no run (L17-2300-OD1). Timeouts and transport errors prove nothing. |
| Phase timeout | Each phase command starts with a literal `timeout <phase budget>`. On exit 124 the shared F1 projector renders `<step> timed out after <N>s`, with no wrapper or epilogue (OD2; pending, lane 16). A remote read may use per-host `timeoutSec` or `defaultTimeoutSec` (#2334). |
| `queueTimeout` | Explicit on every step (guide rule 6), never the serve's 600 s default. A workflow value reaches every step; a job or step may override it. Keep it below the run budget. |

## Keep steps few

Step count is the dominant cost of a run: op 6639 took 957 s for 4.6 s of SSH work. Until the extensions lane reports the cause, one script per Node per phase does the reads, precondition checks, writes and result JSON. Never wrap an SSH call in TypeScript envelope and decode steps; #2261 reached 66 steps that way. Use a typed step only for a result the script cannot print, and a separate check only for another Node's read. A typed result step returns `{stdout}` itself, with no `printf` step after it. Resolve assets once, outside `forEach`.

Count the native steps of a typical run against the legacy calls, where a call is one host command or API request within a phase. More than about two steps per legacy call needs a written reason in the PR, and each extra step names the Node change or guard it serves. Do not count on resume as a split reason: pinned e459 loses inline SSH `globalArgs` when it resumes a failed run. Guide rule 3 says "one action group is one step", following PLAN F9 and "How a kind converts" item 2.

## Test layout

Put each test where its project's CI runs it.

| Where | What it proves |
| --- | --- |
| `apps/gateway/tests/Feature/<Family>/*NativeTest.php`, plus `Operations/GatewayWorkflowGrantCheckTest.php` | Admission, refusal, lock key, execution copy, the input envelope, projection of success and failure, and grant coverage for the workflow and its vault keys. |
| `swamp/extensions/behaviors/operations/<family>/*_test.ts`, and `swamp/tests` script tests | Typed behaviors with Deno; each shipped script directly, with shellcheck or ruff. Real-Docker tests clean up with `docker rm --force --volumes` (#2358). |
| `swamp/tests/<family>_native_test.py` or `operations/<family>/*_native_test.py` | The workflow on the shared native harness: success, refusal before any write, first-failure skip chain and public result. A secret-handling test sets `self.secrets` so history is scanned. |
| Parity tests from the legacy feature tests, and a generator drift test when a generator writes the workflow | Each legacy case gives the same public result natively. Committed YAML equals readable generator output. |
| `swamp/proof/<kind>.json` (`swamp/proof/README.md`, or the v0 design on beast until it lands) | The proof file: `prepare` and `request` CLI calls, partial `envelope`, budgets, F9 phases, parity tests, `queueTimeout` and topology. No secret appears in it or in argv. |

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
| Retry loop, `attempt < N`, or sleep and poll until ready | Retry: an idempotent step that fails when not ready, then a new admission (decision 7) or operator resume. External state: a bounded poll in the phase script, [as above](#set-the-time-budget) | [Guard](https://swamp-club.com/manual/reference/workflows#guard), [Retry failed steps](https://swamp-club.com/manual/reference/workflows#retry-failed-steps) |
| Wait for a free worker, or for a person | Per-kind `queueTimeout` (F8; serve default 600 s); `manual_approval` task | [queueTimeout](https://swamp-club.com/manual/reference/workflows#queuetimeout), [Manual approval](https://swamp-club.com/manual/reference/workflows#manual-approval-manual_approval) |
| Lock file, `flock`, family busy hold | Per-behavior method lock on one shared `behaviorName`, `concurrency`, or the generic admission `lock_key`. Named apt and UFW tool locks stay | [Method locking](https://swamp-club.com/manual/reference/behavior-definitions#per-behavior-method-locking), [Concurrency](https://swamp-club.com/manual/reference/workflows#concurrency) |
| "Already done" receipt or marker file | `guard` on public state or prior outputs | [Guard](https://swamp-club.com/manual/reference/workflows#guard) |
| Best-effort cleanup, compensation, or a failure scope | A step on `condition: failed`, `always` or `completed`, or `allowFailure`. Compensation reruns the forward child with inverted intent (#2262). Keep the failure scope of the legacy code at `6291bb036`, not of an earlier Swamp port | [Trigger conditions](https://swamp-club.com/manual/reference/workflows#trigger-conditions), [allowFailure](https://swamp-club.com/manual/reference/workflows#allowfailure) |
| Child Operation, or a loop over items in one program | `type: workflow` child; `forEach` over `inputs` with a `behaviorName` unique per run and item | [forEach](https://swamp-club.com/manual/reference/workflows#foreach-1), [Child workflows](https://swamp-club.com/manual/reference/workflows#workflow-workflow) |
| One program for many Nodes, or a call to another Node's agent | Placed steps in one run, each through `@swamp/ssh` from 209 | [Placement](https://swamp-club.com/manual/reference/workflows#target-2), [`@swamp/ssh`](https://github.com/swamp-club/swamp-extensions/tree/main/ssh) |
| Precondition refusal after a write | Check first in the same script and exit with an `error_code`; an `assert` step only for another Node's read | [Assert](https://swamp-club.com/manual/reference/workflows#assert-assert) |
| Custom timeout or process supervisor | Coreutils `timeout <phase budget>` in each phase script (F8); `command/shell` `timeout` and `@swamp/ssh` `timeoutSec` as outer bounds; native cancel | [Behavior definitions](https://swamp-club.com/manual/reference/behavior-definitions) |
| A secret or input into a script on another Node | `@swamp/ssh` `exec` with `argv: [timeout, N, python3, -I, -c, <asset body>, <public args>]` and one whole `vault.get` on `stdin`. `env` uses `SendEnv`, which needs `AcceptEnv` and sudo `env_keep`; assume neither | [`@swamp/ssh`](https://github.com/swamp-club/swamp-extensions/tree/main/ssh) |
| Script in a TypeScript string | Script file in manifest `additionalFiles`, resolved with `context.extensionFile`, or YAML `run: \|` | [additionalFiles](https://swamp-club.com/manual/reference/extensions/manifest#additionalfiles) |
| File, package, unit or firewall writes over SSH | `@swamp/cfgmgmt` `file`, `systemd`, `firewall`, `exec`, behind a host-key pin preflight as [`firewall.direct`](https://github.com/hardimpactdev/orbit/blob/main/swamp/workflows/workflow-operation-firewall-direct.yaml) does; else `@swamp/ssh` | [`@swamp/cfgmgmt`](https://github.com/swamp-club/swamp-extensions/tree/main/cfgmgmt) |
| Progress, result, admission or cancel traffic between steps and the Gateway | Step outputs and terminal projection; native schedules through `SwampSchedulePublisher`; native cancel, with steps honouring `context.signal` | [Schedules](https://swamp-club.com/manual/reference/workflows#triggerschedule), [Cancellation](https://swamp-club.com/manual/reference/workflows#execution-cancellation) |

## Anti-patterns

Each of these is a review BLOCK. Most come from the [conformance ledger](/swamp/porting-retros#lesson-history).

| Anti-pattern | Why it fails | Do this instead |
| --- | --- | --- |
| `target` and `labels` in one effective placement, or a step on the effect Node | Selectors can disagree, the top audit finding; lane 21 moves per-Node worker steps to 209 | `target: orbit-node-209`, then `@swamp/ssh` |
| `forEach` items or concurrent runs sharing one `behaviorName` | The model lock serializes them and later items time out | `behaviorName` unique per run and item |
| A many-action TypeScript behavior, an agent action port, a spawn wrapper, one `exec` per listed command, a window `assert` before each phase, or a rewritten shipped script | Swamp cannot show, guard or resume the actions; the code that runs is not the linted file | One step per action group; public input as argv after the body |
| Swamp growth without a reason, or a new TypeScript behavior that native Swamp, an official extension or a shared helper could replace | `swamp/` grew +226,316 lines against Orbit −162,866; the migration total of +63,450 must turn negative (F15) | Use native Swamp, an official extension, a shared helper or a script file. A reason for growth says why none of them can do it. |
| `forEach.in` reading outputs of a step in the same job | e459 expands it before the outputs exist | Read `inputs`, literals or an earlier job's outputs, as [`database-destroy-actions`](https://github.com/hardimpactdev/orbit/blob/main/swamp/workflows/workflow-database-destroy-actions.yaml) does, or pass the list to a child, as [`database-clone-mysql`](https://github.com/hardimpactdev/orbit/blob/main/swamp/workflows/workflow-database-clone-mysql.yaml) does |
| Reading failed or skipped outputs, all-pairs `always` edges, the whole `${{ steps }}` map in a result step, or an echo step after it | CEL fails with `No such key`; #2261 and #2253 grew dozens of edges | Read `"x" in steps && steps.x.status` for the steps you need; chain jobs on `succeeded` |
| A step input that grows with history or fleet size: `data.query`, or `results: ${{ steps }}` over a fleet fan-out (#2334) | One dispatch frame can pass 64 MiB, and the worker loops (B7, #2310) | Read one stable record with `data.latest`. For per-target results, the plan emits target-ordered step names and the step takes compact arrays: `names.map(k, k in steps ? steps[k].status : "skipped")` (#2326 review) |
| A kind without a measured p95 and budget, a flat or borrowed budget (L17-PR-2324-01), or a non-default Swamp setting without a PLAN row | Runs wait for minutes, as op 6639 did; deviations hide from audit | [Set the time budget](#set-the-time-budget); record reason and removal condition in PLAN |
| A workflow that reads a vault without `vaults:` | Validate passes, the run fails (op 6413) | Declare every vault it reads; `vaults: []` when none is a convention |
| A secret in `run:`, argv, inputs or `set -x`; a CA or GitHub App key in a vault; a secret returned from a Node | History and `ps` expose it; e459 cannot mark `@swamp/ssh` output sensitive; root-of-trust keys stay in the Gateway | `env` from `vault.get`, then stdin or a 0600 file; the mint and sign primitives; generate Gateway-owned secrets at admission; Node-held keys stay on the Node (decision 3) |
| A failure reporter, Gateway re-read timer, binding loop or retry | Duplicates Swamp and stalls Operations | Native status and one history read per terminal delivery |
| Redesigning the legacy sequence without a written reason | The legacy Gateway ran each kind reliably; a redesign loses edge cases | Keep the legacy phases, or give the reason in the PR |

## PR and review checklist

Continue ports in the approved shape while live admission proof is pending. Use this checklist for every kind PR and its review.

- [ ] Follow the [translation steps](#translate-a-legacy-command) and avoid every [anti-pattern](#anti-patterns).
- [ ] Keep the legacy phase order, or give a reason for each change. State the expanded step count of a typical run and the steps per legacy call.
- [ ] Name the core feature and official extension checked. Use defaults, and record each deviation's measured reason and removal condition in PLAN.
- [ ] Delete the old executor and everything only it used. An F9 rebuild deletes the old behaviors and tests it replaces. Remove baseline rows in the last commit, after the last rebase.
- [ ] **Growth (F15):** list `swamp/` growth per part (behaviors, workflows, scripts, tests and fixtures) next to the Orbit net. Give each growth a [reason](#anti-patterns).
- [ ] Prove behavior with topology receipts and parity tests, not large mocked fixtures. Delete duplicate or mock-heavy tests. Keep the diff under GitHub's 20,000-line limit.
- [ ] State the measured p95, its sample, the run budget and `queueTimeout`. A behavior-changing kind carries its [topology receipt](#port-inside-the-topology-f13). Without them the review fails.
- [ ] Every workflow, children included, sets an explicit `queueTimeout`.

Check the completed port and report what the evidence proves.

- [ ] Cover success and refusal. Check redacted failure detail, cancellation and interrupted projection.
- [ ] Validate completed YAML with the pinned native runtime. Run focused native tests, script lint, and Gateway admission, identity and projection tests.
- [ ] Separate source conformance from checks, served-source uptake and live evidence. Check guard-error semantics before alleging a silent skip (#2320). Cite this page.

## Changelog

Each lesson replaces the rule it changes above. The per-PR history and the earlier changelog rows are in [Swamp port retros](/swamp/porting-retros#lesson-history).
