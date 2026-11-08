---
title: "Port a command to the Swamp shape"
description: "Chart, Gateway intent, Swamp phases, Ansible for all machine work, the case runner for the proof, time budgets, and what to delete."
---

# Port a command to the Swamp shape

Start every flow PR from this page. Review every flow PR against it and the [binding shape guide](https://github.com/hardimpactdev/orbit/blob/main/swamp/docs/swamp-shape.md). Agents load it through the [swamp-flow skill](https://github.com/hardimpactdev/orbit/blob/main/.agents/skills/swamp-flow/SKILL.md); the playbook part follows the [ansible-phase skill](https://github.com/hardimpactdev/orbit/blob/main/.agents/skills/ansible-phase/SKILL.md). Add a lesson after every merged flow change.

Decided by Nick, 2026-11-07, after the [shape spike](#what-the-spike-measured): the Gateway admits a command. Swamp orchestrates. Ansible does the work on machines. The case runner proves the command; Molecule tests the templates. (2026-11-08: the first form of this decision let Molecule prove the command. A measured A/B kept the runner; see the changelog.)

## The shape

Four parties share the work of a command. Each has one job.

| Who | Does | Does not |
| --- | --- | --- |
| **Gateway** | Admits the command, validates it, records the full intent, locks, starts one Swamp run, projects the result. | Run anything on a machine. |
| **Swamp** | Runs the logical phases in order, with guards, `forEach`, `allowFailure` and cancellation. Calls the Gateway's mint and sign primitives and external APIs (GitHub). | Run a command on a Node, except through an Ansible phase. |
| **Ansible** | Does every action on a machine. One Swamp step runs one tagged part of a repository playbook. The playbook reads, changes and checks in one run. | Call the Gateway, or decide the order of phases. |
| **Case runner** | Proves the command: it runs the real CLI with `--json` against a nested fleet and checks the answer, the machine state and the Gateway records. The cases are data in `swamp/proof/<family>/<command>.cases.yaml` ([format](/reference/verification-cases)). | Change a machine, or hold logic that a case could state. |
| **Molecule** | Tests the pure Jinja templates of a playbook against fixed text (`unit` scenario). | Prove the command or run on the nested fleet. |

Python inside a framework (Ansible, Molecule) is fine. New work adds no Python glue of its own: no wrapper scripts, no custom Ansible modules, and no second runner. The proof case runner in `swamp/proof/runner/` is the one runner.

## Port a command, step by step

A port has seven steps. Do them in this order.

1. [**Chart first.**](#1-chart-first) Update `docs/cli/<family>/<command>.mmd` to the ideal flow.
2. [**Gateway intent.**](#2-gateway-intent) Admit, record the intent, declare the workflow and the terminal result.
3. [**Swamp phases.**](#3-swamp-phases) One workflow, one Swamp step per logical phase.
4. [**Playbook.**](#4-the-playbook) One tagged part per phase. All machine work.
5. [**Proof.**](#5-prove-it) A cases file for the command, and a Molecule `unit` scenario for each template.
6. [**Time budget.**](#6-set-the-time-budget) Measure it with `profile_tasks`.
7. [**Delete.**](#7-delete-what-the-port-replaces) Steps, behaviors, tests and chart nodes that the port replaces, in the same PR.

One command is one PR: the chart, the documentation, the Gateway change, the workflow, the playbook, the scenario and the deletions, with the proof verdicts attached ([stage 4](/reference/command-development-pipeline#4-pr-and-ci)).

### 1. Chart first

The chart in `docs/cli/<family>/<command>.mmd` is the spec ([chart rules](/cli/README)). Update it to the ideal flow before any code. Tighten it with the `flow-tighten` skill. Then check the chart against the shape:

A phase is a stage of the command that a person can name, such as "Withdraw the Route" or "Converge the rule". Draw each phase as one arrow from Swamp to the Node, labelled with the phase. Its tasks belong in the playbook.

- Draw no arrow for a read, a plan or a check of a machine. They are tasks inside the phase.
- The chart shows no separate end check. A failed check fails the phase.
- Keep every edge case the command documents, even values the Gateway rewrites first.
- Update the chart first when the phase order changes.

### 2. Gateway intent

The Gateway keeps the intent, the lock and the projection. Swamp reads the intent as workflow inputs; no step calls back to the Gateway.

Add these fields to the family's existing registration and return the full input envelope from its projector.

```php
'REPLACE_KIND' => [ // in the family's direct-operations.php; keep its admission, workflow and projector
    'workflow' => 'operation-REPLACE_KIND',
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'REPLACE_JOB', 'step' => 'REPLACE_RESULT_STEP'],
    // only when the run reads a Gateway-owned secret:
    // 'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^REPLACE_KEY_PATTERN$']],
],
// Projector input(): ['intent' => $fullPublicIntent, 'execution_secrets' => $namesOnlyDescriptors]
```

Put every non-secret field the playbook needs under `intent`, frozen at admission. The Gateway computes what it knows: addresses, host keys, captured states and owned paths. A playbook reads vars only.

- Secrets become `{vault, key}` descriptors under `execution_secrets`.
- A Swamp job writes the vault value to a 0600 file before the playbook runs.
- A playbook never sees a secret in argv or task output.
- Pass `lockKey: OperationStore::lockKey(REPLACE_TARGET_TYPE, $id)` to `StartOperationAction::execute` when the kind changes a shared target.
- Add any `execution_copies` vault to `swamp/config/gateway-workflow-grants.json`.
- Key patterns must pass Swamp's regex-safety check. Expand optional groups into a flat alternation.

The Gateway projects the terminal result from one named step. It reads that step's `stdout` as a JSON object, or, with `'native' => true`, the run's own data. An Ansible phase prints Ansible's log, so add a small result step.

- The phase writes `result.json` into the run folder, in the success path and in the failure path.
- The result step prints it with `timeout 10 cat "$ORBIT_RUN/result.json"`.
- The result step runs with `condition: always`, so a refusal keeps its `error_code`.
- Give the step the name the Gateway already reads. The firewall kinds use `Check the rules`, so the projector does not change.

| Done | Gateway checklist |
| --- | --- |
| [ ] | Validate and record the complete immutable intent at admission. Use shared `StartSwampRun`; its builder sets Operation, kind and Node identity. Remove intent, private-material and status callbacks. |
| [ ] | Push a changed Gateway-owned secret with `SwampCommandStep::executionCopy` and pass only `{vault, key}` descriptors; VM-created Pi credentials stay on the VM. Declare one fixed terminal selector; `projectTerminal` reads history once and calls the family projector. |
| [ ] | Projection keeps an exact native terminal that met its budget, even after the Gateway expiry, and drops the `timed_out` mark. One history read; no timer, retry or second reader. |

### 3. Swamp phases

The workflow has one Swamp step per logical phase, plus the steps that are not machine work. For a command on one Node the shape is always the same:

```yaml
id: REPLACE_WORKFLOW_UUID
name: operation-REPLACE_KIND
description: REPLACE_ACTION_GROUP
vaults: []
queueTimeout: 30
inputs:
  type: object
  properties:
    operation_id: {type: integer, minimum: 1}
    node_id: {type: integer, minimum: 1}
    kind: {type: string, enum: [REPLACE_KIND]}
    intent: {type: object}
    execution_secrets: {type: object, additionalProperties: false}
  required: [operation_id, node_id, kind, intent, execution_secrets]
  additionalProperties: false
jobs:
- name: REPLACE_JOB
  steps:
  - name: input                      # the intent as a 0600 file, and the shipped playbooks
    labels: {role: swamp}
    writes: true
    task:
      type: behavior
      behaviorType: '@nckrtl/ansible-input'
      behaviorName: ansible-input
      methodName: write
      inputs:
        intent: '${{ inputs.intent }}'
        path: '${{ "/tmp/orbit-ansible-" + run.id + "/intent.json" }}'
  - name: REPLACE_PHASE              # one Ansible phase: read, act, check
    labels: {role: swamp}
    dependsOn: [{step: input, condition: {type: succeeded}}]
    writes: true
    task:
      type: behavior
      behaviorType: command/shell
      behaviorName: '${{ "REPLACE_PHASE-" + string(inputs.node_id) }}'
      methodName: execute
      inputs:
        run: timeout REPLACE_PHASE_SECONDS /opt/orbit/ansible/venv/bin/ansible-playbook -i localhost, --tags REPLACE_TAG "$ORBIT_ANSIBLE/REPLACE_PLAYBOOK.yml"
        env:
          ORBIT_RUN: '${{ "/tmp/orbit-ansible-" + run.id }}'
          ORBIT_ANSIBLE: '${{ steps.input.outputs.assets }}'
          ANSIBLE_CONFIG: '${{ steps.input.outputs.assets + "/ansible.cfg" }}'
          ANSIBLE_SSH_ARGS: '${{ "-o ControlMaster=auto -o ControlPersist=60s -o IdentitiesOnly=yes -o GlobalKnownHostsFile=/dev/null -o UserKnownHostsFile=/tmp/orbit-ansible-" + run.id + "/known_hosts" }}'
          ANSIBLE_CALLBACKS_ENABLED: ansible.posix.profile_tasks   # the per-task times of step 6
          ANSIBLE_FORCE_COLOR: '0'
          ANSIBLE_DISPLAY_FAILED_STDERR: '1'
  - name: REPLACE_RESULT_STEP        # the result the Gateway projects; also after a failed phase
    labels: {role: swamp}
    dependsOn: [{step: REPLACE_PHASE, condition: {type: always}}]
    task:
      type: behavior
      behaviorType: command/shell
      behaviorName: '${{ "REPLACE_PHASE-result-" + string(inputs.node_id) }}'
      methodName: execute
      inputs:
        run: timeout 10 cat "$ORBIT_RUN/result.json"
        env: {ORBIT_RUN: '${{ "/tmp/orbit-ansible-" + run.id }}'}
  - name: cleanup                    # the run folder goes whatever happened
    labels: {role: swamp}
    dependsOn: [{step: REPLACE_RESULT_STEP, condition: {type: always}}]
    writes: true
    task:
      type: behavior
      behaviorType: command/shell
      behaviorName: '${{ "REPLACE_PHASE-cleanup-" + string(inputs.node_id) }}'
      methodName: execute
      inputs:
        run: timeout 30 rm -rf -- "$ORBIT_RUN"
        env: {ORBIT_RUN: '${{ "/tmp/orbit-ansible-" + run.id }}'}
```

Real references: [`operation-firewall-ansible`](https://github.com/hardimpactdev/orbit/blob/main/swamp/workflows/workflow-operation-firewall-ansible.yaml) (one phase, one Node) and [`route-withdraw-node-ansible`](https://github.com/hardimpactdev/orbit/blob/main/swamp/workflows/workflow-route-withdraw-node-ansible.yaml) (one phase per Node, run in parallel by a parent `forEach` over child workflows).

- **Several logical phases:** one phase step each, in order, with `dependsOn`. Pass data between phases through the run folder, not through step outputs.
- **Several Nodes:** a parent `forEach` over a child workflow per Node, so the Nodes run in parallel. Give each item a unique `behaviorName`.
- **Placement:** `labels: {role: swamp}` on every step, and no `target`. Ansible reaches the Node over SSH from the Swamp Node.
- **Native steps:** they stay for work that is not machine work. See [What stays native](#what-stays-native).
- **Cancellation, resume and interrupted runs:** use the native features and the [seven boundary decisions](#check-all-seven-boundary-decisions). Add no timer, poller or retry loop.

### 4. Write the playbook

The playbook is one file per workflow, `swamp/ansible/<workflow name>.yml`, with one tagged part per phase. The [ansible-phase skill](https://github.com/hardimpactdev/orbit/blob/main/.agents/skills/ansible-phase/SKILL.md) has the full rules. These are the ones that shape a port.

- **One run does read, act and check.** The Swamp step count does not grow with the number of commands on the machine.
- **Modules, not commands.** Use the module that owns the state, such as `community.general.ufw`, `copy`, `file`, `systemd` or `community.docker`.
- **`command` is the last resort.** Use it for a tool without a module, with `changed_when`.
- **Every task is an end state.** A second run of a finished phase reports zero changes.
- **Reload after a change only.** Use a handler, or a loop over the changed results.
- **Refuse first.** Check the preconditions before the first change. Put the public error code in the `fail_msg` of the `assert`.
- **No Python of ours.** Add no custom module and no helper script. Take a missing module to the orchestrator.
- **Pins.** Pin `ansible-core`, the collections and any Python library a filter needs in `swamp/ansible/requirements.*`. List every new file in `swamp/manifest.yaml` under `additionalFiles`.

A decision that needs more than a `when` is a pure Jinja template, `swamp/ansible/templates/<name>.j2`. It reads vars, reads no state, and prints one JSON object. Examples are the position of a firewall line and the sections a withdrawal removes. The unit scenario runs it on fixed text. Keep it small. A template that outgrows one reviewer is a [limit](#the-limits), not a reason to hide the logic in a script.

### 5. Prove it

Two things prove a port.

| What | Runs | Proves |
| --- | --- | --- |
| Molecule `unit` scenario, `swamp/proof/molecule/<command>/molecule/unit` | on any machine, no fleet: `molecule test -s unit` | The pure Jinja templates, against fixed text: the plan for a rule, the sections a withdrawal removes, the refusals with their codes. Cases are data. |
| Cases file, `swamp/proof/<family>/<command>.cases.yaml` | in the task VM, against the nested fleet, with the case runner ([stage 3](/reference/command-development-pipeline#3-proof)) | The real CLI with `--json`: the answer, the machine state, the Gateway records, and that nothing else changed. |

The [format of the cases file](/reference/verification-cases) lists the steps, the batches and how cases spread over the Nodes and run several at a time. Read it before you write cases.

The checks that make the proof strong:

- **Idempotence:** run the command twice. The second answer is `succeeded` with the skip reason, and the Node state is byte-identical.
- **Idempotence of a phase:** run `ansible-playbook` twice on the Node. The second run reports zero `changed`.
- **Negative proof:** break the effect, such as a deleted rule or an edited file. Only that case may fail (`runner case ID --negative`).

Run `molecule test -s unit` before every push. CI runs the same cases through `bin/swamp-shape-gate`.

### 6. Set the time budget

The execution plan (`PLAN.md` on beast, F8) requires a budget for every kind. A flow PR without one fails review. Measure it:

- **Phase times:** run the phase with `ANSIBLE_CALLBACKS_ENABLED=ansible.posix.profile_tasks`. The recap lists every task with its time.
- **Slow tasks:** a task over one second needs a decision. Use fewer tasks or one module call for a list.
- **End to end:** take step `durationMs` and the run's start and end from native history. Use live runs on a disposable topology. State the sample size and the median.

An Ansible phase has a fixed cost: the step dispatch (about 1.1 s), the start of `ansible-playbook` (about 1.1 s), the SSH connection, and about 0.35 s for each remote task. A native `@swamp/ssh exec` step costs the dispatch and one connection. A phase wins when it replaces several steps or any serial dispatch.

| Item | Rule |
| --- | --- |
| Run budget | Every kind expires; a measured p95 with headroom wins. Otherwise 30 minutes until the first success, then 1.5× the longest success. `instance.create` has 60 s (PLAN: under 10 s; policy, not proof). |
| Enforcement | Serve `--max-run-duration` caps runs at the longest fixed budget. At the kind budget the Gateway marks `operation.timed_out` and cancels (decision 4). Add no family timer or poller. |
| No-run rows | Expire after 600 s but keep the lock until the exact native lookup by Operation id proves no run (L17-2300-OD1). Timeouts and transport errors prove nothing. |
| Phase timeout | Each phase command starts with a literal `timeout <phase budget>`. On exit 124 the shared F1 projector renders `<step> timed out after <N>s` (OD2; pending, lane 16). The budget covers the waits inside the playbook. |
| `queueTimeout` | Explicit on every step (guide rule 6), never the serve's 600 s default. Keep it below the run budget. |
| Retries and waits | Only inside the phase: a task's `until`, `retries` and fixed `delay`, or a module's own retry option. State them in the PR. A shell loop is never allowed. |

### 7. Delete what the port replaces

Delete in the same PR. A port that adds the new path and leaves the old one is a review BLOCK.

- the workflow steps and child workflows the phase replaces;
- the TypeScript behaviors that only planned, read or checked a machine, and their tests;
- shipped scripts, and Gateway code that called back for intent or progress;
- the chart arrows and `%%>` notes for reads, plans and checks;
- the cases of the old runner that the scenario now holds;
- grandfather-list and baseline rows, in the last commit after the last rebase.

A pure plan moves into a Jinja template and its Molecule `unit` cases.

State the net in the PR: lines of `swamp/` added and removed, per part. The parts are behaviors, workflows, playbooks, templates, scenarios and tests. The migration total must turn negative (F15). A growth needs a reason that says why Ansible, a module or a template could not do it.

## What stays native

A Swamp step stays native when it is not machine work.

- Calls to the Gateway's mint or sign primitive. They are the only two Gateway calls a step may make.
- External APIs: GitHub with a minted token.
- The order of phases: `dependsOn`, `guard`, `forEach`, `allowFailure` and `condition: always`.
- The `input`, result and `cleanup` steps of the run folder.
- A hand-off to a child workflow.
- Work on the Swamp Node itself that is not a Node change, such as a vault push or a trigger removal.

Do not keep a machine action native because it is "only one command". A single `ufw` change over `@swamp/ssh` is one dispatch and one connection. A phase costs the dispatch and the start of `ansible-playbook` on top, and it pays that back from the second command or the second machine. The spike has no live numbers for a lone command yet. Until it has, a PR that keeps one command native records the measured numbers of both forms.

## What the spike measured

Two commands, built both ways on the same nested fleet (details and the raw numbers: `migration-dashboard/drafts/shape-spike.md`).

Static counts come from the workflows. Live times are measured on the nested fleet of a task VM with `profile_tasks`. A cell that says pending has no live measurement yet.

| | `firewall:allow` today | `firewall:allow` as one phase | `instance:destroy`, one Node, today | `instance:destroy`, one Node, as one phase |
| --- | --- | --- | --- | --- |
| Swamp steps of a typical run | 6, and 7 skipped items of the change loop | 4 (`input`, phase, result, `cleanup`) | 4, and `cleanup` skipped | 3 (`input`, phase, `cleanup`) |
| Dispatches that reach the Node | 3 SSH steps for one change; one more per extra line | 1 phase, 1 connection | 2 Ansible runs and a TypeScript step between them | 1 Ansible run |
| TypeScript that decides | `plan.ts`, 389 lines, with 239 lines of tests and a 105-line fixture | none; 118 lines of Jinja and 181 lines of Molecule unit cases | `composeWithdrawal` and `sections.ts`, shared with Route create and update | 158 lines of Jinja and 148 lines of unit cases |
| End-to-end time | pending | pending | 25.0 s for the whole destroy, 14 to 18 s for each Node child (`DECISIONS.md`, #3039) | pending |
| A second run changes nothing | by design (plan is empty) | pending | by design | pending |

### The limits

The shape has limits. Read them before you put a decision into a template.

**Decision logic costs the most.**

- The rule order of a firewall line is 118 lines of Jinja, against 389 lines of TypeScript.
- The unit cases hold the same rules as the TypeScript tests.
- Network containment needed `ansible.utils` and `netaddr` as new pins.
- Jinja has no types and no functions. A macro returns text, so values go through JSON.
- A template error gives a poor message.

**Jinja can merge keyed sections, and nothing deeper.**

- Caddy sites and PHP pools are keyed text blocks. Their merge ports.
- The private DNS records and catalog do not port. The catalog merge recurses over arbitrary JSON.
- A Node with DNS states fails with `route.dns_withdrawal_unsupported` in the spike.
- The validation of each captured Caddy state is not ported.

**One phase drops a check.**

- The old apply refused a file that changed after the read.
- In one phase the gap is a few hundred milliseconds. The Gateway already holds the Route Nodes.

**A phase prints a log, not a result.**

- The Gateway projects JSON, so a phase needs `result.json` and a result step.
- That step is the fourth step of the firewall run.

**Molecule tests the templates, not the command.**

- A Molecule batch proved the command about as fast as the runner once the runner spread its cases over the Nodes, but it needed more lines of YAML than the runner and its tests need of Python (3,576 against about 3,060), and it could not state a terminal menu choice, a first-line wait or a collected failure list. The runner stays.
- The unit scenarios run on any machine with `localhost`. The Mac of the spike had no container runtime, so nothing ran on a throwaway container.

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

## Anti-patterns

Each of these is a review BLOCK. Most come from past flow reviews.

| Anti-pattern | Why it fails | Do this instead |
| --- | --- | --- |
| A machine action as a native step of its own (`@swamp/ssh exec`, `cfgmgmt`) where an Ansible phase would do it | Each action is one dispatch (about 1.1 s) and one connection; a read, a plan and a check around it multiply the steps | Put it in the phase's playbook. A read-then-act-then-check is tasks, not steps. |
| A read, a TypeScript plan or compose step, and an apply step around one Node change | The plan cannot see what the apply sees, and every step is a dispatch (`route-withdraw-node`: 5 steps for one Node) | Plan inside the playbook. A decision becomes a Jinja template with unit cases. |
| A shipped script, a Python helper or a custom Ansible module of ours | The code that runs is not a reviewed module; Orbit owns Python it should not | Use the module that owns the state, or take the gap to the orchestrator with evidence. |
| `target` and `labels` in one effective placement, or a step on the effect Node | Selectors can disagree, the top audit finding | `labels: {role: swamp}` on every step |
| `forEach` items or concurrent runs sharing one `behaviorName` | The model lock serializes them and later items time out | `behaviorName` unique per run and item |
| A secret in `run:`, argv, inputs, a task result or `set -x`; a CA or GitHub App key in a vault; a secret returned from a Node | History and `ps` expose it; root-of-trust keys stay in the Gateway | `env` from `vault.get`, then a 0600 file the input builder reads and removes; `no_log: true` on the task; the mint and sign primitives; Node-held keys stay on the Node (decision 3) |
| A retry or wait of our own: a shell loop, `sleep`, a Gateway re-read timer, a binding loop | Duplicates Swamp and stalls Operations | The task's `until`/`retries`/`delay` inside a phase with a literal `timeout`; otherwise a new admission or `swamp workflow resume` |
| A step that reads failed or skipped outputs, all-pairs `always` edges, or the whole `${{ steps }}` map in a result step | CEL fails with `No such key`; #2261 and #2253 grew dozens of edges | Read `"x" in steps && steps.x.status` for the steps you need; the result step reads a file |
| A step input that grows with history or fleet size: `data.query`, or `results: ${{ steps }}` over a fleet fan-out (#2334) | One dispatch frame can pass 64 MiB, and the worker loops (B7, #2310) | Read one stable record with `data.latest`; per-target results as compact arrays |
| `forEach.in` reading outputs of a step in the same job | e459 expands it before the outputs exist | Read `inputs`, literals or an earlier job's outputs, or pass the list to a child workflow |
| A kind without a measured p95 and budget, a flat or borrowed budget (L17-PR-2324-01), or a non-default Swamp setting without a PLAN row | Runs wait for minutes, as op 6639 did | [Set the time budget](#6-set-the-time-budget); record reason and removal condition in PLAN |
| A workflow that reads a vault without `vaults:` | Validate passes, the run fails (op 6413) | Declare every vault it reads; `vaults: []` when none |
| Changing a flow's step order without changing its chart first | The chart is the spec; a workflow that drifts from it loses edge cases | Update the chart first |
| Swamp or Orbit growth without a reason | `swamp/` grew +226,316 lines against Orbit −162,866; the migration total of +63,450 must turn negative (F15) | Delete in the same PR ([step 7](#7-delete-what-the-port-replaces)) |

## Test layout

Put each test where its project's CI runs it.

| Where | What it proves |
| --- | --- |
| `apps/gateway/tests/Feature/<Family>/*NativeTest.php`, plus `Operations/GatewayWorkflowGrantCheckTest.php` | Admission, refusal, lock key, execution copy, the input envelope, projection of success and failure, and grant coverage for the workflow and its vault keys. |
| `swamp/proof/molecule/<command>/molecule/unit` | The Jinja templates of the playbook, on fixed text, with every refusal code. Runs without a fleet. |
| `swamp/proof/<family>/<command>.cases.yaml` | The command on the nested fleet, run by the case runner: the answer, the machine state, the Gateway records, idempotence ([step 5](#5-prove-it)). |
| `swamp/extensions/behaviors/operations/<family>/*_test.ts` | Typed behaviors that stay (a result that a file cannot print). Not plans of a machine change. |

Test Orbit's logic with fast sibling tests. Do not add native workflow tests or test Swamp's engine, shell commands or CEL through a custom harness.

## PR and review checklist

Use this checklist for every flow PR and its review.

### The design

These items cover the chart, the Gateway and the playbook.

- [ ] The chart is updated first and shows one arrow per logical phase. The step count of a typical run is stated.
- [ ] The Gateway records the full intent; no step calls back for it.
- [ ] Every action on a machine is in a playbook, in a module that owns the state.
- [ ] No script, no custom module and no `@swamp/ssh` step that a phase could hold.

### The proof

These items cover the proof, the budget and the deletions.

- [ ] A second run of every phase changes nothing (the `idempotence` case).
- [ ] The Molecule `unit` scenario passes, and the verdicts of the cases file are attached.
- [ ] The measured p95, its sample, the run budget, `queueTimeout` and the per-phase times (`profile_tasks`) are in the PR.
- [ ] Everything the port replaces is deleted, with the line counts per part (F15).
- [ ] `bin/swamp-shape-gate` is green; the playbook passes `ansible-lint` in the `production` profile.
- [ ] Core feature and official extension checked, named in the PR.
- [ ] Redacted failure detail, cancellation and interrupted projection are covered.

## Changelog

Each lesson replaces the rule it changes above.

- 2026-11-07: rewritten for the shape of the spike: Ansible does every machine action, Molecule proves the command.
- 2026-11-08: the case runner proves the command again; Molecule keeps the `unit` scenarios. A measured A/B of the same 50 `firewall:allow` cases chose it: the speed came from spreading cases over the Nodes and running them in parallel, which the runner now does, and Molecule needed more lines.
