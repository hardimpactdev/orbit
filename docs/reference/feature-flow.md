---
title: "Feature flow"
description: "Reference for the proof CI job and the case selection list. The stages of a command are in the command development pipeline."
---

# Feature flow

The [command development pipeline](/reference/command-development-pipeline) owns the six stages of a command change. This page covers the proof CI job and `swamp/proof/selection.txt`.

## Terms

These two terms separate Orbit-owned files from user workloads on a Node:

| Term | Meaning |
| --- | --- |
| Orbit footprint | Files and units Orbit owns: the agent, CLI, Gateway code, and rendered Orbit configs. |
| Workload state | User resources on Nodes: Instances, Processes, containers, Caddy sites, firewall rules, `.env` files, and role config. |

## The proof CI

`proof-run` in `.github/workflows/ci.yml` runs [`orbit proof:run`](/cli/proof/run) via `bin/ci-proof-run` on a self-hosted runner enrolled in the private network ([The proof run job](/reference/implementation-loop#the-proof-run-job)). When enabled (`ORBIT_PROOF_ENABLED=true`), a selected proof is part of `Required checks` and blocks merge; there is no report-only mode ([ADR 0262](/decisions/0262-prove-changed-operations-on-the-pull-request)). Execution on the Gateway is controlled separately by `proof.execution` ([the execution switch](/cli/proof/run#the-execution-switch)).

### How `proof-run` executes

The job selects and runs cases against the PR head without checking out the PR code onto the runner:

- `bin/ci-build-plan` reads `swamp/proof/selection.txt` and starts `proof-run` for any changed path that selects a command or matches no rule (docs-only changes skip it).
- `proof-run` passes the PR head SHA and a run/attempt idempotency key to `orbit proof:run --json`; Orbit fetches the commit and runs cases inside container proof copies.
- The workflow timeout is `275` minutes (`WAIT=270m` in `bin/ci-proof-run`: admission wait plus execution budget). A new push or cancelled job cancels the active proof run.
- The job summary lists cases, uncovered paths, and a private receipt link without command output or hostnames.
- A run with uncovered paths and no cases passes and states that clearly; a capacity failure or any failed/unrun selected case fails the job.
- Proof tests the PR head, not `main` after merge.

### Fork pull requests and guards

Fork PRs never run `proof-run` automatically:

- A maintainer must approve the exact head commit and add the `run-proofs` label, which triggers `proof-outside.yml` and reports the `Orbit outside proof` status.
- Pushing a new commit removes the label, cancels the prior proof, and requires fresh approval and re-labeling.
- `proof-run` only runs for same-repository PRs (`head.repo.full_name == github.repository`) on the Sabre/Shark shard runner pool (`self-hosted, hardimpact, orbit-shard`), pinned by `tools/test_ci_runner_policy.py`.
- The job passes no secrets, proof copies hold no real model keys, and Orbit refuses commits not on a repository branch.

## Choose the cases

### Instance execution coverage

CI checks **Instance execution proof coverage** for files executed by Nodes during `instance:create`, `instance:clone`, `instance:migrate`, and `instance:destroy`. While a command has no real passing Operation case (exit `0`, terminal `succeeded`, not `fails_today`), touching its execution files emits a warning. Once a command has a real case in base or head, failing to select any real case fails build-input selection.

### Selection list (`swamp/proof/selection.txt`)

`swamp/proof/selection.txt` maps changed path globs to commands. Both `bin/ci-build-plan` and Orbit read the list from the tested commit:

```text
# path glob                                           selects
docs/**                                               skip
**/*.md                                               skip
apps/web/**                                           skip
swamp/workflows/workflow-operation-firewall-direct.yaml firewall:allow firewall:deny firewall:remove
swamp/proof/firewall/allow.cases.yaml                 firewall:allow
swamp/workflows/workflow-vault-sync.yaml              none: no case yet
```

| Value | Meaning |
| --- | --- |
| `skip` | Needs no proof (docs, charts, web app). |
| `<family>:<command>` | Selects listed commands; code changes run the whole file, while cases-only edits run affected entries. |
| `*` | Runs every case (not used in the list). |
| `none: <reason>` | Listed as uncovered without failing the run. |

Each command's cases live at `swamp/proof/<family>/<command>.cases.yaml` (extra colons become `-`, such as `deploy-step-create.cases.yaml`). Missing files report `no cases file for COMMAND`; files with `cases: []` report `none: no case yet`.

Matching rules:

- Last matching line wins; documentation globs (`**/*.md`, `**/*.mdx`, `**/*.mmd`) stay at the bottom.
- Unmapped paths fail with `proof.path_unmapped`; malformed lines fail with `proof.selection_invalid`.
- Renames and deletes match both old and new paths; editing `selection.txt` alone selects nothing.
- Incomplete GitHub file lists, missing selection lists, or `--all` select every case.

### Case-level narrowing

After selecting commands, the runner narrows to affected cases:

1. Expand base and head case definitions (fixtures, vars, variants, fleet settings, batch setup).
2. Publish `proof-selection.json` bound to the exact head and merge base.
3. The Gateway validates the artifact and runs only the selected case IDs in their required batches.

Expired, mismatched, or uncertain results fall back to whole files. Command code changes, tooling/runner changes, or parse errors disable narrowing and run the whole file. Valid selection reasons are `changed`, `added`, `uses changed fixture`, `uses changed var`, `batch setup changed`, `file settings changed`, `command code changed`, `proof tooling changed`, and `selection uncertain`. Comments and formatting select nothing, and a verified zero-case selection succeeds without allocating a copy.

`fails_on_base: true` stays in case files as metadata for merge-base replay ([Opt into merge-base replay](/reference/verification-cases#opt-into-merge-base-replay)), which is currently unexecuted in container copies.
