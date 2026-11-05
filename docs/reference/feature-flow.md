---
title: "Feature flow"
description: "The target flow for an Orbit change, from idea to deployed."
---

This is the target flow for an Orbit change. The [command development pipeline](/reference/command-development-pipeline) lists the stages of a command with their status and links to every chart. The `developing-commands` skill (`.agents/skills/developing-commands/SKILL.md`) applies this flow to one command at a time. The `verifying-commands` skill defines the VM proof used during development and in CI.

## Words used here

These terms distinguish the task images, topology seeds, their hosts and the state on each Node.

| Term | Meaning |
| --- | --- |
| Task VM hosts | Machines that run task VMs and their nested topologies: beast, shark and host 14. |
| Task image | The VM image that a host starts its task VMs from. Each host builds its own. |
| Topology seed | Stopped copies of a ready Gateway with Swamp, `app-dev` Node and `app-prod` Node, kept by each host. A task VM resets its test fleet from them. |
| Orbit footprint | What Orbit owns on a Node: the agent, CLI, Gateway code, and the files and units Orbit renders for itself. |
| Workload state | What users run on Nodes: Instances, Processes, containers, Caddy sites, firewall rules, `.env` files and role config. |

## 1. Idea

A command change is **one PR**. The chart, the docs page, the verification cases, the command code and the runnable proof cases all land in that PR, so a reviewer can check that they match. There are no separate docs-only prep PRs.

The PR starts as a draft. Its first commit holds the spec, which is written before any code. The agent then codes against it in later commits of the same PR.

1. **Brief and acceptance criteria:** a Task group or a Task in a group.

   Include a short brief and the acceptance criteria the change must meet.

2. **The flow chart:** `docs/cli/<family>/<command>.mmd`, updated to the ideal flow, following the flow-tighten rules.
   - The main path uses only the required parameters.
   - Each optional flag gets its own `opt` block.
   - The chart is minimal and shows real steps only.
3. **The docs of the ideal state:** update `docs/cli/<family>/<command>.mdx` with the finished feature's description, usage, behavior and errors.
4. **The verification cases:** `swamp/proof/<family>/<command>.cases.md`, derived from the chart and the page.

The Orbit App, through Swamp and a Gateway-minted App token, opens the draft PR with these files as its first commit (not built yet; see [Pull request](#3-pull-request)).

- **Manual mode:** an optional spec checkpoint. Nick reviews the chart, docs and cases in the draft before code is written.
  - He may check out a development Instance on the PR branch and review there with an agent.
- **Automatic mode:** no checkpoint.

For a bug, fix the chart and the docs first, in the first commit, then the code.

The agent that takes the Task gets the updated chart, the updated docs, the cases, the brief and the acceptance criteria. The test cases in step 2.3 come from the chart, and the acceptance criteria decide when the Task is done. A merged docs PR does not make a command ready. The spec is approved in the draft.

The commands that were already prepared through docs-only PRs keep their chart, page and cases. When a command's turn comes, its PR reviews and updates them through this same flow.

## 2. Develop

Build and check the change on a proof topology before pushing it.

1. Start a task VM with `tasks:provision`. It holds the nested topology, reset quickly from the ready copies in the host's topology seed. It matches what CI and the fleet run.
2. Build the change.
   - The Gateway records intent and starts a Swamp run. Swamp executes. The agent only observes.
   - For each part of the Node work, choose native Swamp steps or an Ansible phase (flow-tighten rule 22):
     - an Ansible phase for idempotent Node state with lists, such as packages, Caddy sites, PHP-FPM pools and config files;
     - native steps for Gateway calls, single commands, work that must not repeat, and commands with a budget under about 10 seconds.
   - Each Ansible phase is one Swamp step and must be safe to rerun from the start.
### Test the chart

Use the chart to cover the command's paths.

3. Add test cases from the chart:
   - one for the main path;
   - one for each optional flag;
   - one for each error path.

   Each case asserts the command's promised effect, the recorded state and the observed Node state. Doctor reports zero drift for the records it touched, and a second run changes nothing. An error case checks that the command refuses and nothing changes.

   For example, a firewall case proves that traffic reaches a blocked port after the allow command. A restricted source case also proves that an excluded source stays blocked. A broad test-network allowance must not let either assertion pass without the tested rule. The `verifying-commands` skill owns the case contract and evidence requirements.
4. Reset the topology to the branch and run the cases there, before the push.
   - Target: Orbit's own Swamp runs the reset and verify workflows over SSH.
   - Today: `bin/proof-fleet verify` does it.

## 3. Pull request

The author prepares the one PR of the command change. The PR already exists as a draft with the spec (step 1).

1. Swamp opens the draft PR as the Orbit App, with the spec as its first commit.
2. Push the code and the runnable proof cases to the same branch. CI, with the proof CI for command code, runs on every push.
3. When everything is green, the PR leaves draft. In manual mode, Swamp also requests Nick's review, so GitHub notifies him.
4. The gate passes: Nick's approving review on the exact head in manual mode, or a separate final self-review in automatic mode. Automatic mode allows at most 3 self-review rounds. Then the PR switches to manual mode.
5. Swamp merges the PR straight into `main` with a squash merge.

The Orbit App, through a Swamp step, decides whether a PR may merge. Swamp uses an App token that the Gateway mints. The Gateway only admits the request and mints the token. There is no merge queue and no GitHub auto-merge.

CI runs on `main` after the merge. A red `main` is fixed forward and never released. The proof runs only on the PR head, never again before or after the merge.

Two PRs for the same command normally conflict, because each edits the command's chart, page and cases file. The second PR rebases. That reruns CI and the proof.

Not built yet: PRs opened by the App, and Swamp merging PRs. Until then (the interim), the author opens the draft PR as `nckrtl`, takes it out of draft and runs `gh pr merge <n> -R hardimpactdev/orbit --auto --squash`. GitHub merges the PR once the Required checks pass.

## 4. CI

CI is the only gate: if CI passes, the PR merges. CI runs on every push to the PR, also while it is a draft; the jobs follow the changed files of the whole PR. There is one CI: the fast checks plus, for command code, the proof CI. The merge requires that one result. So CI must be strict about what matters and run nothing else. The changed files decide which jobs run:

| Changed files | Jobs |
| --- | --- |
| Documentation only (`docs/`, `*.md`, charts) | Docs checks only. Never the proof CI. |
| Gateway code (`apps/gateway/`) | Fast checks and the Gateway Pest suite |
| Web or CLI code | Fast checks and that build |
| Command code: Swamp workflows, extensions, playbooks, role definitions, versions | Fast checks and the proof CI for the affected commands |

1. Fast checks run in about 2 minutes.
2. The proof CI runs only when command code changed:
   1. Orbit takes a task VM. It resets the test fleet from the ready copies in the host's topology seed and applies the PR's code, in about 75 seconds.
   2. It runs only the test cases of the affected commands, one after another in that fleet, and then releases the VM.

   Development and CI use the same cases and assertions. The required check identifies the tested commit and reports every selected case. A missing result or a failed assertion fails the check. A passing check confirms the defined cases; it does not prove behavior that the cases omit.

   This adds time only to PRs that change commands. The run budget is 30 minutes after the fleet is ready. See [the proof CI](#the-proof-ci).
3. A new push cancels the proof run of the previous push on the same PR.

   The proof CI starts only when a changed path selects a command in `swamp/proof/selection.txt`. Docs, charts and case descriptions never start it.

### The proof CI

The proof CI is the target. Built so far: the `orbit proof:run` admission (trust check and case selection), the selection list, and the execution as a Gateway-started chain that moves to one Swamp flow next. Not built yet: the receipt store, the job and the label workflow. Execution stays switched off until they are; see [the execution switch](/cli/proof/run#the-execution-switch).

The job is `Proofs`, a job of the one CI; the merge requires the one CI result. A job in `.github/workflows/ci.yml` runs [`orbit proof:run`](/cli/proof/run) on the self-hosted runners on beast and shark, waits for the verdict, and passes or fails. These are the organization's runner services. They reach the Gateway over the private network, and the job adds no runner and no access: any job on those runners already acts as the Node of its host.

**The job**

- A hosted scope step reads the changed paths with `bin/proof-select`, run from `main` and not from the pull request. A path that needs a proof starts `Proofs`.
- A path that matches no line fails the scope step. A change to documentation only never starts the proof CI.
- `Proofs` passes one commit SHA to `orbit proof:run --json`: the pull request head.
- It also passes an idempotency key built from the workflow run and its attempt. It checks out nothing and chooses no cases.
- Orbit fetches the commit and selects the cases with its own program.
**The result**

- The job summary lists each case, the uncovered paths and the receipt link. It holds no command output and no host name. The receipt link needs access to the private network.
- A run with uncovered paths and no case passes, and the summary says so. It never looks like a run that proved something.
- The job exits with the verdict. The timeout is 45 minutes: the VM wait plus the run budget. A cancelled job cancels its run.
- `Proofs` tests the pull request head, not its merge with `main`. It does not run again after the merge.

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
- The CI proof VM holds no Pi token and no model key.
- Orbit refuses a commit that is not on a branch of the repository, so a job that reaches a runner can still prove only trusted code.

**Required check**

- `Proofs` is a job of the one CI. The merge requires the one CI result, `Required checks`, which will hold it.
- Only the scope step may skip it, and only for a change that needs no proof.

### Choose the cases

The rule is built in `orbit proof:run`; the scope step that reads it in CI is not. The file `swamp/proof/selection.txt` is the selection list: it maps changed paths to commands. Each line holds a path glob and the value it selects. The scope step and Orbit read the list of the tested commit.

```text
# path glob                                   selects
docs/**                                       skip
**/*.md                                       skip
apps/web/**                                   skip
swamp/workflows/workflow-firewall-*.yaml      firewall:allow firewall:deny firewall:remove
swamp/proof/firewall/cases/**                 firewall:allow firewall:deny firewall:remove
swamp/proof/reset/**                          *
bin/proof-fleet                               *
swamp/workflows/workflow-vault-sync.yaml      none: no case yet
```

| Value | Meaning |
| --- | --- |
| `skip` | The path needs no proof: documentation, charts, case descriptions, the web app. |
| `<family>:<command>` | The cases of each listed command run. Without a case program, the command is listed as uncovered. |
| `*` | Every case runs. Use it for shared code that every command consumes. |
| `none: <reason>` | The path has no case yet. It is listed as uncovered and does not fail the run. |

The case programs of a command are `swamp/proof/<family>/cases/<command>-*.sh`. Further colons of the command name become `-`. A program whose name has no `-`, such as the harness check `swamp/proof/reset/cases/smoke.sh`, belongs to no command and never runs as a case.

- The last matching line wins.
- A path that matches no line is refused with `proof.path_unmapped`, so the author must classify every path.
- A rename or a delete counts for the old path too.
- A change to the selection list selects every case. So do a file list that GitHub returns incomplete, and `--all`. A change cannot narrow its own selection.
- List a shared workflow, extension or role with every command that consumes it. A change to it then runs the whole affected proof.
- List a helper that several commands share with every one of them.
- The PR of a command change maps the Gateway Action and CLI files of the command. Until then, they stay `skip`.
- Case programs are code and select their command. Charts, pages and `*.cases.md` files are `skip`.

A malformed line is refused with `proof.selection_invalid`. A commit without a selection list selects every case.

## 5. Merge

Swamp merges the PR into `main` with a squash merge, after the gate. The interim is `gh pr merge <n> -R hardimpactdev/orbit --auto --squash` as `nckrtl`. CI then runs on `main`; a red `main` is fixed forward.

## 6. Release

The release run builds and loads the code, then checks the Gateway.

1. A Swamp release run starts for the newest commit on main.

   Several merges can land during a release. The next run then takes only the newest commit.

2. It builds the release: Composer, the web build, the CLI.
3. It snapshots the database and runs the migrations. Migrations are expand and contract, so old code still works during the switch.
4. It switches the Gateway to the new code and loads the new workflows into Swamp. Swamp reloads; it does not restart.
5. It checks that the Gateway is healthy.

   A failed check runs `bin/gateway-rollback` to switch back without Swamp.

6. The Gateway records the release.

## 7. Task image and seed refresh

A release that changed playbooks, pins or lock files starts a refresh on each task VM host, and a daily run does too. There is no image build per release. Only Linux hosts (beast and shark) refresh.

Per host:

1. Start a refresh task VM from the current image and seed.
2. Reset the nested fleet and sync the release commit.
3. Converge once, only if playbooks or pins changed.
4. Re-mint the nested Swamp tokens.
5. Save the outer VM as the new task image and the nested VMs as new ready copies. The copies hold no key.
6. Boot-check both, then promote them.

A recipe build runs only for a new host or a new base OS ([chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/tasks/vm-image-build.mmd)). The seed refresh chart is [here](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/tasks/vm-seed-refresh.mmd).

The hosts refresh in parallel. Nothing is copied between hosts: they have different CPUs, and a copy loses the storage sharing inside the seed. From then on, task VMs and CI proofs start from the new seed. See [Refresh the topology seed](/reference/compute-drivers#refresh-the-topology-seed).

The images stay on our own machines. Nothing goes to GitHub or UpCloud.

## 8. Fleet

Each release runs `node:converge` on every Node, with flags that limit what it converges. Without flags, `node:converge` applies all recorded state; that is the manual drift fix in step 9.

On each release, the rollout converges only two things:

1. **The Orbit footprint, on every Node:** the agent, the CLI and Orbit's own files.
2. **Roles whose recorded state changed in this release:** for example, a PHP version bump runs the PHP role's playbook on the Nodes with that role. Roles that did not change are skipped.

The flag names are still open. For example: `node:converge 12 --footprint --roles=app-dev`. Following the chart rules, each flag gets its own `opt` block in the `node:converge` chart.

Nodes are converged in parallel. Open choice: dev Nodes first and then the rest, or all at once.

A release never touches workload state:
- Drift you made on purpose, such as a container you are trying out, stays as it is.
- Converge never removes what Orbit did not create. An unrecorded container is never touched.

## 9. Drift

Doctor reports drift so you can choose a fix.

1. Doctor reads recorded state and agent reports, and lists each difference as a finding.
2. Each finding offers its fix:
   - if the Node is missing something the Gateway recorded: run `node:converge`, or the command that owns that record;
   - if the Node has something the Gateway never recorded: record it with the matching command (`instance:register`, `tool:adopt`, `firewall:allow`), or remove it from the Node.
3. You choose the fix. Nothing repairs workload drift on its own.

## 10. Version updates

Version changes follow the same flow as other changes.

1. Self-hosted Renovate, running on our runners, proposes version changes as PRs to `recipe.json`, with checksums.
2. The PR goes through the same CI: the proof CI converges the new versions onto the ready copies of a topology and runs the tests of the roles it changes.
3. A green PR merges. A red PR stays open until someone excludes the broken version.
4. The release then rolls the new versions out to the Nodes with those roles (step 8.2).

Tools that a user installs on one Node with `tool:install` stay separate. They update within their own version range, outside this flow.

## 11. Nightly

The nightly build checks that the topology seed matches a fresh installation.

1. A schedule creates a Task in a task VM.
2. It builds the topology from stock Ubuntu up to the running release, and runs the full test suite.
3. It compares the result with the converged seed through Doctor. A difference means converge left something behind.
4. If it passes, the fresh build replaces the seed.
5. If it fails, the current seed stays, and a Pi agent starts in that VM:
   - an Orbit bug: Pi fixes the code, proves it, and opens a PR;
   - an upstream break: Pi pins the last working version and opens a PR with the reason;
   - an infrastructure problem: Pi reruns once, then reports.

## First complete command: firewall:allow

`firewall:allow` is the first command to complete this whole flow. The existing `node:role:add` work continues through its conversion, shared Ansible setup and proofs. That work is reused where needed; it does not own the new CI, per-host refresh or scoped rollout work. Firewall uses native Swamp steps, so its preparation can proceed while the role conversion finishes.

Build the missing parts in dependency order. This order prepares the development pipeline; a release still follows steps 6 through 9 above.

1. **Agree the command contract and cases.** Align the documented contract with the implementation. Extend the existing harness with chart-path cases. Verify the CLI output and intended Node state, including Doctor and unchanged reruns.
2. **Establish the development environment.** Verify that the topology seed of a host holds the ready Gateway with Swamp, app-dev and app-prod copies. Record their release identity. Development and CI reset from the same copies.
3. **Connect the cases to CI.** Run affected cases on a topology reset to the tested commit. A change to documentation skips VM tests. A failed case turns the CI result red.
4. **Verify the Swamp release.** Follow the merged commit into the running Gateway and loaded workflows. A manual proof receipt does not establish that the automatic release works.
5. **Complete the per-host refresh.** Each host refreshes its task image and seed after a release (step 7). A failed refresh keeps the previous seed. Verify that the next development or CI reset uses the new release.
6. **Complete scoped fleet convergence.** Apply the release scope from step 8. Compare Doctor with the pre-release baseline. Record evidence and update the skills before the next full command pass.

These stages can ship in focused PRs, but `firewall:allow` is complete only after all of them work. Manual CI runs, a manual seed refresh or a Doctor check on just one Node do not replace the automatic stages or fleet verification. Renovate and the nightly fresh build follow after this first complete path works.

## What is built today

The [command development pipeline](/reference/command-development-pipeline) shows the status of the stages from preparation to the per-host refresh. This table covers the rest of the flow.

| Step | State |
| --- | --- |
| 2. Ansible phases | First command (`node:role:add`) in progress |
| 8. Rollout of the Orbit footprint | Built (`fleet.rollout`) |
| 8. Rollout of changed roles | Not built |
| 9. Doctor findings with a fix per finding | Doctor built; fix per finding not built |
| 10. Renovate | Designed, not built |
| 11. Nightly fresh build and Pi | Designed, not built |

## Open points

These choices remain open.

- Parallel rollout: dev Nodes first, or all Nodes at once.
- The `node:converge` flag names for the release scope (footprint, changed roles).
- The first content of `swamp/proof/selection.txt`: which Gateway and CLI files map to which command.
- How Pi's PRs merge: check that Swamp can merge them with an App token after task-VM development restarts.
- Speed of nested VMs inside a task VM, for the nightly build.
- The agent becomes an observer only after the task image build stops using the agent and the remaining Gateway calls to agent commands are gone.
