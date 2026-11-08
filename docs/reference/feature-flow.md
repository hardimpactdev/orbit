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

The job `proof-run` is built but report-only. It is outside `Required checks`. One firewall proof has passed end to end; complete coverage and reliable admission still need review. Nick decides when proof becomes required. The outside-PR label workflow is built. Execution is on by default (`proof.execution`); see [the execution switch](/cli/proof/run#the-execution-switch).

The job is `proof-run` (`Proof request and report (optional)`), followed by `Proof assertions (optional)`. The merge does not wait for either job. See [the proof run job](/reference/implementation-loop#the-proof-run-job).

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
- A report-only admission refusal with `tasks.capacity_unavailable`, no case rows and a known command selection records **not run: capacity unavailable**.
- The request report succeeds and the assertion check skips. No proof passes.
- Its artifact receipt names the commit, operation, selected commands, outcome and idempotency key. It excludes execution input and host details.
- Other errors and failed assertions stay red. The job exits with the verdict. The timeout is 45 minutes: the VM wait plus the run budget. A cancelled job cancels its run.
- `Proofs` tests the pull request head, not its merge with `main`. It does not run again after the merge.

**Triggers**

- A pull request from a branch of `hardimpactdev/orbit` runs the job automatically.
- A pull request from a fork starts no proof by itself.

**Outside pull requests**

- A maintainer approves the pull request and adds the label `run-proofs`.
- The label starts the workflow `proof-outside.yml`, which passes the head SHA from the label event to `orbit proof:run`. The verdict is the result of that workflow run.
- Orbit accepts only the current head, with the label and a maintainer's approval of exactly that commit.
- A new push removes the label and cancels the proof of the old head. The new head needs a new approval and the label again.
- A label added before the approval fails. Remove the label and add it again after approving.
- `ci.yml` starts no proof for such a pull request, and `Required checks` does not wait for the proof.

**Guards**

- The runner group of beast and shark serves only this repository.
- A GitHub setting requires a maintainer's approval for every workflow run of an outside collaborator. A maintainer does not approve a run whose diff touches `.github/`.
- The workflow condition picks a self-hosted runner for a pull request from this repository. It picks a hosted runner for every other pull request.
- `tools/test_ci_runner_policy.py` pins the condition. A fork controls its own copy of the workflow, so the approval is the gate and the condition is the second layer.
- The job passes no secret.
- The CI proof VM holds no Pi token and no real model key. The fixed model key it holds is a placeholder that only the model router guard accepts, from the task VM network.
- Orbit refuses a commit that is not on a branch of the repository, so a job that reaches a runner can still prove only trusted code.

**Required check**

- Proof remains report-only and outside `Required checks`. One passing family does not establish complete coverage or reliable admission.
- Nick decides the graduation rule before proof becomes required.
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
| `<family>:<command>` | Select each listed command. Code changes run its whole cases file; cases-only changes run the affected entries. Without a cases file, the command is listed as uncovered. |
| `*` | Every case runs. The list does not use it: a proof runs only when a command's own code or cases change. |
| `none: <reason>` | The path has no case yet. It is listed as uncovered and does not fail the run. |

The cases file of a command is `swamp/proof/<family>/<command>.cases.yaml`. Further colons of the command name become `-`, so `instance:deploy-step:create` has `swamp/proof/instance/deploy-step-create.cases.yaml`. A command without that file is listed as uncovered with the reason `no cases file for COMMAND`. When every case runs, each cases file of the commit is one command, labelled by its family and file name.

- The last matching line wins.
- A path that matches no line is refused with `proof.path_unmapped`, so the author must classify every path.
- A rename or a delete counts for the old path too.
- A change to the selection list selects nothing by itself.
- A file list that GitHub returns incomplete selects every case, and so does `--all`.
- A cases file selects its own command. Charts and pages are `skip`.

After choosing commands, choose their cases:

1. Expand base and head definitions with the runner. Include fixtures, list vars, variants, fleet settings and batch setup.
2. Publish the runner selection as `proof-selection.json`. Bind it to the exact head and merge base.
3. Read the runner result in the Gateway. Validate its identity and selected case ids.
4. Record selected case ids and reasons. Keep each case in its batch with its required setup.

The runner compares canonical definitions by id. A command code change selects the whole file. Changes to the runner, selection code or selection list disable narrowing for every selected command. These tooling changes alone select no commands. Any parse error or unknown construct, including an unknown fixture key, also selects the whole file. The artifact records every considered file, including files with zero selected cases. The Gateway does not compare YAML definitions. Expired or mismatched results select whole files.

Selection reasons are `changed`, `added`, `uses changed fixture`, `uses changed var`, `batch setup changed`, `file settings changed`, `command code changed`, `proof tooling changed` or `selection uncertain`. A changed case includes all its variants. Deleted cases select nothing. A verified zero-case selection succeeds without a VM.

Comments, formatting and uncovered descriptions select nothing. The Gateway and `bin/ci-build-plan` consume one runner result, checked with shared fixtures. Case order can change fleet placement; when it does, the affected cases run.

The PR that adds the first cases file of a command family maps the files of the family. A family block lists every command of the family. The Node family selects `node:list` and reports the remaining Node commands as uncovered until they have cases. It holds the Gateway Action, Request, Data, Domain, Infrastructure, Model, migration, route and controller files, and the CLI and SDK files. Until the first cases file exists, these files stay `skip`.

Code that every command runs through, and the proof tooling itself, selects `none:` with a reason: a proof runs only when a command's own code or cases change, and the next such change proves the shared code too. The documentation skips (`**/*.md`, `**/*.mdx`, `**/*.mmd`) come last in the list. A README inside a directory that selects commands therefore stays `skip`.

A malformed line is refused with `proof.selection_invalid`. A commit without a selection list selects every case.
