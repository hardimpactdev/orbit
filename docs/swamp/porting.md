---
title: "Port a command to the Swamp shape"
description: "Where to change a command's chart, admission, workflow and playbook, and how to prove and review the port."
---

# Port a command to the Swamp shape

This page maps a port to its repository files and checks. The binding [Swamp shape guide](https://github.com/hardimpactdev/orbit/blob/main/swamp/docs/swamp-shape.md) owns machine execution, placement, secrets, callbacks and result transport. The [chart README](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/README.md) owns chart design. Update those owners when their rules change. Put reusable lessons in the reference or solution that owns the behavior, not an append-only port log.

## The shape

Follow the binding guide, including its measured native-machine exception. An existing workflow is evidence of its implementation, not permission to repeat a grandfathered shape. The [command development pipeline](/reference/command-development-pipeline) owns stage order: prepare the spec as the first branch commit, build and prove the whole feature in one task copy, then open one complete PR.

## Port a command, step by step

### 1. Chart first

Read the command page, chart and Gateway declaration. Update the ideal chart before implementation, preserving public outcomes and admission rewrites. Use [flow-tighten](https://github.com/hardimpactdev/orbit/blob/main/.agents/skills/flow-tighten/SKILL.md) to critique it, then run `bin/check-flows`. The chart README owns phase granularity, optional paths, sharing and failure design.

### 2. Gateway intent

Keep the family's registration and projector together. Use shared `StartSwampRun` and the existing Operation lock through `OperationStore::lockKey`. [Swamp command steps](/reference/swamp-command-steps) documents the input envelope, execution-copy descriptors and terminal selectors; the shape guide owns their execution policy.

The declaration names the workflow and its fixed terminal selector. Keep job and step names synchronized with the workflow. Encode empty maps as objects. Expand Gateway macros before freezing intent. Reserve publication before capturing runtime definitions that depend on it.

Register every intended execution-copy vault and key pattern in the kind and `swamp/config/gateway-workflow-grants.json`. Native `serve check-config` checks CEL, while `bin/check-operation-kinds` checks Orbit's registry and grant parity. Both matter.

### 3. Swamp phases

Use the official Swamp skill and pinned manual for scaffolding and composition syntax. Use the shape guide's [Ansible phase contract](https://github.com/hardimpactdev/orbit/blob/main/swamp/docs/swamp-shape.md#ansible-phase-contract) and [result transport](https://github.com/hardimpactdev/orbit/blob/main/swamp/docs/swamp-shape.md#result-transport), not a copied workflow template.

The implementation spans `swamp/workflows/`, the input builder and playbook under `swamp/ansible/`, and manifest assets. For a concrete Ansible child, inspect [route-withdraw-node](https://github.com/hardimpactdev/orbit/blob/main/swamp/workflows/workflow-route-withdraw-node.yaml) alongside its current declaration. Check its choices against the guide rather than treating them as new defaults.

Keep dispatch input bounded to the fields and statuses the next step needs. A whole `steps` map or history-growing query can exceed dispatch limits. Use a native child workflow for a cohesive phase with an explicit contract, not merely to rename another action.

### 4. Write the playbook

Use [ansible-phase](https://github.com/hardimpactdev/orbit/blob/main/.agents/skills/ansible-phase/SKILL.md) as the implementation router. The shape guide owns playbook requirements. [Ansible phase lessons](/solutions/ansible-phase-lessons) covers Jinja, generated vars, delegation, idempotence and fixture traps.

### 5. Prove it

Use the [verification-case format](/reference/verification-cases) and [proof stage](/reference/command-development-pipeline#3-proof). Run the real CLI in the task copy against its nested fleet. Check the answer, Node state and Gateway records. Template tests alone do not prove a command.

Prove repair from stale or missing state, an unchanged repeat, and the declared failure/recovery path. A repeated completed phase reports zero changes. Give shared or damaged fixture state its own batch. Restore fixture DNS overrides and temporary addresses after failure too; isolate concurrent fixtures' identifiers and shared local state. Real Docker tests require disposable nested-fleet resources and `ORBIT_DISPOSABLE_DOCKER=1`, never a shared host.

### 6. Set the time budget

The [native run budget rule](/reference/operations#native-run-budgets) owns budget selection. Do not size a budget from a run that skipped or deferred its work. Record the sample, measured p95, run budget, queue timeout and fresh versus unchanged timings. Use `ANSIBLE_CALLBACKS_ENABLED=ansible.posix.profile_tasks` for task times and native history for step and end-to-end times.

The shape guide owns command timeout and queue requirements. Measure both forms before retaining a native machine step; do not borrow an exception from another command.

### 7. Delete what the port replaces

Trace production admissions and parent/child callers. Delete replaced steps, uncalled roots, behaviors, scripts, tests, assets and baseline rows in the same change. Keep shared helpers with live callers. State net Orbit lines and split Swamp growth into workflows, behaviors, playbooks, scripts, tests and fixtures, explaining custom growth.

## What stays native

Use the shape guide's [execution rules](https://github.com/hardimpactdev/orbit/blob/main/swamp/docs/swamp-shape.md#the-shape). This reference grants no additional exception for a single command, callback or result step.

## Check all seven boundary decisions

Read the shape guide for execution and custody, [Operations](/reference/operations) for locking, uncertain start, cancellation and interruption, and [Swamp command steps](/reference/swamp-command-steps) for the shared helper API. Keep existing atomic state and idempotency checks when porting. A family adds no alternative execution or reconciliation path.

## Test layout

Put each test where its project's CI discovers it.

| Location | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/<Family>/` | Admission, refusals, lock keys, input envelopes, execution copies, terminal projection and grant coverage |
| `swamp/tests/ansible_*_test.ts` or Python tests listed in the gate's `ANSIBLE_TESTS` | Pure templates and expressions against fixed inputs, with refusal codes |
| `swamp/proof/<family>/<command>.cases.yaml` | Real CLI, Node and Gateway outcomes on the nested fleet |
| `swamp/extensions/behaviors/operations/<family>/*_test.ts` | Orbit's typed behavior logic |

Run the tests where the owning project's CI discovers them. Test Orbit logic, not Swamp's engine or shell semantics through a second custom runner. Run `bin/swamp-shape-gate` for pinned lint, real module argument checks, command-output tests and validation; report credential-dependent skips. Test parser changes with recorded positive and counter-case text through real Ansible, not a copied production regex.

## PR and review checklist

Review the port against its chart and the binding guide before opening the PR.

- Chart, page and implementation agree; phase-order changes have reasons.
- The execution shape, custody and result contract meet the binding guide.
- Admission and projection tests cover the changed contract and grant declarations.
- Template tests and the cases covering the changed behavior pass before the PR opens. Docs-only and deletion-only changes need no fleet receipt.
- Repair, unchanged repeat and failure-state evidence cover the affected behavior.
- Timings, budgets, core/official-extension gap checks and explained growth are reported.
- Replaced code and baseline rows are removed; `bin/check-flows`, `bin/check-operation-kinds` and `bin/swamp-shape-gate` pass.
- An independent review runs before opening or updating the PR, as the contributor guide requires. Follow [stage 4](/reference/command-development-pipeline#4-pr-and-ci) for CI and delivery.

Source conformance, isolated proof, CI, served-source uptake and live confirmation are different evidence. A release confirms uptake; it is not the first proof. [Gateway recovery](/reference/gateway-recovery#load-swamp) owns managed load and release procedures. Do not remove a checkout script mode while the served workflow still calls it.
