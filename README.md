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

## Phase 2: the subtask factory, run locally

One upgrade-plan subtask is one work item in the `orbit-subtask-factory`
instance of `@swamp/software-factory`
(`models/@swamp/software-factory/orbit-subtask-factory.yaml`):

```
mechanical → handoff → review → approved
               ↑  ↓ fail    ↓ rework (open critical/high findings)
             implement ←────┘
               ↓ blocked  ↑ answered
              consult ────┘
```

| Stage | Work | LLM |
| --- | --- | --- |
| mechanical | `composer update` in the sub-project, committed with the key as a trailer | none |
| handoff | the sub-project's `composer check` and `composer test` (full Pest suite) | none |
| implement | one `codex exec` turn (gpt-6.1-sol) with the failing output or open findings | 1 per entry |
| consult | the reviewer (`claude -p`, claude-opus-5-5) answers a `BLOCKED:` question | 1 per entry |
| review | one read-only `claude -p` review; records a `code-review` findings artifact | 1 per entry |

Both CLIs reach their models through CLIProxyAPI (AI Router) with the user's
existing config; Swamp itself never calls an LLM.

Orbit's caps:

- Consult cap 2: `consult.maxCycles: 2`. The 3rd consult parks for
  `approve gateId=cycle-override:consult`.
- Handoff reminders 3: the handoff effect records `consecutiveFailures`,
  computed from its own markers and reset by a pass. `fail` is open while it
  is ≤ 3; the 4th failure only has `assist`, which needs `handoff-assistance`.
- Max cycles: implement 6, review 3, handoff 10. A trip parks for
  `cycle-override:<stage>`; grants add one entry each.

### How a work item moves

`workflows/workflow-subtask-driver.yaml` is one tick: `orbit-driver.tick` takes
each active work item one short step.

1. **Dispatch**: `record_dispatch` once per stage entry, then
   `orbit-subtask.run`, which launches the effect as a detached job.
2. **Poll**: `orbit-subtask.run` again. A running job is reported. A dead job
   with no result (lock free) is relaunched under the same key, at most 3
   times. A finished job is mapped to artifacts and the stage's resultEvidence,
   which the driver records.
3. **Advance** along the one satisfied transition, or park with what a human
   must approve.

The driver never dispatches an entry twice, so the factory's dispatch guard is
never used up by recovery. Exactly-once lives in the effects: the key is
`<workItem>.<stage>.c<cycle>`, a marker dir per key holds a flock and
`result.json`, and git commits carry `Swamp-Idempotency-Key`.

```bash
swamp model method run orbit-subtask-factory start --input workItem=bump-packages-php-sdk
bin/tick-loop 60 bump-packages-php-sdk          # the cron stand-in
swamp model method run orbit-subtask-factory summary --input workItem=bump-packages-php-sdk
```

Kill tests: `swamp model method run orbit-driver tick --input killAt=<point>`
SIGKILLs the tick at `after-dispatch`, `after-launch`, `before-evidence`,
`after-evidence` or `before-advance`. `bin/effects/fake.sh` replays scripted
stage results when `/fast/apps/orbit-swamp-scratch/fake/<workItem>.json`
exists, for engine tests with no git, Composer or LLM work.

Scratch state lives outside the repo: worktrees in
`/fast/apps/orbit-swamp-scratch/wt/<workItem>` (branch `swamp/<workItem>`, never
pushed), markers in `.../markers/<key>`, loop logs in `.../logs`.
