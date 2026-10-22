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

One upgrade-plan subtask is one work item of `@swamp/software-factory`. There
are two versions; **v2 is the current one**, aligned with Swamp's practices.

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
| implement | one Codex turn (gpt-6.1-sol, high) with the failing output, open findings or a consult answer | 1 per entry |
| consult | the reviewer (Claude, claude-opus-5-5) answers a `BLOCKED:` question | 1 per entry |
| review | one read-only Claude review; records a `code-review` findings artifact | 1 per entry |

Both agents reach their models through CLIProxyAPI (AI Router). Swamp itself
never calls an LLM.

Orbit's caps (same in v1 and v2):

- Consult cap 2: `consult.maxCycles: 2`. The 3rd consult parks for
  `approve gateId=cycle-override:consult`.
- Handoff reminders 3: each handoff records `consecutiveFailures`, reset by a
  pass. `fail` is open while it is ≤ 3; the 4th failure only has `assist`,
  which needs `handoff-assistance`.
- Max cycles: implement 6, review 3, handoff 10. A trip parks for
  `cycle-override:<stage>`; grants add one entry each.

### v2: stage workflows (`orbit-subtasks`)

- Factory: `models/@swamp/software-factory/orbit-subtasks.yaml`. Each stage's
  work is `mode: workflow`; every transition also needs `workflow-succeeded`,
  verified against Swamp's own run record.
- Stage workflows: `workflows/workflow-stage-{mechanical,handoff,implement,consult,review}.yaml`.
  They run on `@swamp/git` (`ensure_worktree`, `commit`, `log`, `diff`),
  `@nckrtl/composer` (`install_all`, `update`, `script`; allowlisted child
  env) and `@swamp/agent-runner` (Claude `review`/`run`; the `run_codex`
  extension in `extensions/behaviors/agent_runner_codex.ts` routes Codex
  through CLIProxyAPI until lab #3769 lands). The Codex token helper is the
  vault secret `default/CLIPROXY_CODEX_AUTH`.
- Each run claims its key (`orbit-stage.claim`), and its last step
  (`orbit-stage.report`) records the stage's artifacts and resultEvidence with
  `run.id` (the factory DESIGN's self-reporting variant).
- Exactly-once: guards check live git state with
  `model.method("orbit-stage", "committed", …).dataHandles[0]` and skip work a
  key already committed; commits carry `Swamp-Idempotency-Key`; `report` is a
  no-op when the entry already has its evidence.
- Driver: `orbit-driver-v2` (`workflows/workflow-subtasks-driver.yaml`). It
  dispatches once per entry, starts the stage run detached
  (`swamp workflow run --input-file`; in phase 3 a `swamp serve` call), judges
  liveness from the claim's pid, and relaunches a dead run under the same key
  (at most 3 launches, then it parks for assistance).
- Crash recovery the Swamp way also works: `swamp run doctor --fix` marks a
  killed run `interrupted`, `swamp workflow recover` auto-recovers it (the
  in-flight steps are guarded), and `resume` repeats nothing.
- One Composer and one agent-runner definition per work item
  (`composer-<workItem>`, `agent-claude-<workItem>`, `agent-codex-<workItem>`):
  a long step holds its definition's lock, and other steps on the same
  definition fail after `SWAMP_LOCK_TIMEOUT_MS` (60 s).

```bash
swamp model method run orbit-subtasks start --input workItem=bump-packages-php-sdk
DRIVER_WORKFLOW=subtasks-driver bin/tick-loop 60 bump-packages-php-sdk
swamp model method run orbit-subtasks summary --input workItem=bump-packages-php-sdk
~/.swamp/deno/deno test --allow-all tests/
```

The stage workflows were generated once and are edited as YAML from here on.

### v1: detached effect jobs (`orbit-subtask-factory`)

Kept for comparison. Stage work is `mode: method` on `orbit-subtask.run`,
which launches bash effects (`bin/run-effect`, `bin/effects/*.sh`) as detached
jobs with marker dirs and a per-key flock. Kill tests:
`swamp model method run orbit-driver tick --input killAt=<point>`
(`after-dispatch`, `after-launch`, `before-evidence`, `after-evidence`,
`before-advance`). `bin/effects/fake.sh` replays scripted stage results when
`/fast/apps/orbit-swamp-scratch/fake/<workItem>.json` exists.

Scratch state lives outside the repo in `/fast/apps/orbit-swamp-scratch`:
worktrees `wt/` (v1) and `wt2/` (v2) on `swamp/...` branches that are never
pushed, launch inputs and logs in `launch/`, v1 markers in `markers/`, loop logs
in `logs/`.

Known gap: the `apps/docs` check needs bun installs (`apps/web/tools/...`) that
the worktree prep does not do yet; Project 46's setup steps do.
