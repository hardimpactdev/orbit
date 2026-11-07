---
title: "ADR 0247: Keep fleet software current through tested recipe changes"
sidebarTitle: "0247 Fleet software updates"
description: "In progress. Renovate proposes recipe updates; command tests on template clones gate them; the fleet rollout applies them with Ansible phases through node:converge; topology templates replace the task VM image build."
---

# ADR 0247: Keep fleet software current through tested recipe changes

Every recipe version change reaches a live Node only after a real machine has proven it. Renovate proposes the change, command tests on template clones prove it, and the fleet rollout applies it through `node:converge`.

## Status

In progress.

Decided by Nick on 2026-11-03 and 2026-11-04. Replaces the fleet maintenance that was removed on 2026-11-03 (`recipe.py`, `fleet.recipe`, nightly recipe pull requests) and the task VM image build.

Principle: [Deterministic first](/mission#principles), [One way, one name](/mission#principles) and [Lean](/mission#principles).

## Context

The old fleet maintenance merged nightly version bumps with no compatibility test. CI checked Orbit's own code only, so the first real test of a new version was a live Node. The task VM image build installed software through orbit-agent phases, tied the image identity to the agent pin, and caused 8 of the last 19 build failures.

Ansible is approved for Node work: one Swamp step per phase, each phase an idempotent playbook that can rerun from the start. In the `node:role:add` trial, phases were about twice as fast as all-Swamp workflows and about a third of the size.

## Decision

**Desired state.**

- `apps/gateway/resources/machine-software/recipe.json` stays JSON and stays the one source of pins, role tool lists and the image tool list.
- Ansible reads it directly from the release checkout.
- The Gateway intent carries targets only: Nodes, roles, Tools, network and SSH details. It carries no versions.

**Updates.**

- Self-hosted Renovate runs as an hourly GitHub Actions cron on Orbit's self-hosted runners. A custom manager reads `recipe.json`.
- A new version is proposed when it is at least 24 hours old, or right away when it fixes a known CVE.
- Non-major updates go into one rolling batch PR. Each tool has a track (its major line); a new major gets its own PR that waits for Nick.
- A green PR merges automatically. A red PR stays open. A person or agent excludes the broken version, and Renovate rebuilds the PR.
- Checksums are stored only where Renovate fills them in, which is GitHub releases. Sury, Zabbly and Launchpad packages come from signed apt repositories at exact versions. Other downloads are checked against the upstream checksum file at install time.

**Tests.**

- Each chart path has one case, kept in `docs/cli/<family>/<command>.cases.yaml`: the main path, each `--flag`, and the error paths. `bin/check-flows` fails when an `opt` block has no case.
- `proof:run <commit>` takes a VM, builds its nested test fleet (Gateway with Swamp, app-dev, app-prod) from the ready snapshot, applies the commit and runs the cases. [`proof:run`](/cli/proof/run) owns the contract.
- A case passes when Doctor finds no drift on the records it touched and a second run changes nothing. An error case passes when the command refuses and changes nothing.
- A CI job calls `proof:run` for every pull request that changes command code, and its result is the required check `Proofs`. A commit that changes `recipe.json` runs every case.

**Rollout.**

- `node:converge` runs these phases in order:
  1. Orbit binaries: the agent and the CLI.
  2. Role software.
  3. Role configuration.
  4. Tools, when the Node has any.
  5. Network, on the Node and its hub.
  6. Check.
- `node:converge` has no footprint digests and no `--force`.
- After each verified release, the fleet rollout runs `node:converge` on one Node at a time, in risk order. Nodes with only `app-dev` go at any hour; other Nodes wait for the maintenance window. It halts at the first failed Node.
- The software phase holds the recipe packages, so Tools cannot move them. Ubuntu `-security` updates still install through `unattended-upgrades`. Renovate then raises the pin, and the next test confirms it.

**Tools.**

- Tool records stay per Node.
- One Tools phase serves `tool:install`, `tool:update`, `tool:remove`, `node:converge` and the rollout. It also runs on macOS.
- The phase picks the newest version within the constraint that is at least 24 hours old, or one that fixes a CVE.
- A Tool that conflicts with a held recipe package fails alone, with `tool.recipe_conflict`. A failed Tool does not halt the rollout.

**Templates.**

- One template set lives on one Incus host.
- After a release that changes Node state, a refresh converges the templates and snapshots them. One refresh runs at a time, and the latest release wins.
- The app-dev snapshot is the task VM image. It is copied to the other Incus hosts.
- A nightly task builds the topology from stock Ubuntu in a task VM, runs every case, and compares the result with the templates through Doctor.
  - On a pass, the fresh build replaces the templates.
  - On a failure, Pi works in that VM, on Codex Sol 6.1 at High effort. It fixes an Orbit bug with a PR, pins and excludes a broken upstream version with a PR, or reruns once and then reports.
  - A pin PR touches only `recipe.json` and `renovate.json`, and merges automatically when its required check passes. A fix PR changes Orbit code and goes through normal review: an independent review, then Nick's approval.

**Python.**

- Ansible needs Python on each Node, so Python comes with the agent.
- Linux Nodes keep the system `python3`, which Ubuntu always ships and Ansible's `apt` module needs.
- A macOS Node gets its Python where it gets its agent: `node:add` installs a pinned python-build-standalone build into Orbit's own prefix, with one `raw` task that needs no Python. The Tool commands then run on it. `node:converge` and the rollout stay Linux-only.
- The build is a `recipe.json` artifact, which Renovate keeps current.
- Orbit installs no Xcode and no Command Line Tools. Homebrew's own need for them stays Homebrew's.

## Rejected alternatives

- **A custom nightly bump with automatic splitting and bisection.** Renovate proposes and groups updates. Checks in the style of Molecule test them. No standard tool splits or bisects a batch, so that part would be code of our own.
- **The hosted Mend Renovate app.** Most artifact checksums need a command, which the free app blocks. It runs every few hours instead of hourly. It also gets write access to the repository.
- **Versions in Ansible vars or the Gateway database.** That would mean three reshapes of the same data, or changes that skip git review and the test gate.
- **Holding Ubuntu security updates for the tested path.** A live CVE then waits up to a day for the maintenance window.
- **A template set on every Incus host.** That means N refreshes per release, and tests need only one place.

## Consequences

- `recipe.json` gains one artifact: the python-build-standalone build for macOS Nodes.
- No recipe version reaches a Node untested. Two exceptions remain: Ubuntu security patches, which the next test confirms after the fact, and Tool versions, which follow their constraint and the 24-hour rule without a test, isolated by the recipe hold.
- The task VM image build, `fleet.recipe_pr`, the agent image phases, the Tool candidate and decision steps, and the footprint digests are removed.
- Every rollout visits every Node. A visit with no changes takes about a minute.
- Timings of today's workflows, not of the Ansible phases, measured on the shared proof topology on Shark on 2026-11-03 and 2026-11-04:
  - Release: the release run took 49 seconds on topology version 16, and the Swamp load (16 seconds) is part of it. On version 17 the release fixture failed in its preparation, because serve holds port 9418.
  - Converge: `node:converge` took 25 seconds on version 17 and 57 seconds on version 14, for one Node.
  - Release onto an existing topology with two Nodes: about 2 to 3 minutes, estimated from the runs above.
  - Stock Ubuntu 26.04 to `app-dev`: neither step succeeded. `node:add` ran every phase in 70 seconds, and then failed its final check because of a key workaround that only the proof needs. The `app-dev` role then ran for 4 minutes 17 seconds and failed at a Tool Manager download (HTTP 404). Most of that time went to prerequisites (45 seconds), PHP packages (62 seconds) and Tool Managers (69 seconds). The failed attempts took 5 minutes 27 seconds combined. A complete successful run remains unmeasured.
  - Estimate: `proof:run` adds about 2 to 3 minutes before its cases run. The time of a full nightly build is still unverified.

## Affects

- Components: apps/gateway, apps/cli, apps/docs
- ADRs: replaces the task VM image build of 0206.
- Detail: outside those components, the change also touches `swamp/` and `.github/`: the Swamp workflows and Ansible playbooks under `swamp/`, the Renovate workflow under `.github/`, and the charts `docs/cli/node/converge.mmd`, `docs/cli/fleet/rollout.mmd`, `docs/cli/tool/{install,update,remove}.mmd`, `docs/cli/test/run.mmd` and `docs/cli/template/{refresh,build}.mmd`. Pages: [Compute drivers](/reference/compute-drivers), [Tools](/reference/tools), [Node provisioning](/reference/node-provisioning).
- Verify: a rollout and a template refresh on the proof topology; a Renovate PR that a failing case blocks; a nightly build that reaches zero Doctor drift against the templates.
