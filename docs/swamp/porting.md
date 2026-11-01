---
title: "Port a kind to Swamp"
description: "Steps, template, native mapping and anti-patterns for porting a legacy Orbit command to a Swamp workflow."
---

# Port a kind to Swamp

Start every kind PR from this page. Review every kind PR against it and the [binding shape guide](https://github.com/hardimpactdev/orbit/blob/main/swamp/docs/swamp-shape.md). Ship the Gateway admission, native workflow and old executor deletion together. Agents load it through the [swamp-port skill](https://github.com/hardimpactdev/orbit/blob/main/.claude/skills/swamp-port/SKILL.md). Lane 26 owns this page and adds a lesson after every merged port; the per-PR detail is in [Swamp port retros](/swamp/porting-retros).

## Translate a legacy command

Work through these steps in order before you fill the template.

1. **Start from the legacy sequence (F9).** The pre-Swamp Gateway action is the spec. Read it at the migration baseline with `git show 6291bb036:<path>`. Note each phase and the Node it changes.
2. **One legacy phase, one step.** Each legacy phase becomes one action group: one script-file step on one Node. It keeps the phase's legacy edge cases.
3. **Map each phase to Swamp.** Use the [mapping table](#legacy-pattern-to-native-feature). Prefer an official model (`@swamp/ssh`, `@swamp/cfgmgmt`) over a script. Record what you checked for the PR body.
4. **Write the intent.** Every non-secret field the steps need goes under `intent`, frozen at admission. Every secret becomes a `{vault, key}` descriptor under `execution_secrets`.
5. **Refuse first, then print the result.** The action script checks preconditions before its first write. It prints one public JSON object. Then set the [time budget](#set-the-time-budget).
6. **Wire the Gateway.** Admission, intent, execution copies, grants and the projector; see [Fill the Gateway declaration](#fill-the-gateway-declaration).
7. **Delete the old path in the same PR.** Remove the executor, its tests, scripts and callbacks, and its `swamp-shape-baseline.json` rows.

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

F1 is pending; lane 16 owns it. When it lands, shipped scripts run through its shared wrapper, and its shared projector records `failed_step` and the last 4 KB of redacted stderr as `error.detail` for any failed step, including SSH, typed and `assert` steps. Until then, `REPLACE_F1_RUN` is a plain invocation of the shipped script from the asset paths. Add no family wrapper. Never print credentials, private keys or unredacted diagnostics. Scripts catch parser and HTTP errors and print safe text, because stderr becomes public detail (#2271).

On official e459 a `command/shell` step that exits nonzero keeps no outputs, so its stdout and `error_code` are lost. The action therefore sets `ignoreExitCode: true` and is the `terminal_result`; the `accepted` assert on its `exitCode` then fails the run. A kind with several effect steps instead adds a `result` step on `condition: always` that maps the first failed step to an `error_code`, as `instance.create` does.

One script file owns one action group, such as all git commands for a checkout. It runs as a placed `command/shell` step on 209, or through `@swamp/ssh` `exec` with the body in argv for another Node; use `script` only when the script reads no input or secret. It is idempotent for the admitted `operation_id` and start tag. It refuses with a public `error_code` and a nonzero exit. It passes secrets to tools on stdin or in protected config files, never argv or tracing. Use native guards, resume, concurrency and method locks; Swamp has no step retry setting. Named apt and UFW locks are allowed.

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
| [ ] | Push a changed Gateway-owned secret with `SwampCommandStep::executionCopy` and pass only `{vault, key}` descriptors; VM-created Pi credentials stay on the VM. Declare one fixed terminal selector; `projectTerminal` reads history once and calls the family projector. |

## Set the time budget

The execution plan (`PLAN.md` on beast, F8) requires a budget for every kind. A port PR without one fails review.

| Item | Rule |
| --- | --- |
| Measured p95 | Take step `durationMs` and run start and end times from native history of the kind's runs. Use live runs when they exist, otherwise native harness runs. State the sample size. |
| Run budget | Set it from the measured p95 with headroom, not a guess. PLAN sets the `instance.create` policy: under 10 s, budget 60 s. That is a target, not measured proof. |
| Enforcement | Serve `max-run-duration` caps runs at the longest legitimate kind; the extensions lane sets it. At the kind budget the Gateway marks `operation.timed_out` and cancels (decision 4). Add no family timer or poller. |
| Phase timeout | Each phase script runs under coreutils `timeout <phase budget>`, inside `run:` or as the first `@swamp/ssh` `argv` items. |
| `queueTimeout` | Every step gets one, so it fails fast when no worker is free (guide rule 6). A workflow-level value is inherited by every step. Keep it below the run budget. |

## Prove the kind (F10)

A kind whose behavior changes merges only with a receipt from `bin/swamp-proof <kind>`. It creates two disposable task VMs on shark. The control VM runs pinned official Swamp and official extensions with a throwaway datastore and vault, and loads the PR branch's `swamp/`. The target VM plays the Node and is reached over `@swamp/ssh`, as 209 reaches Nodes. The command runs the kind with real intent inputs, runs the F9 parity tests, measures phase timings against F8, writes the receipt to the PR and destroys both VMs.

A proof that loads `swamp/` into production serve or logs in to 209 is not a receipt. Any change under `swamp/workflows/`, to a grant or to a declaration changes behavior, even when the PR title says "retire" (#2257, #2259). Only deletion, docs and test PRs fix forward. A PR that fixes a live user-facing outage may merge without a receipt if it says so; the extensions lane then proves it live right after the load.

Each kind adds `swamp/proof/<kind>.json` in fixture format v0 (`swamp/proof/README.md`; until lane 25 commits it, `designs/swamp-proof-fixture-v0.md` on beast). Copy `envelope` from a real Gateway admission and bind only environment values, such as `${proof.target.host_keys}`. List throwaway `secrets` for every vault key the run reads, never a real credential. Map each F9 `phases` entry to its native step and phase budget, and point `parity` at the ported legacy tests. `queue_timeout_seconds` equals the workflow's `queueTimeout`.

## Keep steps few

Step count is the dominant cost of a run: op 6639 took 957 s for 4.6 s of SSH work. Until the extensions lane reports the cause, one script per Node per phase does the reads, precondition checks, writes and result JSON. Never wrap an SSH call in TypeScript envelope and decode steps; #2261 reached 66 steps that way. Use a typed step only for a result the script cannot print, and a separate check only for another Node's read. A typed result step returns `{stdout}` itself, with no `printf` step after it. Resolve assets once, outside `forEach`.

Count the native steps of a typical run against the legacy calls. More than about two steps per legacy call needs a written reason in the PR, and each extra step names the Node change or guard it serves. Do not count on resume as a split reason: pinned e459 loses inline SSH `globalArgs` when it resumes a failed run. Guide rule 3 says "one action group is one step", following PLAN F9 and "How a kind converts" item 2.

## Fan out over computed data

Official e459 expands `forEach` before the outputs of steps in the same job exist, so a `forEach.in` that reads them fails with `No such key`. The manual does not say when `in` is evaluated. Read `inputs`, literals, or outputs of a step in an earlier job that this job `dependsOn`, as [`workflow-database-destroy-actions.yaml`](https://github.com/hardimpactdev/orbit/blob/main/swamp/workflows/workflow-database-destroy-actions.yaml) does. When that does not fit, pass the list as inputs to a `type: workflow` child whose `forEach` reads them; [`workflow-database-clone-mysql.yaml`](https://github.com/hardimpactdev/orbit/blob/main/swamp/workflows/workflow-database-clone-mysql.yaml) passes step output into child inputs.

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
| A generator drift test, when a generator writes the workflow | The committed YAML equals the generator output. Generate readable YAML, not JSON in YAML behind `deno-fmt-ignore`. |
| `swamp/proof/<kind>.json` | The F10 fixture: envelope, throwaway secrets, target setup, expected result, F9 phases with budgets, and the parity command. |

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
| Retry loop, `attempt < N`, or sleep and poll until ready | An idempotent check step that fails when not ready; keep tool-owned waits. Recovery is a new admission (decision 7) or operator resume, which never reopens the Operation | [Guard](https://swamp-club.com/manual/reference/workflows#guard), [Retry failed steps](https://swamp-club.com/manual/reference/workflows#retry-failed-steps) |
| Wait for a free worker, or for a person | Per-kind `queueTimeout` (F8; serve default 600 s); `manual_approval` task | [queueTimeout](https://swamp-club.com/manual/reference/workflows#queuetimeout), [Manual approval](https://swamp-club.com/manual/reference/workflows#manual-approval-manual_approval) |
| Lock file, `flock`, family busy hold | Per-behavior method lock on one shared `behaviorName`, `concurrency`, or the generic admission `lock_key`. Named apt and UFW tool locks stay | [Method locking](https://swamp-club.com/manual/reference/behavior-definitions#per-behavior-method-locking), [Concurrency](https://swamp-club.com/manual/reference/workflows#concurrency) |
| "Already done" receipt or marker file | `guard` on public state or prior outputs | [Guard](https://swamp-club.com/manual/reference/workflows#guard) |
| Best-effort cleanup, compensation, or carrying on after one item fails | A step on `condition: failed`, `always` or `completed`, or `allowFailure`. Compensation reruns the forward child with inverted intent (#2262). Keep legacy carry-on behaviour (#2281) | [Trigger conditions](https://swamp-club.com/manual/reference/workflows#trigger-conditions), [allowFailure](https://swamp-club.com/manual/reference/workflows#allowfailure) |
| Child Operation, or a loop over items in one program | `type: workflow` child; `forEach` over `inputs` with a `behaviorName` unique per run and item | [forEach](https://swamp-club.com/manual/reference/workflows#foreach-1), [Child workflows](https://swamp-club.com/manual/reference/workflows#workflow-workflow) |
| One program for many Nodes, or a call to another Node's agent | Placed steps in one run, each through `@swamp/ssh` from 209 | [Placement](https://swamp-club.com/manual/reference/workflows#target-2), [`@swamp/ssh`](https://github.com/swamp-club/swamp-extensions/tree/main/ssh) |
| Precondition refusal after a write | Check first in the same script and exit with an `error_code`; an `assert` step only for another Node's read | [Assert](https://swamp-club.com/manual/reference/workflows#assert-assert) |
| Custom timeout or process supervisor | Coreutils `timeout <phase budget>` in each phase script (F8); `command/shell` `timeout` and `@swamp/ssh` `timeoutSec` as outer bounds; native cancel | [Behavior definitions](https://swamp-club.com/manual/reference/behavior-definitions) |
| A secret or input into a script on another Node | `@swamp/ssh` `exec` with `argv: [timeout, N, python3, -I, -c, <asset body>, <public args>]` and one whole `vault.get` on `stdin`. `env` uses `SendEnv`, which needs `AcceptEnv` and sudo `env_keep`; assume neither | [`@swamp/ssh`](https://github.com/swamp-club/swamp-extensions/tree/main/ssh) |
| Script in a TypeScript string | Script file in manifest `additionalFiles`, resolved with `context.extensionFile`, or YAML `run: \|` | [additionalFiles](https://swamp-club.com/manual/reference/extensions/manifest#additionalfiles) |
| File, package, unit or firewall writes over SSH | `@swamp/cfgmgmt` `file`, `systemd`, `firewall`, `exec`, behind a host-key pin preflight as [`firewall.direct`](https://github.com/hardimpactdev/orbit/blob/main/swamp/workflows/workflow-operation-firewall-direct.yaml) does; else `@swamp/ssh` | [`@swamp/cfgmgmt`](https://github.com/swamp-club/swamp-extensions/tree/main/cfgmgmt) |
| Progress, result, admission or cancel traffic between steps and the Gateway | Step outputs and terminal projection; native schedules through `SwampSchedulePublisher`; native cancel, with steps honouring `context.signal` | [Schedules](https://swamp-club.com/manual/reference/workflows#triggerschedule), [Cancellation](https://swamp-club.com/manual/reference/workflows#execution-cancellation) |

## Anti-patterns

Each of these is a review BLOCK. Most come from the [conformance ledger](#changelog).

| Anti-pattern | Why it fails | Do this instead |
| --- | --- | --- |
| `target` and `labels` in one effective placement, or a step on the effect Node | Selectors can disagree, the top audit finding; lane 21 moves per-Node worker steps to 209 | `target: orbit-node-209`, then `@swamp/ssh` |
| `forEach` items or concurrent runs sharing one `behaviorName` | The model lock serializes them and later items time out | `behaviorName` unique per run and item |
| A many-action TypeScript behavior, an agent action port, a spawn wrapper, one `exec` per listed command, a window `assert` before each phase, or a rewritten shipped script | Swamp cannot show, guard or resume the actions; the code that runs is not the linted file | One step per action group; public input as argv after the body |
| `forEach.in` reading outputs of a step in the same job | e459 expands it before the outputs exist | Read an earlier job's outputs, or fan out in a child workflow |
| Reading failed or skipped outputs, all-pairs `always` edges, the whole `${{ steps }}` map in a result step, or an echo step after it | CEL fails with `No such key`; #2261 and #2253 grew dozens of edges | Read `"x" in steps && steps.x.status` for the steps you need; chain jobs on `succeeded` |
| Strict format checks on a third-party token | GitHub stateless tokens contain dots; the check refused a valid mint | Check only documented properties: length, scope, expiry |
| A kind without a measured p95 and budget, or a non-default Swamp setting without a PLAN row | Runs wait for minutes, as op 6639 did; deviations hide from audit | [Set the time budget](#set-the-time-budget); record reason and removal condition in PLAN |
| A workflow that reads a vault without `vaults:` | Validate passes, the run fails (op 6413) | Declare every vault it reads; `vaults: []` when none is a convention |
| A secret in `run:`, argv, inputs or `set -x`; a GitHub App or CA key in a vault | History and `ps` expose it; root-of-trust keys stay in the Gateway | `env` from `vault.get`, then stdin or a 0600 file; the mint and sign primitives |
| A secret returned from a Node to the Gateway | e459 cannot mark `@swamp/ssh` output sensitive; legacy generates `APP_KEY` in the Gateway, and Nick kept it there | Generate Gateway-owned secrets at admission; Node-held keys stay on the Node (decision 3) |
| A failure reporter, Gateway re-read timer, binding loop or retry | Duplicates Swamp and stalls Operations | Native status and one history read per terminal delivery |
| Redesigning the legacy sequence without a written reason | The legacy Gateway ran each kind reliably; a redesign loses edge cases | Keep the legacy phases, or give the reason in the PR |

## PR and review checklist

Continue ports in the approved shape while live admission proof is pending. Use this checklist for every kind PR and its review.

- [ ] Follow the [translation steps](#translate-a-legacy-command) and avoid every [anti-pattern](#anti-patterns).
- [ ] Keep the legacy phase order, or give a reason for each change. State the expanded step count of a typical run and the steps per legacy call.
- [ ] Name the core feature and official extension checked. Use defaults, and record each deviation's measured reason and removal condition in PLAN.
- [ ] Delete the old executor and everything only it used. Remove baseline rows in the last commit, after the last rebase.
- [ ] Report Orbit and Swamp net lines separately. Keep the diff under GitHub's 20,000-line limit so the audit can read it.
- [ ] State the measured p95, its sample, the run budget and `queueTimeout`. A behavior-changing kind attaches its [F10 receipt](#prove-the-kind-f10). Without them the review fails.

Check the completed port and report what the evidence proves.

- [ ] Cover success and refusal. Check redacted failure detail, cancellation and interrupted projection.
- [ ] Validate completed YAML with the pinned native runtime. Run focused native tests and script lint.
- [ ] Run Gateway admission, identity and projection tests.
- [ ] Separate source conformance from checks, served-source uptake and live/grant evidence. Cite this page in the review.

## Changelog

Lane 26 adds a row after every merged port. Lane 17 wrote v0 in #2268.

| Source, newest first | Lesson |
| --- | --- |
| Retro #2248 | Legacy polled UpCloud until the VM started; a one-shot observe fails on real runs. Waiting for provider state is an open question with sub-orchestrator A. |
| #2258 | Swamp rejects a grant pattern with a quantified group that holds `+` or `*`, such as `(?:-setup-[0-9]+)?`, at grant time. Expand to a flat alternation and run `bin/check-grant-patterns.ts`. |
| #2259 | Name each `forEach` model from `self._index`, give `@swamp/ssh` `globalArgs.name` the same expression, and stay within 64 characters. |
| #2252, #2264, #2270, #2286, #2287 | Deletion PRs check imports, loaders and manifests first, remove baseline rows or lower a count, and keep methods live workflows still call. |
| #2273 instance create | Op 6639 took 957 s for 4.6 s of SSH work: keep steps few. PHP encodes an empty map as `[]`: encode empty input maps as objects. |
| PLAN F10 (A, 2026-11-01) | `bin/swamp-proof` runs `swamp/proof/<kind>.json` on two shark task VMs with throwaway Swamp state; no production serve, no 209 login. Live-outage fixes get a live proof after merge. |
| PLAN F9 | The legacy Gateway sequence is the spec: one legacy phase per script-file step, edge cases carried over, legacy feature tests as parity tests. |
| PLAN F8 (rewritten) | Measured p95 and budget per kind; serve `max-run-duration` ceiling, `queueTimeout` on every step, coreutils `timeout` per phase script, Gateway `operation.timed_out` at the kind budget. |
| v1 check and conformance ledger | Swamp has no step retry key. Copy placement from `firewall.inspect`. Placement, model names, embedded scripts, many-action behaviors and callbacks are the top defects. |
| Orchestrator, lane 27 diagrams, #2281 | Count native steps against legacy calls; over about two per call needs a reason. #2281 ran about 22 steps for about 6 calls, rewrote a script's source, and stopped after one failed item. |
| Retro #2237, #2248, #2249, #2251, #2253, #2261 | Asset, exec and decode triplets, all-pairs `always` edges, per-phase window asserts and one `exec` per command gave up to 15 steps per legacy call. |
| Lane 9 create consult | A Node never returns a secret to the Gateway. Generate Gateway-owned secrets at admission; a step returns only public output, such as `.env.example` defaults. |
| #2266, lane 5, v2 review | Refuse before the first write and accept documented token formats. A nonzero `command/shell` exit drops outputs; use `ignoreExitCode` plus an `assert`. `forEach` fails over same-job outputs. |
