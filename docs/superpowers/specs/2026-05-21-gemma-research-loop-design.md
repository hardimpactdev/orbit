# Gemma Research Loop Design

## Goal

Create an external research loop for Orbit where Solo orchestrates local
OpenCode sessions backed by Beast's `gemma4:26b` Ollama model. The loop should
let a human define research topics, then let many short Gemma sessions perform
focused overnight investigation in a shared topic worktree.

The aim is not autonomous product development. The aim is repeatable research:
baseline an Orbit behavior, try small changes, verify with real Orbit lanes,
and leave enough evidence for morning review.

## Current Context

Orbit already has useful verification surfaces:

- `composer test:e2e`, `composer test:e2e:docker`,
  `composer test:e2e:incus`, and `composer test:e2e:provision` for real
  infrastructure behavior.
- E2E timing output through `ORBIT_E2E_TIMINGS=1`.
- `doctor` for convergence and security posture.
- `profile` for request timing.
- activity logs for auditability.
- Solo agent orchestration and process lifecycle tools.

Local OpenCode can be launched by Solo, and OpenCode can use the configured
Ollama provider:

```text
ollama/gemma4:26b
```

Only Ollama/Gemma runs on Beast. OpenCode and Solo stay on the local machine.

## Product Decisions

- The research loop lives outside Orbit's CLI and production command surface.
- Solo owns orchestration. OpenCode/Gemma owns one focused research task at a
  time.
- Each research topic has exactly one persistent shared worktree.
- Each research task gets a fresh Gemma/OpenCode session.
- A topic ledger carries durable context between fresh Gemma sessions.
- Gemma may leave code changes in the topic worktree. The worktree is preserved
  for morning inspection.
- Gemma must not merge, rebase, delete the worktree, or push branches.
- Gemma must not edit E2E scenario tests unless a topic explicitly allows it.
- The orchestrator does not review code quality. It only checks mechanical
  completion, forbidden-path violations, and required artifacts.
- Humans and stronger agents do morning review and decide whether to merge,
  revise, or discard a research result.

## Non-Goals

- No Orbit CLI command in v1.
- No standing live-node verification in v1.
- No autonomous merging.
- No long-lived Gemma session that accumulates stale context.
- No Gemma-owned task prioritization.
- No mutable E2E scenario tests for speed/security baselines by default.

## Directory Shape

The external workspace should be separate from the Orbit repository:

```text
~/orbit-research/
  topics/
    e2e-docker-speed/
      topic.yaml
      ledger.md
      tasks/
        001-baseline.yaml
        002-cache-inspection.yaml
        003-prototype-cache-reuse.yaml
      findings/
        001-baseline.md
        002-cache-inspection.md
      logs/
        001-baseline.log
      worktree -> ../../worktrees/e2e-docker-speed

  worktrees/
    e2e-docker-speed/

  templates/
    gemma-task-prompt.md
    morning-review.md
```

The topic directory stores intent and evidence. The worktree stores code.

## Topic Packet

`topic.yaml` defines the durable research contract:

```yaml
id: e2e-docker-speed
title: Reduce Docker E2E lane wall time
category: speed
priority: high

goal: >
  Make Docker-backed feature E2E faster without weakening coverage.

hypothesis: >
  Checkout/cache reuse and topology reuse are likely bottlenecks.

worktree:
  branch: research/e2e-docker-speed
  base: main

immutable_paths:
  - tests/E2E/**/*.php

allowed_scope:
  - app/**
  - bin/**
  - composer.json
  - TESTING.md
  - tests/E2E/Support/**

forbidden_scope:
  - tests/E2E/**/*.php
  - tests/Feature/**

baseline:
  command: >
    /usr/bin/time -p env ORBIT_E2E_TIMINGS=1
    ORBIT_E2E_PARALLEL_PROCESSES=8 composer test:e2e:docker
  runs: 3

success:
  min_wall_time_improvement_percent: 10
  require_all_runs_passing: true
  require_same_or_higher_assertion_count: true
  require_no_forbidden_path_changes: true

verification:
  commands:
    - vendor/bin/pint --dirty --format agent
    - composer test:e2e:docker
```

The topic is human-authored. Gemma may read it but must not rewrite the topic
contract.

## Task Packet

Each task is intentionally smaller than the topic:

```yaml
id: 002-cache-inspection
status: open
title: Inspect checkout and cache behavior

prompt: >
  Inspect the Docker E2E checkout/cache path. Identify the slowest likely step
  and propose one small change. Do not edit files in this task.

allowed_actions:
  - read
  - run-safe-commands

required_outputs:
  - findings/002-cache-inspection.md
  - logs/002-cache-inspection.log
```

Allowed task statuses:

- `open` — ready for the orchestrator to dispatch.
- `running` — claimed by a spawned Gemma worker.
- `done` — required artifacts exist and no mechanical guard failed.
- `failed` — worker could not complete the task or verification failed.
- `needs-human-review` — worker finished but surfaced ambiguity or risk.

The orchestrator owns status transitions. Gemma reports its proposed result in
the findings file.

## Topic Ledger

`ledger.md` is the memory bridge between fresh Gemma sessions. It should stay
short enough for every worker to read.

```markdown
# Research Ledger: e2e-docker-speed

## Current State

Shared worktree: ~/orbit-research/worktrees/e2e-docker-speed
Branch: research/e2e-docker-speed

## Durable Findings

- 001-baseline: Docker E2E mean wall time is 82.4s across 3 passing runs.
- 002-cache-inspection: checkout archive extraction dominates per-worker setup.

## Active Patch State

- No code changes yet.
- E2E scenario tests untouched.

## Open Questions

- Is checkout cache reuse safe across Pest workers?
```

Gemma appends a short ledger entry after each task. It must not replace the
whole ledger.

## Orchestration Loop

The Solo orchestrator should be intentionally small:

1. Read the selected topic.
2. Create the topic worktree if missing.
3. Find the next `open` task.
4. Mark it `running`.
5. Spawn local OpenCode through Solo with `--model ollama/gemma4:26b`.
6. Send a generated prompt containing:
   - topic path;
   - worktree path;
   - ledger path;
   - current task packet;
   - allowed and forbidden paths;
   - verification rules;
   - required output format.
7. Wait for the OpenCode process to become idle.
8. Check required artifacts.
9. Check `git diff --name-only` against forbidden paths.
10. Mark the task `done`, `failed`, or `needs-human-review`.
11. Close the Gemma/OpenCode process.
12. Continue to the next task.

The orchestrator may summarize the worker's findings, but it must not rewrite
the findings as if it reviewed the code.

## Gemma Worker Contract

Every Gemma worker prompt should include these rules:

```text
You are a fast, narrow research worker.

You have one task. Do not broaden it.
Read topic.yaml, ledger.md, relevant findings, and the current task before
acting.

Do not edit forbidden paths.
Do not change E2E scenario tests unless the task explicitly permits it.
Do not merge, commit, rebase, push, or delete the worktree.

Before changing code:
1. Record baseline evidence when the task requires it.
2. State the smallest change you will try.

After changing code:
1. Run the exact verification command required by the task or topic.
2. Compare against baseline when applicable.
3. Write findings.md.
4. Append a short entry to ledger.md.
5. Stop.
```

The required findings shape:

```markdown
# Findings: 002-cache-inspection

## Result

status: done|failed|needs-human-review

## Summary

One short paragraph stating what the task proved or failed to prove.

## Evidence

- Baseline:
- Change attempted:
- Verification:
- Timing delta:

## Files Changed

- `tests/E2E/Support/Example.php` or `none`

## Risks

- `none` or one concrete risk.

## Next Suggested Task

One concrete next step, or `none`.
```

## Guardrails

The orchestrator must fail or mark `needs-human-review` when:

- required findings or log files are missing;
- forbidden paths changed;
- E2E scenario tests changed without explicit permission;
- the verification command was skipped;
- test count or assertion count drops for a benchmark task;
- Gemma changes the topic contract;
- the worktree cannot be left intact.

The orchestrator should preserve failed worktrees. Failed attempts can still
contain useful evidence.

## Morning Review

Morning review is a human/strong-agent activity. It reads:

- `topic.yaml`
- `ledger.md`
- each task's findings
- `git diff` in the shared worktree
- verification logs

Possible decisions:

- promote the work to a normal Orbit implementation/review lane;
- add follow-up research tasks;
- reset the topic worktree and keep the findings;
- discard the topic as not useful.

## First Prototype Topic

The first topic should be read-only:

```text
Measure current Docker E2E timing bottlenecks.
```

This validates Solo -> OpenCode -> Gemma -> findings -> ledger without letting
Gemma edit code yet.

## Open Questions

- Should task status live in each task YAML file, or in a separate
  orchestrator-owned state file to keep topic packets immutable?
- Should the first orchestrator be a shell/PHP script launched by Solo, or a
  very small Solo agent prompt that uses Solo MCP directly?
- Should Gemma workers receive only recent findings, or all findings when the
  topic is still short?
