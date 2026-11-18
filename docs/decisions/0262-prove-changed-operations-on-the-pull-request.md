---
title: "ADR 0262: Prove changed operations on the pull request"
sidebarTitle: "0262 Proof on the pull request"
description: "In progress. A check runs only when its inputs change, on the pull request. The proof of each changed operation blocks the merge and replaces the shape-gate fixtures. The after-merge consumers and the nightly sweep are removed."
---

# ADR 0262: Prove changed operations on the pull request

A check runs when its inputs change, and only then, on the pull request. The quick checks stay as they are. The [proof](/cli/proof/run) of every operation that a change touches runs on the same pull request and blocks the merge. It replaces the shape-gate fixtures, which test the same automation a second time against stand-ins. The after-merge consumers and the nightly sweep are removed.

## Status

In progress.

Steps 1 and 2 of the [build order](#build-order) are in work.

Decided by the owner on 2026-11-15.

Principle: [One way, one name](/mission#principles): each operation has one test, its proof. It also serves [Lean](/mission#principles): Orbit deletes the after-merge consumers, the full set and the fixtures that nothing reads.

Open:

- Whether a fixture that tests pure code, with no playbook, workflow or Node behind it, stays as a quick check or is removed. Decide per file when the fixtures are retired (step 3 of the [build order](#build-order)).
- Whether `Manual copy proof`, an operator's on-demand proof of one commit, stays.

## Context

Orbit's CI has three stages:

- **Pull request checks**: quick checks (Pest, PHPStan, builds, docs, Swamp shape and Deno tests) and fixture shards for the changed files, selected by their inputs.
- **Release** on main: the same checks again, unless the merged tree matches a green pull request tree, then the CLI release and the docs site.
- **After-merge consumers** (the Main full set): `Main deferred fixtures` runs the fixtures that pull requests skip, on every push and every 10 minutes, and sweeps the whole set every night; `Main full set` runs the same sweep on demand; `Main copy proof` runs a report-only proof of the newest main commit. Their shards queue for the self-hosted runners they are pinned to. Nothing waits for their result, so a failure surfaces after the merge, when main is already red.

The shape-gate fixtures are 179 test files under `swamp/tests/`, `swamp/bin/` and `swamp/extensions/`, registered in `.github/ci/fixtures/`. Most run a shipped playbook or workflow on the CI runner against stand-ins: this machine as the Node, a stand-in Incus, no network. They declare about 6.6 hours of single-worker time; `ansible_instance_migrate_test.ts` alone declares 2.3 hours. The [proof](/cli/proof/run) runs the same operations end to end, through the Gateway and Swamp, against a nested fleet. The two layers test the same behavior twice.

The after-merge stage exists for two reasons: 18 fixture files are too slow for the pull request budget, and an input list may miss a file, so a nightly run of everything guards against it. Both are answered better at their root: slow work is sharded over more capacity ([ADR 0260](/decisions/0260-share-ci-places-between-runners-and-proof-copies)), and a missing input is a bug in the input list.

## Decision

Orbit's CI has two stages: checks on the pull request, then the release on main. A pull request runs the checks its change selects, and the proof is the test of each changed operation.

### A check runs when its inputs change

Each check declares its inputs. A pull request runs exactly the checks whose inputs it changes. A check that misses an input is fixed by correcting its inputs; Orbit adds no rerun of everything to catch it.

There are no after-merge consumers and no nightly sweep. Main builds and releases.

### Main checks a merged tree that differs

When the merged tree differs from the tree that the pull request's green run checked out, main runs its selected quick checks again on the merged tree, as the CLI release already requires ([Reuse a green PR tree on main](/reference/implementation-loop#reuse-a-green-pr-tree-on-main)). When the trees match, main reuses the pull request's result. Main runs no proof: a green pull request merges without a second proof run.

### Proof is the test of an operation

When a change touches an operation's code, its proof cases run on the pull request: `proof:run` selects the changed cases, groups them into batches, and balances the batches over copies, as [proof:run](/cli/proof/run) describes. The proof is part of `Required checks` and blocks the merge. No switch makes it report-only: the only proof switch, `ORBIT_PROOF_ENABLED`, decides whether CI runs proof at all.

The shape-gate fixtures are retired. For each fixture file, a case that a proof case does not already cover becomes a proof case of its operation when it is reasonably expected to happen in normal use. A case for a rare situation is not kept: the operation is designed so that the situation cannot occur.

The Swamp shape gate's linters and validators stay quick checks. They test the source, not an operation's behavior.

### Exception: the code that makes the proof copy

No proof can cover the code that makes, starts, resets or removes the proof copy and its nested fleet. The proof runs inside that copy, and the released code makes the copy, not the candidate. So the fixture cases of this code stay quick checks with stand-ins. Decided by the owner on 2026-11-16.

The exception covers exactly this code:

- The nested fleet in an Instance container: `swamp/ansible/instance_nested.py`, `swamp/ansible/instance_nested_club_key.sh`, `swamp/ansible/instance_fleet_shrink.py`, `swamp/ansible/fleet-converge-inputs.json`, `swamp/ansible/tasks/instance-nested-club-key.yml` and `swamp/ansible/tasks/instance-fleet*.yml`.
- The nested-fleet preparation of a switched-on Instance host in `swamp/ansible/tasks/instance-host.yml`: the subordinate ID check before nesting, the nested project and profile, and the nested-fleet image build with the record of its failure.
- The nested-fleet branch of `swamp/ansible/instance-container.yml` and `swamp/ansible/instance-container-destroy.yml`: the start gate, the inner volume and its seed pair, the Club key, the fleet step and the release of the CI place (`swamp/ansible/tasks/task-copy-base-names.yml`). This branch only reads the nested project, profile and image record that the host preparation makes.
- The nested-fleet sleep and wake: the nested branch of `swamp/ansible/tasks/instance-container-power.yml`, which keeps a nested-fleet container running when its memory stays in RAM; the nested project and the inner cgroups in `swamp/ansible/files/instance-container-power.py`; and the nested-fleet refusal of `swamp/ansible/files/instance-container-wake.sh`.
- The proof default and its seed: `swamp/ansible/tasks/instance-proof-checkout.yml`, `swamp/ansible/proof-seed-copy.yml`, `swamp/ansible/tasks/proof-seed-*.yml` and `swamp/workflows/workflow-operation-instance-seed-copy.yaml`.
- The proof copy and its fleet: the fleet step of `swamp/workflows/workflow-operation-instance-start.yaml`, `swamp/workflows/workflow-operation-proof-run-copy.yaml`, `swamp/workflows/workflow-proof-copy-fleet-reset.yaml`, `swamp/workflows/workflow-proof-fleet-*.yaml`, `swamp/ansible/proof-gateway.yml`, `swamp/ansible/proof-observers.yml`, `swamp/ansible/proof-observer-facts.php` and `swamp/ansible/tasks/proof-gateway-release.yml`.
- The task host and its proof fleet: `swamp/ansible/task-vm-*.yml`, `swamp/ansible/tasks/task-vm-*.yml`, `swamp/ansible/tasks/task-container-host-*.yml`, `swamp/ansible/task-host-firewall-remove.yml` and `swamp/ansible/tasks/task-host-firewall-remove.yml`. An Instance host runs the `task-container-host-*` tasks too; its use of them follows the rule above.

A fixture case that tests only this code stays when it is reasonably expected in normal use. A case for a rare situation of this code follows the rare-case rule above too: it is designed away or dropped. A fixture case that tests other code follows the rule above. When a file holds both kinds, only its other cases move. The [fixture retirement](/reference/fixture-retirement) pages name the cases that stay.

### When a family retires

A family's fixtures retire only when the proof fleet hosts Instances cheaply, so that the proof of a pull request fits in the five minutes it may take ([Implementation loop](/reference/implementation-loop)). Decided by the owner on 2026-11-16. Until then, the fixtures stay.

A pull request's proof fits only when its slowest batch fits. For the Instance cases, that needs all of these together:

- The base snapshot of the copy holds the inner Incus, the prepared Instance image and a `proof-web` default with its seed. A batch then makes only clones in its setup.
- A batch holds about 4 deployment cases, or about 6 clone, sleep or wake cases.
- A copy has a second Instance host, so two writers run at the same time.
- The selection is per phase: a change to one phase of the container playbook selects only the cases of that phase.
- Each batch of the selection gets one free proof place.

Then a full selection is about eight batches of under five minutes each, and needs about eight free places. A narrow selection needs one or two. [Fixture retirement: instance containers](/reference/fixture-retirement/instance-container#the-five-minute-math) has the detail.

### Quick checks stay where they run

The quick checks of a public repository run on GitHub-hosted runners, except fixture shards on repository branches and main: these share the nine general runners on Sabre and Shark. Ordinary fixtures from fork pull requests stay on GitHub-hosted runners. Private repositories run only on Orbit's runners. Code from a fork never runs on Orbit's runners; its proof follows the existing outside-proof rule.

### Build order

1. In work. Make the proof blocking again while `ORBIT_PROOF_ENABLED` is `true`, with its cases selected per changed operation, and remove the report-only switches `ORBIT_PROOF_BLOCKING` and `ORBIT_SLOW_CHECKS_BLOCKING`. This needs proof capacity on more than one Node ([ADR 0260](/decisions/0260-share-ci-places-between-runners-and-proof-copies)).
2. In work. Remove the after-merge consumers and the full set: `Main deferred fixtures`, `Main full set`, `Main copy proof`, the answer collector, the main queue and the failure sweep, with their scripts, tests, repository variables and the Main full set page. The 23 fixture files that the registry kept out of pull requests (`after_merge`, `after_merge_cases`) become pull request units, selected by their inputs, so no coverage is dropped; the two registry keys go. They run only on the self-hosted CI pool, in their own shard jobs, and only for a pull request from a branch of the repository itself; a fork pull request that selects them fails `Required checks` and never reaches Orbit's runners. Selected work that does not fit the shards fails `Required checks`; nothing is deferred. Every unit fits a 150-second lane and every job ends within five minutes, so the migration fixture is split by phase. A change to the shared fixture machinery (fixture list, loader, runner, budgets, the shape gate and the Ansible toolchain pins) selects the fixtures whose own code it touches plus a small declared machinery canary set that fits one pull request's budget, not every fixture; the machinery's pure-code tests cover the rest. The canary set holds one small Ansible fixture per family (container, host, node, migrate and worker), so a toolchain change still runs Ansible in every family. Each fixture file declares as inputs only the task and playbook files its cases run, so a change to one shared task selects only the files that run it. A file that still cannot fit the ten self-hosted shards when selected is a named capacity gap, listed in `capacity_gap` in `checks.toml`: its units that fit run, and the plan names the rest as not run, with the reason; this is not a merge blocker. Overflow of any other file fails `Required checks`. The gaps are:
   - `swamp/tests/ansible_instance_migrate_move_test.ts`: the full move. Its units need more lanes than the self-hosted shards hold.
   - `swamp/tests/ansible_instance_migrate_preflight_test.ts`: the phases short of a full move. A change to a shared container task also selects the host files, and together they need more lanes than the shards hold. On the self-hosted pool, these cases cost more than the 150-second lane: the forced files, processes and seed failures after quiesce, a moved Process executable whose runtime is removed, a copied tracked-file mismatch, and a database server that the container cannot reach.
   - `swamp/tests/ansible_instance_container_test.ts`: its units need more lanes than the shards hold, and three cases are above the 150-second lane: each runs two or more converges that need a saved state boundary before they can split.
   - `swamp/tests/ansible_instance_host_test.ts`: the full host role runs in two cases: a cold build and a repeat that must change nothing. Both passed the local unit ceiling. The estimated cost of the cold build still exceeds the 150-second lane. The repeat fits, along with the checks for a bridge upgrade and a CI Node. When shared files change, the plan still leaves selected units unrun.
   - `swamp/tests/ansible_wireguard_hub_lan_rules_test.py`: the file ran past the unit ceiling on the self-hosted pool. A Python file runs whole, so it needs a split by case first.

   A self-hosted shard runs two units at once (`self_hosted_workers`), not four: the runner container shares its Node's memory with proof copies, and four Ansible units at once made it reclaim memory until the heaviest units ran past the unit ceiling. When a run on the self-hosted pool measured a case, the case costs its pool time. A case in a unit that a pool run planned but did not finish costs its hosted cost scaled by its file's pool ratio: the median ratio of pool time to declared cost of the file's measured units, or of all measured units when the file has none. When the unit ceiling cut that case, it costs the larger of its cut time and the scaled cost. A file or case that no pool run has reached keeps its hosted cost until a pool run measures it. Each registry names the source of each cost.

   Every phase of a move runs the shared task files `instance-migrate-phase.yml` and `instance-container-phase.yml`, so inputs per task file cannot narrow the move files further. The split by phase shrinks each gap; a file leaves the list when every selection of it fits. The CI runner setup supplies the tools these files need ([Self-hosted fixture shards](/reference/implementation-loop#self-hosted-fixture-shards)). Step 3 then retires them with the other fixtures.

   Follow-up: once this step merges, remove `after_merge`, `after_merge_cases` and `reason` from the scheduling keys in `bin/ci-units`. The step's own pull request still needs them: they make its removal of those keys from the registry a scheduling edit.
3. Retire the fixtures, file by file: move each realistic uncovered case into a proof case, then delete the file and its registration. A family retires only when its proof fits the condition in [When a family retires](#when-a-family-retires).
4. Absorb this ADR into [Implementation loop](/reference/implementation-loop#ci) and the proof pages.

## Rejected alternatives

- Keep fixtures and proof both: the same behavior tested twice, with two places to fix when an operation changes.
- Keep fixtures as the main test and proof as an occasional check: a stand-in can pass while the real operation fails.
- Move the after-merge fixtures back into the pull request: it keeps the overlap and only moves the cost.
- Keep a nightly full run as a safety net: its result arrives after the merge, nobody reads it, and it hides input lists that are wrong.

## Consequences

- A merge waits for the proof of the operations it changes. Its time depends on proof capacity ([ADR 0260](/decisions/0260-share-ci-places-between-runners-and-proof-copies)).
- A failing proof stops the merge, not main.
- Orbit deletes about 179 test files, their cost files, and the after-merge workflows and scripts.
- Rare failure paths lose their stand-in tests. Each one needs a design that makes it impossible, or a proof case if it is realistic.
- The fixtures of the code that makes the proof copy stay. They are the only test of that code.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: [0260](/decisions/0260-share-ci-places-between-runners-and-proof-copies)
- Detail: [Implementation loop: CI](/reference/implementation-loop#ci); [proof:run](/cli/proof/run); the Main full set page, removed in step 2
- Verify: a pull request that changes one operation runs that operation's proof cases and no fixture; `Required checks` fails on a failing proof case; main runs its quick checks only on a merged tree that differs and runs no proof; no after-merge consumer or nightly sweep runs
