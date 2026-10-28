# orbit-swamp

A spike to test whether [Swamp](https://swamp-club.com) can replace the
hand-rolled orchestration in Orbit's tasks feature. It uses Orbit dependency
upgrades as the test case. The [dependency routine](docs/website-routine.md) and
[project batch workflow](docs/website-packages.md) use the official software
factory and Gateway operations in task VMs.

Maintained projects run the shared maintenance extensions on a Swamp worker inside its
task VM. [Worker placement and enrollment](docs/task-worker-maintenance.md)
cover the lifecycle and replay rules. Project config requires worker paths and
contains no repository script. Each repository keeps its version constraints
and release-age policy. One failed update or check gets one repair turn; a second failure parks
for assistance. Green work publishes a pull request using the extension summary.
Unchanged work completes without one. Add repositories with one
[private project extension](docs/project-extensions.md).

The earlier [dependency planner](docs/dependency-workflow-adoption.md) remains
historical and is not part of this flow. External review-and-merge/topology
admission stays closed.

## Services deployment

The orchestrator runs as an Orbit-managed Node Process on Services. Beast's
original unit is stopped and disabled, with its files retained. See the
[layout, bundle deployment and cutover procedure](docs/services.md).

## Private project extensions

`@nckrtl/project-nckrtl-com` holds Project 20's config and weekly routine/daily
detection schedules. Shared factories and the group worker do the work.
Detection creates separate major/advisory tasks; exact major targets enter the
existing package sequence, while security tasks wait for verified fixed
versions. See [the design and rollout](docs/project-extensions.md) and
[extension template](docs/templates/project-extension/README.md). The
[fleet inventory](docs/maintained-project-extensions.md) covers the other 18
maintained projects and their source config.

## Composer maintenance extension

[`@nckrtl/composer-maintenance`](extensions/behaviors/composer-maintenance/README.md)
provides `routine`, `detect`, `package`, and `summary` methods for a supplied
checkout. It takes paths and policy as inputs, reads Composer lockfiles without
`vendor/`, and records temporary Vet security exceptions. Its private manifest
supports `nckrtl` collective distribution. Project 20 calls it through the group
worker workflow.

## JavaScript maintenance extension

[`@nckrtl/js-maintenance`](extensions/behaviors/js-maintenance/README.md)
provides matching `routine`, `detect`, `package`, and `summary` methods for npm,
Bun, Vite+, and Expo. JavaScript-only checkouts need no maintenance package or
Composer. It shares path safety, clean child environments, exception records,
and PR text with Composer maintenance. Its summary can cover both ecosystems in
one run. Project 20 calls it through the group worker workflow.

[Release-age exception records](docs/project-maintenance-exceptions.md) belong
to private project config and Swamp resources, including records returned after
failed security commands. The checkout needs no exception ledger.

## Horizon New Dawn project extension

[`@nckrtl/project-horizon-new-dawn`](extensions/behaviors/projects/horizon-new-dawn/README.md)
detects the next upstream Horizon tag on a group's VM worker and creates one
backlog parity task per changed item. Its committed package ledger stays in the
package. [The parity contract](docs/horizon-new-dawn.md) covers the merged
baseline, pinned fixtures and manual activation. PRs wait for Nick.

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

## Phase 1: historical host-side upgrade plan (read-only)

`workflows/workflow-dependency-upgrade.yaml` runs `scan → plan → assert`, then
an approval gate that runs only when the plan has a major upgrade (or
`--input requireApproval=true`).

This is the earlier host-scratch spike, not a supported task-VM execution path.
Do not run it to bypass the
[dependency adoption boundary](docs/dependency-workflow-adoption.md). Its
major-approval stub is not exact dependency-plan consent.

The behavior type `@nckrtl/orbit-deps` is in
`extensions/behaviors/orbit_deps.ts`.

- `scan` reads `GET /api/v1/instances/315/dependencies` from the Gateway.
  Instance 315 is the `default` development Instance of Project 46. The Gateway
  authenticates by WireGuard peer, and curl trusts the Orbit root CA. For each
  sub-project and ecosystem, `scan` compares the Gateway's file hashes with
  `origin/main` in the scratch clone. Where a hash differs, or the Gateway scan
  failed, `scan` reads the manifest and lockfile with `git show` and records the
  reason.
- `plan` (the dependency-planner) checks every direct dependency against
  Packagist (`p2`) or the npm registry. It records an `upgrade-plan` with one
  `within-constraints` subtask per sub-project and one `constraint-change`
  subtask per package whose newest stable release is outside its constraint.
  Subtasks that leave the caret range are flagged `major`. Malformed, incomplete
  or unavailable registry evidence is retained as diagnostic `unknown` rows and
  fails the method; an empty successful plan cannot represent registry failure.

Limits: the plan counts direct dependencies only; `composer update` moves
transitive ones as well. `dev-*` and path packages are skipped. `apps/agent`
(Cargo) is outside the Gateway's monorepo rule and is not scanned.

## Phase 2: historical host-side subtask factory

One upgrade-plan subtask is one work item of `@swamp/software-factory`. There
are two versions; **v2 is the current one**, aligned with Swamp's practices.

```
mechanical → handoff → review → approved
               ↑  ↓ fail    ↓ rework (open critical/high findings)
             implement ←────┘
               ↓ blocked  ↑ answered
              consult ────┘
```

| Stage      | Work                                                                                          | LLM         |
| ---------- | --------------------------------------------------------------------------------------------- | ----------- |
| mechanical | `composer update` in the sub-project, committed with the key as a trailer                     | none        |
| handoff    | the sub-project's `composer check` and `composer test` (full Pest suite)                      | none        |
| implement  | one Codex turn (gpt-6.1-sol, high) with the failing output, open findings or a consult answer | 1 per entry |
| consult    | the reviewer (Claude, claude-opus-5-5) answers a `BLOCKED:` question                          | 1 per entry |
| review     | one read-only Claude review; records a `code-review` findings artifact                        | 1 per entry |

Both agents reach their models through CLIProxyAPI (AI Router). Swamp itself
never calls an LLM.

Orbit's caps (same in v1 and v2):

- Consult cap 2: `consult.maxCycles: 2`. The 3rd consult parks for
  `approve gateId=cycle-override:consult`.
- Handoff reminders 3: each handoff records `consecutiveFailures`, reset by a
  pass. `fail` is open while it is ≤ 3; the 4th failure only has `assist`, which
  needs `handoff-assistance`.
- Max cycles: implement 6, review 3, handoff 10. A trip parks for
  `cycle-override:<stage>`; grants add one entry each.

### v2: stage workflows (`orbit-subtasks`)

- Factory: `models/@swamp/software-factory/orbit-subtasks.yaml`. Each stage's
  work is `mode: workflow`; every transition also needs `workflow-succeeded`,
  verified against Swamp's own run record.
- Stage workflows:
  `workflows/workflow-stage-{mechanical,handoff,implement,consult,review}.yaml`.
  They run on `@swamp/git` (`ensure_worktree`, `commit`, `log`, `diff`),
  `@nckrtl/composer` (`install_all`, `update`, `script`; allowlisted child env)
  and `@swamp/agent-runner` (Claude `review`/`run`; the `run_codex` extension in
  `extensions/behaviors/agent_runner_codex.ts` routes Codex through CLIProxyAPI
  until lab #3769 lands). The Codex token helper is the vault secret
  `default/CLIPROXY_CODEX_AUTH`.
- Each run claims its key (`orbit-stage.claim`), and its last step
  (`orbit-stage.report`) records the stage's artifacts and resultEvidence with
  `run.id` (the factory DESIGN's self-reporting variant).
- Exactly-once: guards check live git state with
  `model.method("orbit-stage", "committed", …).dataHandles[0]` and skip work a
  key already committed; commits carry `Swamp-Idempotency-Key`; `report` is a
  no-op when the entry already has its evidence.
- Driver: `orbit-driver-v2` (`workflows/workflow-subtasks-driver.yaml`). It
  dispatches once per entry, starts the stage run on `swamp serve`
  (`workflow run --server --detach`, run id kept in `launch-<key>`), judges
  liveness from the run's summary or Swamp's run record, and relaunches a dead
  run under the same key (at most 3 launches, then it parks for assistance).
  Without `serveUrl` it falls back to a local detached run and the claim's pid.
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

## Phase 3: external Orbit task groups (ADR 0229)

The [current design and next slice](docs/phase-3-design.md) separates completed
live proof from pending amendments and interruption proof. PR6 delivered
[group assistance](docs/group-assistance-contract.md); live adoption remains
unproven. The next docs-only proposal is the
[review-and-merge API contract](docs/review-and-merge-contract.md), with
[hosted handoff CI](docs/gateway-handoff-ci-brief.md) as the first native
Gateway task. The [tick inventory](docs/tick-engine-inventory.md) marks retained
effects and safety.

Swamp drives an Orbit task group with `orchestration: external` only through
Gateway operations. Orbit runs every effect in the group's task VM; Swamp
decides the next step. Swamp never merges.

- Factory: `models/@swamp/software-factory/orbit-groups.yaml`. One work item is
  one task group, `tg-<group id>`:
  `provision → baseline → plan → analyze → implement → handoff → analyze → review
  → (analyze | publish) → published → completed`.
  Rework and next-child paths require fresh analysis. The plan stage records the
  complete cumulative typed inventory and requires explicit authorization; see
  the adoption contract.
- Stage work: `@nckrtl/orbit-gateway.stage`
  (`extensions/behaviors/orbit_gateway.ts`, logic in `_lib/gateway_stage.ts`).
  Each call starts or reads one operation (`tasks.provision`, `tasks.check`,
  `tasks.analyze`, `tasks.turn`, `tasks.publish`) with
  `Idempotency-Key: tg-<id>.<stage>.s<subtask>.c<cycle>`, stores the request and
  the operation id in `job-<key>`, and returns at once. A replay sends the
  stored request, so the Gateway returns the same operation.
- `published` waits for a person to merge the pull request, then calls
  `tasks:complete`.
- Every failed or cancelled operation has a route. A failed provision that ended
  the Orbit group ends the run in `failed`, because a retry needs a new group;
  one that left the group open (a cancelled queued provision stays `todo`)
  retries after `provision-retry`. Baseline, handoff, review and publish retry
  under a new cycle key after an approval (`baseline-retry`, `handoff-retry`,
  `review-retry`, `publish-retry`). A pull request closed unmerged publishes
  again after `republish`. A blocked turn or one without a receipt parks for
  `implement-retry` or `review-retry`, and a failing baseline for
  `baseline-override`.
- Caps count per subtask: 6 implement attempts, 3 reviews, and at most 3 failing
  handoffs in a row (the 4th needs `handoff-assistance`; a pass resets the
  streak). The counters live in each stage's artifact; the stage `maxCycles` are
  only run-wide safety nets.
- `tests/factory_routing_test.ts` runs the real factory and driver with swamp in
  a throwaway copy of the repo against a fake Gateway (`tests/_support/`), one
  group per routing case.
- Driver: `groups-driver` discovers all active task groups every five seconds.
  Each group owns independent bounded `group-run` generations with a durable
  wait between advances. [Generation admission and recovery](docs/per-group-workflows.md)
  preserve the factory state and operation keys. `maintenance-projects` admits
  new work and reconciles results; it no longer ticks the group drivers.
- A group's failed guarded step records an `error` in its tick entry and leaves
  the other groups free to advance. Its next tick reconciles the same factory
  entry and saved operation. If no step returned a status, the error entry's
  `stage` and `cycle` are null.
- Pre-approvals: a person can approve a gate before the run reaches it. The
  driver stores it for one exact work item, gate, stage and cycle, and the first
  tick that parks there applies it if it was created in the current run era.
  `swamp model method run orbit-driver-groups preapprove --input workItem=tg-1234
  --input gateId=baseline-override --input stage=baseline --input cycle=1 --input actor=nick`
- Board assistance: ordinary gates use the current open child's comments as
  before. After all children end, `publish-retry` and `republish` use group
  comments with the work item, stage, cycle, gate and run era. Each
  reconciliation reads full history and the current request. It preserves
  operator replacements and safety causes. Only the matching explicit factory
  approval permits clearing Swamp's Direction request; a board resolution alone
  neither approves nor starts a retry. Comments can be duplicated after a lost
  response, and the API has no conditional resolution. See the
  [delivered contract and adoption rules](docs/group-assistance-contract.md).
- swamp caps one execution at 100 cross-behavior calls. A tick stops starting
  steps at `maxCallsPerTick` (90) and the next tick starts with the work items
  it deferred. Evidence from before a `reset` never counts as the current
  entry's.

```bash
swamp model method run orbit-groups start --input workItem=tg-1234
swamp workflow run groups-driver            # one discovery pass; serve repeats it
swamp model method run orbit-groups summary --input workItem=tg-1234
```

### Serve on Beast

- User unit `swamp-serve` on `127.0.0.1:9417` (9090 is Prometheus), token auth,
  admin `user:nckrtl`, Club key from `~/.config/swamp/api-key`. The drop-in
  `swamp-serve.service.d/path.conf` sets `PATH` (Composer, PHP, `claude`,
  `codex`) and turns off update checks and telemetry.
- `subtasks-driver` has a 1-minute `trigger.schedule`. Pause it with
  `swamp workflow schedule pause subtasks-driver --reason …`.
- The driver authenticates with the serve token `serve-driver` in
  `~/.config/swamp/serve-driver-token` (mode 600).
- Serve does not reload extension code. Restart the unit after a change to
  `extensions/`, when no stage run is in flight.
- `stage-implement` runs Codex with `sandbox: danger-full-access`: Beast sets
  `kernel.apparmor_restrict_unprivileged_userns=1`, so Codex's bwrap sandbox
  cannot start. The workspace is a disposable scratch worktree.
- `stage-review` allows `Bash(tee:*)`, which agent-runner uses to write its
  result. A review with no result counts as a failed run, not a finding.

```bash
export XDG_RUNTIME_DIR=/run/user/$(id -u)
systemctl --user restart swamp-serve
journalctl --user -u swamp-serve -f -o cat
```

The stage workflows were generated once and are edited as YAML from here on.

### v1: detached effect jobs (`orbit-subtask-factory`)

Kept for comparison. Stage work is `mode: method` on `orbit-subtask.run`, which
launches bash effects (`bin/run-effect`, `bin/effects/*.sh`) as detached jobs
with marker dirs and a per-key flock. Kill tests:
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

Swamp runs the production automatic-deploy policy after the ADR 0238 Gateway cutover. Its schedule runs every minute at second 30 UTC and discovers every enabled production Instance and waits for Gateway ownership before admitting work. See [running the policy and resolving holds](docs/automatic-deploy.md).
