---
title: "Command pipeline"
description: "The stages every Orbit command goes through, from preparation to a refreshed fleet, with the status of each stage and links to its charts, pages, skill and files."
---

# Command pipeline

Every command goes through these stages, one command at a time. Each stage below shows its status on `main` and links to its charts, pages, skill and key files. The [feature flow](/reference/feature-flow) holds the detail of the whole change flow.

| Stage | Status |
| --- | --- |
| [1. Prep](#1-prep) | Built |
| [2. Proof environment](#2-proof-environment) | Built |
| [3. Proof](#3-proof) | Built, one case so far |
| [4. PR with fast CI](#4-pr-with-fast-ci) | Built |
| [5. VM CI](#5-vm-ci) | Partly built |
| [6. Automatic Swamp release](#6-automatic-swamp-release) | Built |
| [7. Per-host task image and seed refresh](#7-per-host-task-image-and-seed-refresh) | Partly built |

## 1. Prep

**Built.** A command is ready when its chart, docs page and verification cases are approved and merged. Preparation changes no code.

- Skill: [preparing-commands](https://github.com/hardimpactdev/orbit/blob/main/.agents/skills/preparing-commands/SKILL.md), with [flow-tighten](https://github.com/hardimpactdev/orbit/blob/main/.agents/skills/flow-tighten/SKILL.md) for the chart.
- Chart: `docs/cli/<family>/<command>.mmd`. Rules: [CLI pages and flow charts](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/README.md).
- Docs page: `docs/cli/<family>/<command>.mdx`.
- Cases: `swamp/proof/<family>/<command>.cases.md`, for example [`firewall/allow.cases.md`](https://github.com/hardimpactdev/orbit/blob/main/swamp/proof/firewall/allow.cases.md).

## 2. Proof environment

**Built.** A task VM, started with `tasks:provision`, holds a nested topology: a Gateway with Swamp, an `app-dev` Node and an `app-prod` Node. They share a real nested WireGuard network, `10.45.0.0/24`, and the outer VM is the operator. Each task VM host keeps ready copies of this topology in its topology seed, so a reset takes about 75 seconds instead of a 230-second build.

- Charts: [`tasks:provision`](/cli/tasks/provision#how-it-works), the [proof reset](https://github.com/hardimpactdev/orbit/blob/main/swamp/proof/reset/reset.mmd), and the [seed refresh](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/tasks/vm-seed-refresh.mmd) that makes the ready copies.
- Docs: [Provision an external group](/reference/tasks#provision-an-external-group), [Refresh the topology seed](/reference/compute-drivers#refresh-the-topology-seed).
- Skill: [verifying-commands](https://github.com/hardimpactdev/orbit/blob/main/.agents/skills/verifying-commands/SKILL.md).
- Files: [`swamp/proof/README.md`](https://github.com/hardimpactdev/orbit/blob/main/swamp/proof/README.md), [`swamp/proof/reset/README.md`](https://github.com/hardimpactdev/orbit/blob/main/swamp/proof/reset/README.md), [`bin/proof-fleet`](https://github.com/hardimpactdev/orbit/blob/main/bin/proof-fleet).

## 3. Proof

**Built, with one case so far.** `bin/proof-fleet verify` runs the command's cases on that topology. Each case calls the real CLI, checks the real effect and prints a verdict. The first case is the `firewall:allow` traffic case.

- Chart: [`firewall:allow`](/cli/firewall/allow#how-it-works).
- Skill: [verifying-commands](https://github.com/hardimpactdev/orbit/blob/main/.agents/skills/verifying-commands/SKILL.md).
- Files: [`allow-fresh-ipv4-tcp.sh`](https://github.com/hardimpactdev/orbit/blob/main/swamp/proof/firewall/cases/allow-fresh-ipv4-tcp.sh), [`allow.cases.md`](https://github.com/hardimpactdev/orbit/blob/main/swamp/proof/firewall/allow.cases.md).

## 4. PR with fast CI

**Built.** The author opens the PR and turns on auto-merge as `nckrtl`. The merge queue lands it when `Required checks` pass.

- Docs: [Feature delivery](/reference/implementation-loop), [Pull request](/reference/feature-flow#3-pull-request) and [CI](/reference/feature-flow#4-ci) in the feature flow.
- Skill: [developing-commands](https://github.com/hardimpactdev/orbit/blob/main/.agents/skills/developing-commands/SKILL.md).
- Files: [`.github/workflows/ci.yml`](https://github.com/hardimpactdev/orbit/blob/main/.github/workflows/ci.yml), [`bin/ci-build-plan`](https://github.com/hardimpactdev/orbit/blob/main/bin/ci-build-plan).

## 5. VM CI

**Partly built.** `orbit proof:run <commit>` picks the cases from the diff with the selection list and runs them in a task VM.

- Built: the trust check, the case selection and the execution as a Gateway-started chain. Execution is off by default (`proof.execution`). It moves to one Swamp flow next.
- Not built: the CI job on the beast and shark self-hosted runners that calls `proof:run`, and the `Proofs` required check. Outside pull requests will run it only after review plus the `run-proofs` label.
- Chart: [`proof:run`](/cli/proof/run#how-it-works).
- Docs: [`orbit proof:run`](/cli/proof/run), [The VM job](/reference/feature-flow#the-vm-job), [Choose the cases](/reference/feature-flow#choose-the-cases).
- Skill: [verifying-commands](https://github.com/hardimpactdev/orbit/blob/main/.agents/skills/verifying-commands/SKILL.md).
- Files: [`swamp/proof/selection.txt`](https://github.com/hardimpactdev/orbit/blob/main/swamp/proof/selection.txt), [`apps/gateway/app/Domain/Proof`](https://github.com/hardimpactdev/orbit/tree/main/apps/gateway/app/Domain/Proof).

## 6. Automatic Swamp release

**Built.** The Gateway looks for the newest green commit on `main` and starts one Swamp run: build, snapshot and migrate, switch, load the new workflows and check health. A failed check switches back.

- Chart: [`gateway:release:deploy`](/cli/gateway/release-deploy#how-it-works).
- Docs: [Release as a Swamp run](/reference/gateway-recovery#release-as-a-swamp-run), [Automatic releases](/reference/gateway-recovery#automatic-releases).
- Files: [`swamp/workflows/workflow-operation-gateway-release.yaml`](https://github.com/hardimpactdev/orbit/blob/main/swamp/workflows/workflow-operation-gateway-release.yaml), [`bin/gateway-rollback`](https://github.com/hardimpactdev/orbit/blob/main/bin/gateway-rollback).

## 7. Per-host task image and seed refresh

**Partly built.** After each release, and at least daily, each task VM host refreshes its own task image and topology seed. The hosts work in parallel, and nothing is copied between them.

- Built: the image build, and the seed refresh. An operator starts a seed refresh with `php artisan task-vms:refresh-seed <host>`.
- Not built: the release trigger and the daily schedule (B8).
- Charts: [image build](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/tasks/vm-image-build.mmd), [seed refresh](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/tasks/vm-seed-refresh.mmd).
- Docs: [Build the base image](/reference/compute-drivers#build-the-base-image), [Refresh the topology seed](/reference/compute-drivers#refresh-the-topology-seed).
- Files: [`tools/task-vm-seed`](https://github.com/hardimpactdev/orbit/tree/main/tools/task-vm-seed), [`swamp/workflows/workflow-operation-task-vm-seed-refresh.yaml`](https://github.com/hardimpactdev/orbit/blob/main/swamp/workflows/workflow-operation-task-vm-seed-refresh.yaml).

## Change the pipeline

Change a stage in the place that owns it, and update this page in the same PR.

- **This page:** the stages, their order, their status and their links. Edit `docs/reference/command-pipeline.md`. A stage that gets built changes its status here.
- **The charts:** edit the `.mmd` file next to the command page, chart first. Run `bin/check-flows`. The rules are in [CLI pages and flow charts](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/README.md).
- **The skills:** `.agents/skills/preparing-commands` for stage 1, `developing-commands` for the order a command takes, and `verifying-commands` for stages 2, 3 and 5.
- **The detail of the flow:** [Feature flow](/reference/feature-flow) for the target flow after the release, and the CI job.
