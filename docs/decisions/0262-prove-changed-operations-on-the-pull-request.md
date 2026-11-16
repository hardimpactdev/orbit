---
title: "ADR 0262: Prove changed operations on the pull request"
sidebarTitle: "0262 Proof on the pull request"
description: "In progress. A check runs only when its inputs change, on the pull request. The proof of each changed operation blocks the merge and replaces the shape-gate fixtures. The after-merge consumers and the nightly sweep are removed."
---

# ADR 0262: Prove changed operations on the pull request

A check runs when its inputs change, and only then, on the pull request. The quick checks stay as they are. The [proof](/cli/proof/run) of every operation that a change touches runs on the same pull request and blocks the merge. It replaces the shape-gate fixtures, which test the same automation a second time against stand-ins. The after-merge consumers and the nightly sweep are removed.

## Status

In progress.

Decided by the owner on 2026-11-15.

Principle: [One way, one name](/mission#principles): each operation has one test, its proof. It also serves [Lean](/mission#principles): Orbit deletes the after-merge consumers, the full set and the fixtures that nothing reads.

Open:

- Whether a fixture that tests pure code, with no playbook, workflow or Node behind it, stays as a quick check or is removed. Decide per file when the fixtures are retired (step 3 of the [build order](#build-order)).
- Whether main still runs its checks when the merged tree differs from the tree that the pull request's green run checked out ([Reuse a green PR tree on main](/reference/implementation-loop#reuse-a-green-pr-tree-on-main)), and whether that run includes the proof.
- Whether `Manual copy proof`, an operator's on-demand proof of one commit, stays.

## Context

Orbit's CI has three stages:

- **Pull request checks**: quick checks (Pest, PHPStan, builds, docs, Swamp shape and Deno tests) and fixture shards for the changed files, selected by their inputs.
- **Release** on main: the same checks again, unless the merged tree matches a green pull request tree, then the CLI release and the docs site.
- **After-merge consumers** ([Main full set](/reference/main-full-set)): `Main deferred fixtures` runs the fixtures that pull requests skip, on every push and every 10 minutes, and sweeps the whole set every night; `Main full set` runs the same sweep on demand; `Main copy proof` runs a report-only proof of the newest main commit. Their shards queue for the self-hosted runners they are pinned to. Nothing waits for their result, so a failure surfaces after the merge, when main is already red.

The shape-gate fixtures are 179 test files under `swamp/tests/`, `swamp/bin/` and `swamp/extensions/`, registered in `.github/ci/fixtures/`. Most run a shipped playbook or workflow on the CI runner against stand-ins: this machine as the Node, a stand-in Incus, no network. They declare about 6.6 hours of single-worker time; `ansible_instance_migrate_test.ts` alone declares 2.3 hours. The [proof](/cli/proof/run) runs the same operations end to end, through the Gateway and Swamp, against a nested fleet. The two layers test the same behavior twice.

The after-merge stage exists for two reasons: 18 fixture files are too slow for the pull request budget, and an input list may miss a file, so a nightly run of everything guards against it. Both are answered better at their root: slow work is sharded over more capacity ([ADR 0260](/decisions/0260-share-ci-places-between-runners-and-proof-copies)), and a missing input is a bug in the input list.

## Decision

Orbit's CI has two stages: checks on the pull request, then the release on main. A pull request runs the checks its change selects, and the proof is the test of each changed operation.

### A check runs when its inputs change

Each check declares its inputs. A pull request runs exactly the checks whose inputs it changes. A check that misses an input is fixed by correcting its inputs; Orbit adds no rerun of everything to catch it.

There are no after-merge consumers and no nightly sweep. Main builds and releases.

### Proof is the test of an operation

When a change touches an operation's code, its proof cases run on the pull request: `proof:run` selects the changed cases, groups them into batches, and balances the batches over copies, as [proof:run](/cli/proof/run) describes. The proof is part of `Required checks` and blocks the merge.

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

### Quick checks stay where they run

The quick checks of a public repository run on GitHub-hosted runners, as today. Private repositories run only on Orbit's runners. Code from a fork never runs on Orbit's runners; its proof follows the existing outside-proof rule.

### Build order

1. Make the proof blocking again, with its cases selected per changed operation. This needs proof capacity on more than one Node ([ADR 0260](/decisions/0260-share-ci-places-between-runners-and-proof-copies)).
2. Remove the after-merge consumers and the full set: `Main deferred fixtures`, `Main full set`, `Main copy proof`, the answer collector and the failure sweep, with their scripts and repository variables.
3. Retire the fixtures, file by file: move each realistic uncovered case into a proof case, then delete the file and its registration.
4. Absorb this ADR into [Implementation loop](/reference/implementation-loop#ci) and the proof pages, and retire [Main full set](/reference/main-full-set).

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
- Detail: [Implementation loop: CI](/reference/implementation-loop#ci); [proof:run](/cli/proof/run); [Main full set](/reference/main-full-set), which is retired
- Verify: a pull request that changes one operation runs that operation's proof cases and no fixture; `Required checks` fails on a failing proof case; no after-merge consumer or nightly sweep runs
