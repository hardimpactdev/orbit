---
title: "Port a kind to Swamp"
description: "Steps, template, native mapping and anti-patterns for porting a legacy Orbit command to a Swamp workflow."
---

# Port a kind to Swamp

Start every kind PR from this page. Review every kind PR against it and the [binding shape guide](https://github.com/hardimpactdev/orbit/blob/main/swamp/docs/swamp-shape.md). Ship the Gateway admission, native workflow and old executor deletion together. Agents load it through the [swamp-port skill](https://github.com/hardimpactdev/orbit/blob/main/.claude/skills/swamp-port/SKILL.md). Lane 26 owns this page and adds a lesson after every merged port.

## Translate a legacy command

Work through these steps in order before you fill the template.

1. **Find the legacy path.** List its Gateway action, `direct-operations.php` entry, agent actions and tests. Note each effect and the Node it changes.
2. **Group the effects.** An action group is a set of commands with one Node, one resume point and one guard. All git commands of a checkout are one group. Each group becomes one step.
3. **Map each group to Swamp.** Use the [mapping table](#legacy-pattern-to-native-feature). Prefer an official model (`@swamp/ssh`, `@swamp/cfgmgmt`) over a script. Record what you checked for the PR body.
4. **Write the intent.** Every non-secret field the steps need goes under `intent`, frozen at admission. Every secret becomes a `{vault, key}` descriptor under `execution_secrets`.
5. **Order refusals before effects.** A read step and a typed decision step feed a native `assert` step. It refuses before the first write, as the #2266 branch check does.
6. **Write the result.** One result step prints one public JSON object. Declare it as `terminal_result`. Name the failed step and a public `error_code` on failure.
7. **Wire the Gateway.** Admission, intent, execution copies, grants and the projector; see [Fill the Gateway declaration](#fill-the-gateway-declaration).
8. **Delete the old path in the same PR.** Remove the executor, its tests, scripts and callbacks, and its `swamp-shape-baseline.json` rows.

## Fill the workflow

Replace every `REPLACE_*` token, generate a new workflow UUID, and define the family's public intent schema. This example runs an action group on 209. For an effect on another Node, replace `command/shell` with official `@swamp/ssh` from 209, using the approved identity file and stored full host keys; see [Node execution](/reference/swamp-command-steps#node-execution-through-official-ssh). Set one placement selector per scope.

Copy shape from the smallest clean reference that fits. [`firewall.inspect`](https://github.com/hardimpactdev/orbit/blob/main/swamp/workflows/workflow-operation-firewall-inspect.yaml) is one pinned SSH read and a typed result. [`schedule.create`](https://github.com/hardimpactdev/orbit/blob/main/swamp/workflows/workflow-operation-schedule-create.yaml) uses `@swamp/cfgmgmt` with `forEach`. [`instance.create`](https://github.com/hardimpactdev/orbit/blob/main/swamp/workflows/workflow-operation-instance-create.yaml) shows shipped scripts, guards and an `assert` refusal. Do not copy placement from `node.bootstrap_probe`; it still sets both selectors.

```yaml
id: REPLACE_WORKFLOW_UUID
name: operation-REPLACE_KIND
description: REPLACE_ACTION_GROUP
vaults: [orbit-control-inputs]
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

`REPLACE_F1_RUN` is the complete invocation of lane16's shared F1 error wrapper around the shipped action script. Fill it from the published wrapper's interface and the asset output paths; it is not a new command or wrapper API. F1 is assigned in PLAN and its concrete interface is pending publication. Use that shared implementation when available; do not add a family wrapper. Ports can proceed while that integration is prepared.

Each failed step exposes its step name and the last 4 KB of stderr, redacted, as public step output. The shared F1 projector records `failed_step` and `error.detail` for `operation:show`. Cover failure before action stdout exists, including asset and SSH failures. Never print credentials, private keys or unredacted diagnostics.

One script file owns one action group, such as all git commands for a checkout. Split steps only for a resume point, a different Node or a guard. Each group is idempotent for the admitted `operation_id` and start tag. Use native dependencies, guards, resume, concurrency and per-behavior method locks; Swamp has no step retry setting. Required apt/UFW host locks remain allowed when named. Add no poller, supervisor, receipt protocol or agent action.

The script prints one public result JSON object on stdout. A refusal supplies a public `error_code` and exits nonzero. Keep the native failure status. Scripts pass secrets to tools on stdin or in protected config files, never argv or tracing. Remove unused credential fields and vault declarations; declare every required vault in parents and children. Check actual principal, resource and key grants separately from names in YAML.

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
| [ ] | Validate and record the complete immutable intent at admission. Use shared `StartSwampRun`. Let its builder set authoritative Operation, kind and Node identity. |
| [ ] | Steps read inputs. Remove Gateway intent, private-material and status callbacks. |
| [ ] | Use shared `SwampCommandStep::executionCopy` when a Gateway-owned secret changes. Pass only `{vault, key}` descriptors. Keep values out of persisted inputs and public outputs. |
| [ ] | Keep VM-created Pi/provider credentials on the VM. Consume them there through official SSH. |
| [ ] | Declare one fixed terminal selector. The shared subscriber and `projectTerminal` read the exact native run's history and invoke the family projector. |
| [ ] | Settle the Operation from native status and public outputs. Project F1 failure details for any failed step. |

## Test layout

Put each test where its project's CI runs it.

| Where | What it proves |
| --- | --- |
| `apps/gateway/tests/Feature/<Family>/*NativeTest.php` or the family's admission test | Admission, validation, refusal, lock key, execution copy, the exact input envelope, and projection of success and failure from a history fixture. |
| `apps/gateway/tests/Feature/Operations/GatewayWorkflowGrantCheckTest.php` | The grant manifest covers the new workflow and its vault keys. |
| `swamp/extensions/behaviors/operations/<family>/*_test.ts` | Typed decision and result behaviors, with Deno. |
| `swamp/tests/<family>_native_test.py` or `operations/<family>/*_native_test.py` | The workflow on the shared native harness: success, refusal before any write, first-failure skip chain and public result. |
| `swamp/tests` script tests | Each shipped script file directly, plus shellcheck or ruff. |

If a generator writes the workflow, commit a test that fails when the committed YAML differs from the generator output.

## Check all seven boundary decisions

Apply every boundary decision to admission, execution and projection.

Atomic state and idempotency checks stay. Root CA and GitHub App private keys stay only in the Gateway. Steps may call only the exact POST mint/sign primitives scoped to the admitted positive numeric Operation. Operator-native resume reconciles through decision 5's exact identity lookup.

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
| Gateway reads intent or private material in the step | Full intent as workflow `inputs`; secrets as `{vault, key}` names | [Inputs](https://swamp-club.com/manual/reference/workflows#inputs), [Vaults](https://swamp-club.com/manual/reference/vaults#vaultgetvault_name-key) |
| Secret on stdin from the Gateway, or in argv | Whole `${{ vault.get(...) }}` in `task.inputs.env` or `@swamp/ssh` `stdin`; the tool reads stdin or a 0600 file | [Environment mounting](https://swamp-club.com/manual/reference/vaults#environment-variable-mounting) |
| Retry loop or `attempt < N` | Idempotent step. Recovery is a new admission (decision 7) or operator `swamp workflow resume --run`; Swamp has no step retry key | [Retry failed steps](https://swamp-club.com/manual/reference/workflows#retry-failed-steps) |
| Sleep or poll until ready | One check step that fails when not ready. Keep tool-owned waits such as `cloud-init status --wait` | [Guard](https://swamp-club.com/manual/reference/workflows#guard), [Retry failed steps](https://swamp-club.com/manual/reference/workflows#retry-failed-steps) |
| Wait for a free worker | Inherited serve `queueTimeout` (600 s); set no key | [queueTimeout](https://swamp-club.com/manual/reference/workflows#queuetimeout) |
| Wait for a person | `manual_approval` task | [Manual approval](https://swamp-club.com/manual/reference/workflows#manual-approval-manual_approval) |
| Lock file, `flock`, family busy hold | Per-behavior method lock on one shared `behaviorName`, `concurrency`, or the generic admission `lock_key`. Named apt and UFW tool locks stay | [Method locking](https://swamp-club.com/manual/reference/behavior-definitions#per-behavior-method-locking), [Concurrency](https://swamp-club.com/manual/reference/workflows#concurrency) |
| "Already done" receipt or marker file | `guard` on public state or prior outputs | [Guard](https://swamp-club.com/manual/reference/workflows#guard) |
| Best-effort cleanup or compensation in code | A visible step that depends with `condition: failed` or `always`, or `allowFailure` | [Trigger conditions](https://swamp-club.com/manual/reference/workflows#trigger-conditions), [allowFailure](https://swamp-club.com/manual/reference/workflows#allowfailure) |
| Loop over items in one program | `forEach` with a per-item `behaviorName` using `self._index` | [forEach](https://swamp-club.com/manual/reference/workflows#foreach) |
| One program for many Nodes, or a call to another Node's agent | Placed steps in one run, each through `@swamp/ssh` from 209 | [Placement](https://swamp-club.com/manual/reference/workflows#target-2), [`@swamp/ssh`](https://github.com/swamp-club/swamp-extensions/tree/main/ssh) |
| Child Operation | `type: workflow` child step | [Child workflows](https://swamp-club.com/manual/reference/workflows#workflow-workflow) |
| Precondition refusal deep inside a script | Read step, typed decision, `assert` step before the first write | [Assert](https://swamp-club.com/manual/reference/workflows#assert-assert) |
| Custom timeout or process supervisor | `command/shell` `timeout` input, `@swamp/ssh` `timeoutSec`, native cancel | [Behavior definitions](https://swamp-club.com/manual/reference/behavior-definitions) |
| Script in a TypeScript string | Script file in manifest `additionalFiles`, resolved with `context.extensionFile`, or YAML `run: \|` | [additionalFiles](https://swamp-club.com/manual/reference/extensions/manifest#additionalfiles) |
| File, package, unit or firewall writes over SSH | `@swamp/cfgmgmt` `file`, `systemd`, `firewall`, `exec`, behind the family's host-key pin preflight; else `@swamp/ssh` | [`@swamp/cfgmgmt`](https://github.com/swamp-club/swamp-extensions/tree/main/cfgmgmt) |
| Progress or result callback to the Gateway | Step outputs; the Gateway projects the terminal event plus one history read | [Data access](https://swamp-club.com/manual/reference/data#cel-access) |
| Host timer that POSTs admissions | Native schedule with full intent through `SwampSchedulePublisher` | [Schedules](https://swamp-club.com/manual/reference/workflows#triggerschedule) |
| Cancel flag polled by steps | Native `swamp workflow cancel`; steps honour `context.signal` | [Cancellation](https://swamp-club.com/manual/reference/workflows#execution-cancellation) |

## Anti-patterns

Each of these is a review BLOCK. Most come from the [conformance ledger](#changelog).

| Anti-pattern | Why it fails | Do this instead |
| --- | --- | --- |
| `target` and `labels` on the same scope | Two selectors can disagree; the most frequent audit finding | `target: orbit-node-209` only |
| A step placed on the effect Node | That Node has no Swamp worker | Place on 209, reach the Node with `@swamp/ssh` |
| `forEach` items or concurrent runs sharing one `behaviorName` | The model lock serializes them and later items time out | `behaviorName` with `run.id` and `self._index` |
| A many-action TypeScript behavior, or a name-dispatched port of an agent command | Swamp cannot show, guard or resume the actions | One step per action group |
| A custom spawn wrapper around `command/shell` | Duplicates core and hides output | Native `command/shell` |
| A failure-reporter job or POST `/failure` | The Gateway already projects native status | Delete it |
| Dereferencing outputs of a failed or skipped step | CEL fails with `No such key` and hides the first error | Depend on `or [succeeded, skipped]` and guard on prior failure |
| Strict format checks on a third-party token | GitHub stateless tokens contain dots; the check refused a valid mint | Check only documented properties: length, scope, expiry |
| A workflow that reads a vault without `vaults:` | Validate passes, the run fails (op 6413) | Declare every vault, `vaults: []` when none |
| A secret in `run:`, argv, workflow inputs or `set -x` | Run history and `ps` expose it | `env` from `vault.get`, then stdin or a 0600 file |
| Copying the GitHub App or CA key into a vault | Root-of-trust keys stay in the Gateway | The two exact POST mint and sign primitives |
| A Gateway re-read timer, binding loop or retry | Duplicates Swamp and stalls Operations | One history read per terminal delivery |
| A non-default Swamp setting without a PLAN row | Hides a deviation from audit | Record reason and removal condition in PLAN |
| New orbit-agent action calls | The agent only observes | Steps run the system commands |

## PR and review checklist

Continue ports in the approved shape while live admission proof is pending. Use this checklist for every kind PR and its review.

- [ ] Follow the [translation steps](#translate-a-legacy-command) and avoid every [anti-pattern](#anti-patterns).
- [ ] Name the core feature and official extension checked.
- [ ] Use defaults. Record each deviation's measured reason and removal condition in PLAN.
- [ ] Delete the old executor, embedded script strings and obsolete callbacks in the same PR.
- [ ] Delete CLI, SDK and docs paths that served only the old executor. Leave `apps/agent` alone.
- [ ] Report Orbit app/test net lines and Swamp lines separately.

Check the completed port and report what the evidence proves.

- [ ] Cover success and refusal. Check redacted failure detail, cancellation and interrupted projection.
- [ ] Validate completed YAML with the pinned native runtime. Run focused native tests and script lint.
- [ ] Run Gateway admission, identity and projection tests.
- [ ] Separate source conformance from checks, served-source uptake and live/grant evidence. Cite this page in the review.

## Changelog

One line per lesson, newest first, with the PR it came from.

- v1 manual cross-reference: Swamp has no step retry key; the template names resume, guards, concurrency and method locks. `command/shell` takes `workingDir` and `timeout`.
- v1 reference check: `node.bootstrap_probe` sets `target` and `labels` together; placement now points to `firewall.inspect`.
- #2266 instance create: refuse a precondition (`instance.branch_in_use`) with a read, a typed decision and an `assert` before the first write.
- #2266 instance create: skip later steps after a failure and never read a skipped step's outputs; report the first failed step.
- #2266 instance create: accept documented third-party token formats; GitHub stateless tokens contain dots.
- #2266 instance create: op 6636 left no stderr for the failed step; every failing step needs the F1 output.
- Conformance ledger, 384 findings to 2026-11-01: placement, shared model names, embedded scripts, many-action behaviors, Gateway callbacks and vault lists are the most frequent defects.
- #2268 template v0 by lane 17: workflow, Gateway checklist, seven decisions, F1 slot.
