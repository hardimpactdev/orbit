---
title: "Feature flow"
description: "Reference for the proof CI job and the case selection list. The stages of a command are in the command development pipeline."
---

# Feature flow

The stages a command goes through, from prep to a refreshed fleet, are in the [command development pipeline](/reference/command-development-pipeline). Its stage charts in `reference/pipeline/` own the flow. This page holds the reference that the charts do not: the terms, the proof CI job and the case selection list.

## Words used here

These terms distinguish the task images, topology seeds, their hosts and the state on each Node.

| Term | Meaning |
| --- | --- |
| Task VM hosts | Machines that run task VMs and their nested topologies: beast, shark and host 14. |
| Task image | The VM image that a host starts its task VMs from. Each host builds its own. |
| Topology seed | Stopped copies of a ready Gateway with Swamp, `app-dev` Node and `app-prod` Node, kept by each host. A task VM resets its test fleet from them. |
| Orbit footprint | What Orbit owns on a Node: the agent, CLI, Gateway code, and the files and units Orbit renders for itself. |
| Workload state | What users run on Nodes: Instances, Processes, containers, Caddy sites, firewall rules, `.env` files and role config. |

## The proof CI

The job `proof-run` is part of `Required checks`, so a red or missing proof blocks the merge. Not built yet: the receipt store and the label workflow. Execution is off by default; see [the execution switch](/cli/proof/run#the-execution-switch). Switch it on before the job is required, or every pull request that selects a command fails with `proof.execution_disabled`.

The job is `proof-run` (`Proof run`), a job of the one CI. It is part of the one CI result that the merge requires. A change that selects no command, a push and a pull request from outside do not need it. See [the proof run job](/reference/implementation-loop#the-proof-run-job).

A job in `.github/workflows/ci.yml` runs [`orbit proof:run`](/cli/proof/run) on a self-hosted runner, waits for the verdict, and passes or fails. The runner reaches the Gateway over the private network as an enrolled Node. The job adds no runner and no access.

**The job**

- The `changes` job reads the changed paths with `bin/ci-build-plan`, which reads `swamp/proof/selection.txt`. A path that needs a proof starts `proof-run`.
- A path that matches no line also starts the job, and `proof:run` refuses it. A change to documentation only never starts the proof CI.
- `proof-run` passes one commit SHA to `orbit proof:run --json`: the pull request head.
- It also passes an idempotency key built from the workflow run and its attempt. It checks out only its own script and chooses no cases.
- Orbit fetches the commit and selects the cases with its own program.
**The result**

- The job summary lists each case, the uncovered paths and the receipt link. It holds no command output and no host name. The receipt link needs access to the private network.
- A run with uncovered paths and no case passes, and the summary says so. It never looks like a run that proved something.
- The job exits with the verdict. The timeout is 45 minutes, the CI cap on one proof run. A cancelled job cancels its run.
- `Proof run` tests the pull request head, not its merge with `main`. It does not run again after the merge.

**Triggers**

- A pull request from a branch of `hardimpactdev/orbit` runs the job automatically.
- A pull request from a fork starts no proof by itself.

**Outside pull requests**

- A maintainer approves the pull request and adds the label `run-proofs`.
- The workflow `proofs-outside.yml` passes the head SHA from the label event to `orbit proof:run`. It reports the verdict as the commit status `Proofs`, then runs the failed `Proofs` job of the pull request again.
- Orbit accepts only the current head, with the label and a maintainer's approval of exactly that commit.
- A new push removes the label. The new head needs a new approval and the label again.
- In `ci.yml`, `Proofs` runs on a hosted runner for such a pull request. It fails until the head has that passing status.

**Guards**

- The runner group of beast and shark serves only this repository.
- A GitHub setting requires a maintainer's approval for every workflow run of an outside collaborator. A maintainer does not approve a run whose diff touches `.github/`.
- The workflow condition picks a self-hosted runner for a pull request from this repository. It picks a hosted runner for every other pull request.
- `tools/test_ci_runner_policy.py` pins the condition. A fork controls its own copy of the workflow, so the approval is the gate and the condition is the second layer.
- The job passes no secret.
- The proof run holds no Pi token and no model key.
- Orbit refuses a commit that is not on a branch of the repository, so a job that reaches a runner can still prove only trusted code.

**Required check**

- `proof-run` is in `Required checks`. It is required only for a pull request from this repository that selects a command.
- Only the scope step may skip it, and only for a change that needs no proof.

## Choose the cases

The file `swamp/proof/selection.txt` is the selection list: it maps changed paths to commands. Each line holds a path glob and the value it selects. `bin/ci-build-plan` and Orbit read the list of the tested commit.

```text
# path glob                                   selects
docs/**                                       skip
**/*.md                                       skip
apps/web/**                                   skip
swamp/workflows/workflow-firewall-*.yaml      firewall:allow firewall:deny firewall:remove
swamp/proof/firewall/allow.cases.yaml         firewall:allow
swamp/workflows/workflow-vault-sync.yaml      none: no case yet
```

| Value | Meaning |
| --- | --- |
| `skip` | The path needs no proof: documentation, charts, case descriptions, the web app. |
| `<family>:<command>` | The cases file of each listed command runs. Without a cases file, the command is listed as uncovered. |
| `*` | Every case runs. Use it for shared code that every command consumes. |
| `none: <reason>` | The path has no case yet. It is listed as uncovered and does not fail the run. |

The cases file of a command is `swamp/proof/<family>/<command>.cases.yaml`. Further colons of the command name become `-`, so `instance:deploy-step:create` has `swamp/proof/instance/deploy-step-create.cases.yaml`. A command without that file is listed as uncovered with the reason `no cases file for COMMAND`. When every case runs, each cases file of the commit is one command, labelled by its family and file name.

- The last matching line wins.
- A path that matches no line is refused with `proof.path_unmapped`, so the author must classify every path.
- A rename or a delete counts for the old path too.
- A change to the selection list selects every case. So do a file list that GitHub returns incomplete, and `--all`. A change cannot narrow its own selection.
- List a shared workflow, extension or role with every command that consumes it. A change to it then runs the whole affected proof.
- List a helper that several commands share with every one of them.
- The PR of a command change maps the Gateway Action and CLI files of the command. Until then, they stay `skip`.
- A cases file selects its own command. Charts and pages are `skip`.

A malformed line is refused with `proof.selection_invalid`. A commit without a selection list selects every case.
