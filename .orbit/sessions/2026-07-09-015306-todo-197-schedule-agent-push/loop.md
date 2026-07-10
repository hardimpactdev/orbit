# Orbit Current Slice State

This is the worktree-local completion packet for MIG-04 slice #197. Do not
commit active `.orbit` state.

## Feature Context

- Epic: `solo://proj/4/todo/187`.
- Slice todo: `solo://proj/4/todo/197` - MIG-04 slice 1, schedule dispatch.
- Roadmap: `solo://proj/4/scratchpad/mig-04-agent-push-mi--259`.
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-mig04-schedule-agent-push`.
- Branch: `codex/mig04-schedule-agent-push`.
- Starting fallback count: 18 non-test `ExplicitRemoteShellFallback` files.
- Current slice: migrate `ScheduleDispatcher` from normal-path SSH fallback
  to an allowlisted internal command dispatched through `RemoteLocalExecutor`
  agent-push.

## Done Contract

- Single-slice: yes - #197 owns one schedule-dispatch surface and establishes
  the MIG-04 recipe.
- Parallelization: serial - the slice changes the schedule dispatcher,
  a new CLI internal command, a shared core enum, allowlist roles, schedule
  docs, and one live schedule smoke; these surfaces depend on each other.
- Done when:
  - `ScheduleDispatcher` no longer injects or uses
    `ExplicitRemoteShellFallback` or `RemoteShellPool`.
  - A node-local `internal:schedule:run` command verifies an operation token
    before executing the schedule payload and returns JSON data matching the
    existing schedule run result fields.
  - The typed internal-command enum and gateway allowlist include
    `internal:schedule:run` for schedule target roles.
  - Gateway schedule tests prove remote schedules dispatch through
    `RemoteLocalExecutor::runInternal`, preserve cwd/timeout/output recording,
    and no longer require explicit transitional SSH fallback.
  - CLI internal-command tests prove missing-token rejection, command/script
    execution, cwd, timeout, stdout/stderr, and non-zero exit capture.
  - Product docs no longer say schedule dispatch uses the host SSH pool.
  - Focused tests, docs-lint, quality gate, RC build/verify, live-test
    `update:all`, and a live schedule smoke all pass.
  - The `ExplicitRemoteShellFallback` count decreases after the slice.
- Evidence:
  - `.orbit/evidence/todo-197-schedule-agent-push/`.
- Reviewer checks:
  - The internal command must not leak operation tokens or secrets in JSON,
    activity, operation summaries, or exceptions.
  - The gateway must not hand-build internal executor command strings.
  - Schedule run history must remain gateway-owned and preserve exit code,
    stdout, stderr, duration, target node, and lock release behavior.
- Stop if:
  - Generic schedule execution cannot safely be represented as a token-gated
    internal command payload.
  - Live proof shows schedule execution behavior differs from the old SSH path.
- Pivot if:
  - Remote schedule concurrency becomes required for operator-visible behavior;
    then pause and design audited async `RemoteLocalExecutor` dispatch instead
    of quietly reusing the SSH pool.

## Progress

- Tried:
  - Confirmed #188 and #189 roadmap prerequisites are completed.
  - Prepared a dedicated implementation worktree with
    `bin/orbit-prepare-worktree codex/mig04-schedule-agent-push --base=origin/main --skip-tests`.
  - Read the MIG-04 epic, roadmap, #197 slice card, schedule dispatcher,
    internal command patterns, typed allowlist, schedule tests, and stale
    schedule docs.
  - Added gateway and CLI tests for agent-push schedule dispatch.
  - Implemented `internal:schedule:run`, added the typed core enum case, added
    gateway allowlist coverage, and moved schedule dispatch to
    `RunsInternalCommands::runInternal`.
  - Updated schedule product docs and regenerated the command catalog.
  - Discussed the remote-dispatch concurrency tradeoff with Claude in Solo
    process `901`; Claude returned `ACCEPT` with an async internal-command pool
    follow-up recommendation.
  - Built and verified RC artifacts for build
    `20260708T232933Z-b891f6663`.
  - Installed the candidate through the live-test manifest and proved
    node-scoped schedule dispatch on `NMBP`.
- Result:
  - Implementation committed as `b891f666314ed9a1ec2065c2ce548be1a71c6d85`
    and pushed to `origin/main` plus `origin/codex/mig04-schedule-agent-push`.
- Next:
  - Complete Solo todo #197 and continue MIG-04 slice #194.

## Candidate Signals While Working

- 2026-07-09/schedule-docs-stale-ssh-contract:
  `tech-stack.md`, `schedule-concepts.md`, `schedule:run` technical docs, and
  `execution-lanes.md` still describe non-gateway schedules as host SSH pool
  dispatch. Status: fix in this slice alongside code.

## Blockers

- No blocker currently.

## Evidence Links

- `pwd`: `/Users/nckrtl/orbit/.worktrees/codex-mig04-schedule-agent-push`.
- `git status --short --branch`: branch
  `codex/mig04-schedule-agent-push...origin/codex/mig04-schedule-agent-push`.
- Roadmap scratchpad: `solo://proj/4/scratchpad/mig-04-agent-push-mi--259`.
- Starting fallback count command:
  `rg -l 'ExplicitRemoteShellFallback' apps/gateway/app | grep -v Test | wc -l`
  returned 18.
- Final fallback count command:
  `rg -l 'ExplicitRemoteShellFallback' apps/gateway/app | grep -v Test | wc -l`
  returned 17.
- Commit/source identity:
  `b891f666314ed9a1ec2065c2ce548be1a71c6d85`; `origin/main` and
  `origin/codex/mig04-schedule-agent-push` both point at this commit.
- Local evidence summary:
  `.orbit/evidence/todo-197-schedule-agent-push/summary.md`.
- RC state:
  `.orbit/release-candidates/20260708T232933Z-b891f6663`.
- Session archive: .orbit/sessions/2026-07-09-015306-todo-197-schedule-agent-push

## Harness Signals

- Searched:
  - `HARNESS.md`
  - `apps/docs/content/execution-lanes.md`
  - `apps/docs/content/tech-stack.md`
  - `apps/docs/content/domains/9_schedule/schedule-concepts.md`
  - `apps/docs/content/domains/9_schedule/5_schedule-run/technical/1_schedule-run.md`
- Created or updated: none.
- Deferred follow-up: async internal-command pooling and unrelated live
  topology drift remain scoped product/operations follow-ups below.

## Final Distillation

- Loop outcome:
  - complete
- Required verification:
  - Focused Pest: passed.
    - CLI internal schedule/workspace tests: 7 passed, 19 assertions.
    - Gateway schedule dispatcher/allowlist/probe tests: 125 passed,
      701 assertions.
  - Docs lint: passed with existing warnings.
  - Scoped Mago lint/format/analyze: passed; warning-only baseline noise noted.
  - `composer quality-check`: passed. The artifact was captured pre-commit
    against parent commit `bb453ea4d8928af9fe5bd2823e4b7f3cd204b2f2` plus the
    dirty worktree that became commit
    `b891f666314ed9a1ec2065c2ce548be1a71c6d85`. Key lanes: gateway Pest 4355
    passed; CLI Pest 2132 passed; docs Pest 128 passed; core Pest 111 passed;
    SDK Pest 128 passed; docs-lint passed.
  - RC artifact proof: passed.
    - `bin/orbit-release-candidate build` produced build
      `20260708T232933Z-b891f6663`.
    - `bin/orbit-release-candidate verify` passed stored CLI/agent hash checks.
  - Live topology proof: passed after one transient/drift-affected retry.
    - First `update:all --stream-json` installed candidate artifacts, then
      failed final verification on `mini` agent reachability.
    - Second `update:all --stream-json` completed successfully with
      `manifest_source=topology-candidate`.
    - Post-green-update schedule smoke on `NMBP` created
      `todo-197-final-b891f666`, force-ran it, recorded run id `8` with
      stdout `todo197-final-b891f666`, removed it, and confirmed
      `schedule:list --node=NMBP` returned `count=0`.
    - Activity rows `146102` and `146103` show
      `local_executor.dispatching` and `local_executor.completed` for
      `internal:schedule:run` on `NMBP`.
  - Retained topology proof: passed - topology kind=live-test RC artifact
    deployment; node=`NMBP`; commands=`manifest:update` to
    `https://s3.hardimpact.dev/orbit/channels/live-test/orbit-release-manifest.json`,
    `update:all --stream-json`, `schedule:add todo-197-final-b891f666`,
    `schedule:run todo-197-final-b891f666`, `schedule:remove
    todo-197-final-b891f666`, `schedule:list --node=NMBP`, and
    `activity:list/activity:show` for rows `146102` and `146103`; evidence
    `.orbit/evidence/todo-197-schedule-agent-push/summary.md`.
- Finalization gate fit:
  - matched - topology-relevant gateway PHP plus CLI/core/docs changes were
    covered by focused tests, docs-lint, quality-check, RC build/verify,
    live-test `update:all`, and live schedule smoke.
- Distillation packet:
  - Location: `.orbit/loop.md`
  - Includes objective/final diff: yes - commit
    `b891f666314ed9a1ec2065c2ce548be1a71c6d85`.
  - Includes worker/reviewer/terminal/evidence pointers: yes - Solo Claude
    review process `901`; evidence summary under
    `.orbit/evidence/todo-197-schedule-agent-push/summary.md`.
  - Includes orchestrator steering notes: yes.
- Agent session capture waivers:
  - Solo Claude review process `901` is referenced by process id and verdict;
    no provider session capture was staged because the lane was a narrow
    read-only sanity check and its rendered output was inspected directly.
- Fresh analyzer:
  - used - Solo Codex process `902`; initial verdict `flawed` due packet
    shape/evidence wording, not implementation behavior. Findings: missing
    exact `Retained topology proof:` row and quality-check artifact identity
    needed pre-commit dirty-worktree clarification. Packet corrected,
    `bin/orbit-feature-finalization-check --lint .orbit/loop.md` passed, and
    analyzer updated to `VERDICT: yes`.
- Candidate signals:
  - schedule-docs-stale-ssh-contract -> fixed in this slice by updating
    schedule docs and execution-lane docs away from host SSH pool language.
- Accepted durable updates:
  - Product docs now state non-gateway schedule dispatch uses
    `internal:schedule:run` over agent-push/local executor.
- Rejected or already-covered signals:
  - No new harness guardrail required for the docs stale-schedule signal; the
    existing docs-first implementation route already caught and fixed it.
- Deferred follow-ups:
  - Add an audited async internal-command pool/start API and restore concurrent
    remote schedule dispatch before long-running remote schedules become common.
  - Investigate separate live topology drift seen during RC proof:
    `mini` agent/Docker-provider drift and stale workspace/process/proxy drift
    on `NMBP`.
- No-new-signal rationale:
  - No process/harness change needed. The only recurring risk is product work
    for async internal-command pooling, captured as a follow-up.
