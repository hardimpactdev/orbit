---
title: "Port a command to the Swamp shape"
description: "How to port an Orbit command's chart, Gateway admission, Swamp workflow, Ansible playbook, and proofs."
---

# Port a command to the Swamp shape

This guide maps a command port to its repository files and required gates. Follow the binding [Swamp shape guide](https://github.com/hardimpactdev/orbit/blob/main/swamp/docs/swamp-shape.md) for execution, placement, secrets, and result transport, and the [command development pipeline](/reference/command-development-pipeline) for stage order.

## Step-by-step port

Follow these seven steps in order for every command port:

1. **Chart first:** Update the command chart (`docs/cli/<family>/<command>.mmd`) before coding, preserving public outcomes and admission rewrites. Review with [flow-tighten](https://github.com/hardimpactdev/orbit/blob/main/.agents/skills/flow-tighten/SKILL.md) and run `bin/check-flows`.
2. **Gateway intent:** Use `StartSwampRun` and `OperationStore::lockKey` ([Swamp command steps](/reference/swamp-command-steps)). Align job/step names, register grants in `swamp/config/gateway-workflow-grants.json`, and run `bin/check-operation-kinds` plus `serve check-config`.
3. **Swamp phases:** Build workflows under `swamp/workflows/` and inputs/playbooks under `swamp/ansible/` (see [route-withdraw-node](https://github.com/hardimpactdev/orbit/blob/main/swamp/workflows/workflow-route-withdraw-node.yaml)). Keep dispatch inputs bounded.
4. **Playbook:** Follow [ansible-phase](https://github.com/hardimpactdev/orbit/blob/main/.agents/skills/ansible-phase/SKILL.md) and [Ansible phase lessons](/solutions/ansible-phase-lessons).
5. **Prove it:** Add cases under `swamp/proof/<family>/<command>.cases.yaml` ([Verification cases](/reference/verification-cases)) and run `orbit proof` in a nested fleet ([stage 3](/reference/command-development-pipeline#3-proof)). Cover repair, zero-change repeats, and failures; real Docker tests need `ORBIT_DISPOSABLE_DOCKER=1`.
6. **Time budget:** Size budgets from full (non-skipped) runs using `ANSIBLE_CALLBACKS_ENABLED=ansible.posix.profile_tasks` and native step history ([native run budgets](/reference/operations#native-run-budgets)).
7. **Delete replaced code:** Remove superseded steps, workflows, scripts, tests, and baseline rows in the same PR. Keep shared helpers that still have live callers.

Encode empty maps as JSON objects and expand Gateway macros before freezing intent in step 2.

## Test layout and gates

Place each test where CI discovers it:

| Location | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/<Family>/` | Admission, refusals, lock keys, input envelopes, execution copies, projection, and grants |
| `swamp/tests/ansible_*_test.ts` (or `ANSIBLE_TESTS` Python tests) | Templates, expressions, and refusal codes against fixed inputs |
| `swamp/proof/<family>/<command>.cases.yaml` | End-to-end CLI, Node, and Gateway behavior on the nested fleet |
| `swamp/extensions/behaviors/operations/<family>/*_test.ts` | Orbit typed behavior logic |

Run `bin/check-flows`, `bin/check-operation-kinds`, and `bin/swamp-shape-gate` before opening the PR (note any credential-dependent skip when `SWAMP_API_KEY`/`SWAMP_SIGNIN_TOKEN` is unset and `--only validate` exits `78`). Complete an independent review before opening or updating the PR ([stage 4](/reference/command-development-pipeline#4-pr-and-ci)). Never remove a checkout script mode while a served workflow still calls it ([load Swamp](/reference/gateway-recovery#load-swamp)).
