---
title: "Feature flow"
description: "The target flow for an Orbit change, from idea to deployed, and what is built today."
---

This is the target flow for an Orbit change, agreed with Nick on 2026-11-04. The `developing-commands` skill (`.agents/skills/developing-commands/SKILL.md`) applies it to one command at a time. The `verifying-commands` skill defines the VM proof used during development and in CI. The last section shows what is built today.

## Words used here

These terms distinguish the shared templates, their hosts and the state on each Node.

| Term | Meaning |
| --- | --- |
| Templates | VM snapshots of a Gateway with Swamp, an `app-dev` Node and an `app-prod` Node. Dev VMs, CI and the task VM image all start from them. |
| Task VM hosts | Machines that run task VMs and their nested topologies: beast, shark and host 14. |
| Orbit footprint | What Orbit owns on a Node: the agent, CLI, Gateway code, and the files and units Orbit renders for itself. |
| Workload state | What users run on Nodes: Instances, Processes, containers, Caddy sites, firewall rules, `.env` files and role config. |

## 1. Idea

Before an agent writes any code, three things are in place and agreed. The agent then codes against them.

1. **Brief and acceptance criteria:** a Task group or a Task in a group.

   Include a short brief and the acceptance criteria the change must meet.

2. **The flow chart:** `docs/cli/<family>/<command>.mmd`, updated to the ideal flow, following the flow-tighten rules.
   - The main path uses only the required parameters.
   - Each optional flag gets its own `opt` block.
   - The chart is minimal and shows real steps only.
3. **The docs of the ideal state:** update `docs/cli/<family>/<command>.mdx` with the finished feature's description, usage, behavior and errors.

For a bug, fix the chart and the docs first, then the code.

The agent that takes the Task gets the updated chart, the updated docs, the brief and the acceptance criteria. The test cases in step 2.3 come from the chart, and the acceptance criteria decide when the Task is done.

## 2. Develop

Build and check the change in a dev topology before pushing it.

1. Start a dev VM from the templates, with its nested topology. It matches what CI and the fleet run.
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
4. Converge the branch onto the dev topology and run the cases there. The change works there before it is pushed.

## 3. Pull request

The author prepares the PR for the merge queue.

1. Push the branch and open the PR.
2. The author turns on auto-merge as `nckrtl` with `gh pr merge <n> --auto`, so the PR joins the merge queue once CI passes.

## 4. CI

CI is the only gate: if CI passes, the PR merges. So CI must be strict about what matters and run nothing else. The changed files decide which jobs run:

| Changed files | Jobs |
| --- | --- |
| Documentation only (`docs/`, `*.md`, charts) | Docs checks only. Never a VM job. |
| Gateway code (`apps/gateway/`) | Fast checks and the Gateway Pest suite |
| Web or CLI code | Fast checks and that build |
| Command code: Swamp workflows, extensions, playbooks, role definitions, versions | Fast checks and the VM job for the affected commands |

1. Fast checks run in about 1 minute.
2. The VM job runs only when command code changed:
   1. A task VM host starts a copy of the templates, in seconds.
   2. It converges the PR's code onto that copy, in about 2 to 3 minutes.
   3. It runs only the test cases of the affected commands, in parallel, each on its own copy.

   Development and CI use the same cases and assertions. The required check identifies the tested commit and reports every selected case. A missing result or a failed assertion fails the check. A passing check confirms the defined cases; it does not prove behavior that the cases omit.

   This adds about 5 to 8 minutes, and only to PRs that change commands.
3. The merge queue applies the same selection rule.

   A batch needs the VM job only for PRs that change command code.

## 5. Merge

The merge queue lands the PR on main.

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

## 7. Templates

Refresh the shared templates after the release.

1. Converge the new release onto the templates and snapshot them.
2. Export the templates as image files. Copy them to the other task VM hosts with `scp` and import them there.

   Earlier image work found `scp` much faster than `incus copy`.

3. From then on, dev VMs, CI and new task VMs start from the new templates.

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
2. The PR goes through the same CI: the VM job converges the new versions onto a template copy and runs the tests of the roles it changes.
3. A green PR merges. A red PR stays open until someone excludes the broken version.
4. The release then rolls the new versions out to the Nodes with those roles (step 8.2).

Tools that a user installs on one Node with `tool:install` stay separate. They update within their own version range, outside this flow.

## 11. Nightly

The nightly build checks that templates match a fresh installation.

1. A schedule creates a Task in a task VM.
2. It builds the topology from stock Ubuntu up to the running release, and runs the full test suite.
3. It compares the result with the converged templates through Doctor. A difference means converge left something behind.
4. If it passes, the fresh build replaces the templates.
5. If it fails, the current templates stay, and a Pi agent starts in that VM:
   - an Orbit bug: Pi fixes the code, proves it, and opens a PR;
   - an upstream break: Pi pins the last working version and opens a PR with the reason;
   - an infrastructure problem: Pi reruns once, then reports.

## First complete command: firewall:allow

`firewall:allow` is the first command to complete this whole flow. The existing `node:role:add` work continues through its conversion, shared Ansible setup and proofs. That work is reused where needed; it does not own the new CI, template or scoped rollout work. Firewall uses native Swamp steps, so its preparation can proceed while the role conversion finishes.

Build the missing parts in dependency order. This order prepares the pipeline; a release still follows steps 6 through 9 above.

1. **Agree the command contract and cases.** Align the documented contract with the implementation. Extend the existing harness with chart-path cases. Verify the CLI output and intended Node state, including Doctor and unchanged reruns.
2. **Establish the templates.** Verify the Gateway with Swamp, app-dev and app-prod templates. Record their release and snapshot identities. Development and CI clone the same set.
3. **Connect the cases to CI.** Run affected cases on clones converged to the tested commit. Changes only to documentation skip VM tests. Prove that a failed case blocks the merge queue.
4. **Verify the Swamp release.** Follow the merged commit into the running Gateway and loaded workflows. A manual proof receipt does not establish that the automatic release works.
5. **Complete template refresh.** Refresh and distribute verified snapshots as described in step 7. Keep previous templates on failure. Verify that the next development or CI clone uses the new release.
6. **Complete scoped fleet convergence.** Apply the release scope from step 8. Compare Doctor with the pre-release baseline. Record evidence and update the skills before the next full command pass.

These stages can ship in focused PRs, but `firewall:allow` is complete only after all of them work. Manual CI runs, a manual template update or a Doctor check on just one Node do not replace the automatic stages or fleet verification. Renovate and the nightly fresh build follow after this first complete path works.

## What is built today

This table separates the parts already built from the work still planned.

| Step | State |
| --- | --- |
| 1. Charts and flow-tighten rules | Built |
| 2. Ansible phases | First command (`node:role:add`) in progress |
| 2. Dev VM from templates | Not built: dev VMs use today's task VM image |
| 3. Auto-merge by the author | Built |
| 4. Fast CI and merge queue | Built (about 1 minute) |
| 4. VM job in CI | Not built |
| 6. Release as a Swamp workflow | R1 built, manual only; R1b, R2 (automatic) and R3 (delete the old path) to do |
| 7. Templates | Designed (ADR 0247), not built |
| 8. Rollout of the Orbit footprint | Built (`fleet.rollout`) |
| 8. Rollout of changed roles | Not built |
| 9. Doctor findings with a fix per finding | Doctor built; fix per finding not built |
| 10. Renovate | Designed, not built |
| 11. Nightly fresh build and Pi | Designed, not built |

## Open points

These choices remain open.

- Parallel rollout: dev Nodes first, or all Nodes at once.
- The `node:converge` flag names for the release scope (footprint, changed roles).
- The exact path rules that select CI jobs, and how a changed file maps to the affected commands.
- How Pi's PRs merge: check App-token auto-merge through the queue after task-VM development restarts.
- Speed of nested VMs inside a task VM, for the nightly build.
- The agent becomes an observer only after templates replace the image build, R3 lands and the remaining Gateway calls to agent commands are gone.
