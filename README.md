# orbit-swamp

A spike to test whether [Swamp](https://swamp-club.com) can replace the
hand-rolled orchestration in Orbit's tasks feature. It uses Orbit dependency
upgrades as the test case. Nothing here changes Orbit code, the live Gateway,
or Orbit task records.

## Setup on Beast

- `swamp` lives in `~/.local/bin`. `~/.config/swamp/env.sh` sets
  `SWAMP_API_KEY_FILE`, `SWAMP_NO_UPDATE_CHECK=1` and `SWAMP_NO_TELEMETRY=1`.
  `~/.zshenv` and `~/.bashrc` load it. A cron or systemd driver must load it
  itself.
- Telemetry is off for this repo too (`telemetryDisabled: true` in
  `.swamp.yaml`).
- `.swamp/` is runtime data and is not committed. On a fresh clone, run
  `swamp extension pull @swamp/software-factory` to restore the pinned factory.
- `/fast/apps/orbit-swamp-scratch/base` is a scratch clone of
  `hardimpactdev/orbit` with its push URL disabled.

## Phase 1: the upgrade plan (read-only)

`workflows/workflow-dependency-upgrade.yaml` runs `scan → plan → assert`, then
an approval gate that runs only when the plan has a major upgrade (or
`--input requireApproval=true`).

```bash
swamp workflow run dependency-upgrade
swamp data query 'modelName == "orbit-deps" && name == "upgrade-plan"' \
  --select 'attributes.subtasks.map(s, s.id)' --json
```

The behavior type `@nckrtl/orbit-deps` is in `extensions/behaviors/orbit_deps.ts`.

- `scan` reads `GET /api/v1/instances/315/dependencies` from the Gateway.
  Instance 315 is the `default` development Instance of Project 46.
  The Gateway authenticates by WireGuard peer, and curl trusts the Orbit root
  CA. For each sub-project and ecosystem, `scan` compares the Gateway's file
  hashes with `origin/main` in the scratch clone. Where a hash differs, or the
  Gateway scan failed, `scan` reads the manifest and lockfile with `git show`
  and records the reason.
- `plan` (the dependency-planner) checks every direct dependency against
  Packagist (`p2`) or the npm registry. It records an `upgrade-plan` with one
  `within-constraints` subtask per sub-project and one `constraint-change`
  subtask per package whose newest stable release is outside its constraint.
  Subtasks that leave the caret range are flagged `major`.

Limits: the plan counts direct dependencies only; `composer update` moves
transitive ones as well. `dev-*` and path packages are skipped. `apps/agent`
(Cargo) is outside the Gateway's monorepo rule and is not scanned.
