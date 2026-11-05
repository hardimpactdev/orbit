---
title: "Old paths"
description: "Code, tools and files that a newer path replaced or will replace. Check this page before you build on something."
covers:
  - swamp/proof/topologies/**
  - tools/swamp-proof-guest/{converge-network-proof.py,network-proof.sh,peer-dns-proof.sh,vaultwarden_setup.py,vaultwarden_account.py}
  - apps/gateway/app/Infrastructure/TaskVms/{RecipeImageBuilds.php,RecipeImageBuildSteps.php}
  - apps/gateway/app/Console/Commands/BuildTaskVmRecipeCommand.php
  - swamp/extensions/behaviors/operations/proof-reaper/**
---

# Old paths

Check this page before you build on something. An item here is old: a newer path replaced it, or will replace it. Each item has one verdict.

- **DELETE** means replaced, with no live caller. It was removed in the change that added this page.
- **MARK** means scheduled for replacement but still used or read. Keep it and do not extend it. The file or its folder README says so.
- **KEEP** means it looked old and is not. The reason is given.

This page was written on 2026-11-05, after the merge queue was removed ([command development pipeline, stage 4](/reference/command-development-pipeline#4-pr-and-ci)).

## Merge queue

PRs merge straight into `main` after their gate. No workflow, action or release check reads a queue event any more.

| Item | Verdict | Why |
| --- | --- | --- |
| `merge_group` in `.github/workflows/ci.yml`, `swamp-shape.yml` and `.github/actions/ci-scope/action.yml` | DELETE | No queue exists. Base, `main` test and Swamp secrets read only `pull_request` and `push`. |
| Queue cases of `tools/test_ci_policy.py` and `tools/test_ci_runner_policy.py` | DELETE | They now check that no queue event is read. |
| `merge_group` run and `gh-readonly-queue/` branch in `GitHubArtifactWebBuild.php` | DELETE | A release takes the web build of a `push` or `workflow_dispatch` run on `main` only. |
| Queue branch cases of `proof:run`: `ProofRunIntent`, `GitHubProofCommitSource`, their tests, two rows of `swamp/proof/proof/run.cases.md` | Open | Another change owns the `proof:run` code. Remove them there. |

## Proof tooling

The current path is in `swamp/proof/README.md`. `bin/proof-fleet reset GROUP` builds a nested fleet in the task VM. The case runner in `swamp/proof/runner/` runs the cases files ([verification cases](/reference/verification-cases)). `orbit proof:run` is the target entry.

| Item | Verdict | Why |
| --- | --- | --- |
| `bin/swamp-proof`, `tools/swamp_proof.py` | DELETE | It made its own Project 62 VM, which a task VM cannot call. |
| `up.py` and `run.py` in `tools/swamp-proof-guest/`, and their tests | DELETE | Only `tools/swamp_proof.py` called them. |
| `fixture.py`, `gateway.php`, `boot.py`, `terminal.py`, `ufw-shim.sh` in `tools/swamp-proof-guest/` | KEEP | The reset and the runner use them. |
| `converge-network-proof.py`, `network-proof.sh`, `peer-dns-proof.sh`, `vaultwarden_*.py` in `tools/swamp-proof-guest/` | MARK | Only the old fixtures name them. |
| `swamp/proof/topologies/minimal` | MARK | Two old fixtures read it. Nothing else does. |
| `swamp/proof/<kind>.json` fixtures and per-kind folders | MARK | Nothing runs them. They record the contract of kinds with no cases file yet. |
| `swamp/proof/receipts/` | MARK | Old evidence. Delete it with the fixtures. |
| `proof-reaper`, and the Project 62 branch of `TaskProvisionIntent` and `ProofReaper` | MARK | They cancel Project 62 groups, which only the removed tool made. |
| `bin/proof-fleet verify` | KEEP | It is the runtime path today. The target is `orbit proof:run`. |

Some helpers in `fixture.py` and `gateway.php` may have had only `up.py` and `run.py` as callers. Candidates are `grants`, `leaf`, `observe` and `realtime`. Nobody has checked each one. Trim them in a separate change with their tests.

Delete each fixture with its kind, when the cases file of that kind passes. Do not delete a per-kind folder as a whole. The Deno test `task-turn/actions_test.ts` reads `swamp/proof/tasks.turn/pi_fixture.py`. The `preparing-commands` skill tells an author to read the old fixtures when porting.

Delete the reaper and the Project 62 branch when no Project 62 group is open.

## Firewall proof cases

The `firewall:allow` change owns `swamp/proof/firewall/**`, so no note is added to those files. All items here are MARK.

| Item | Replaced by |
| --- | --- |
| `cases/allow-fresh-ipv4-tcp.sh`, `cases/allow_fresh_ipv4_tcp.py` | `allow.cases.yaml` and the runner |
| `cases/listener.py` | `swamp/proof/runner/listener.py`, which also handles UDP and IPv6 |
| `allow`, `deny`, `list`, `remove` `.cases.md` | `allow.cases.yaml` and `list.cases.yaml`; `deny` and `remove` have none yet |
| `swamp/proof/firewall.*.json`, `firewall/check.py`, `firewall/peer.py` | The cases files above |

Before the programs can go, these must change:

- `swamp/proof/selection.txt` maps `swamp/proof/firewall/**` to the firewall commands.
- The `proof:run` code, tests and samples still name the `.sh` program. The samples are `RunProofCommandTest.php`, `apps/cli/tests/Expected/proof/proof-run/*`, `packages/php-sdk/fixtures/proof/proof-run/*`, the Gateway proof tests and `tools/test_ci_build_plan.py`.
- The yaml cases must pass in `proof:run` first. Then the selection result drops the programs.
- `cases/listener.py` is a smaller copy that only `allow_fresh_ipv4_tcp.py` reads. Delete it with that program.
- Only the old `firewall.*.json` fixtures call `check.py` and `peer.py`.
- `allow.cases.yaml` converts `allow.cases.md`. Convert `deny` and `remove` before you delete their tables.

## Cases tables

A cases table cannot be checked by a tool, so the cases file is now yaml ([verification cases](/reference/verification-cases#the-case-file)).

| Item | Verdict | Replaced by |
| --- | --- | --- |
| `swamp/proof/<family>/<command>.cases.md`, 78 files | MARK | `<command>.cases.yaml` |

Only `firewall:allow` and `firewall:list` have a yaml file. For every other command the table is still the only cases record. Convert a table when you work on its command, and add no rows.

The skills `developing-commands`, `preparing-commands` and `verifying-commands`, `feature-flow`, `cli-ux` and the pipeline page now name the yaml file. The chart `docs/reference/pipeline/1-prep.mmd` still says `COMMAND.cases.md`. Change it with the chart owner.

## Task VMs and images

Slot code is gone, the recipe image build is on its way out, and the e2e harness holds no snapshot code.

| Item | Verdict | Why |
| --- | --- | --- |
| Task VM slot code: enrol, claim, release, `free` state, slot columns | KEEP | Nothing is left. #2701 removed it. |
| `docs/cli/tasks/vm-slots.mmd` and "ready slot" lines in the stage 2 and 6 charts | KEEP | This is the new, simpler slot design. It is a draft target. |
| Recipe image build: `RecipeImageBuilds`, `RecipeImageBuildSteps`, `BuildTaskVmRecipeCommand`, `task-vms:build-*` | MARK | Stage 6 replaces it. Each class has a note. |
| Topology snapshot code in `apps/e2e` | KEEP | Nothing is left. Two tests check that removed commands stay removed. |

The word "slot" in `TaskVmCapacity`, `TaskVmSize`, `TaskVmResources` and `compute-drivers` means a share of a host's `max_vms`. It is not the removed concept.

Stage 6 replaces the recipe image build with a refresh of the topology seed by converge ([chart](https://github.com/hardimpactdev/orbit/blob/main/docs/reference/pipeline/6-task-image-seed-refresh.mmd)). Images are built the old way until that refresh exists.

## Not changed

`apps/agent/**` is frozen. Its image-build commands (`image_build`, `image_recipe`) belong to the recipe path and wait for the agent strip.
