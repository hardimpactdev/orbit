# Auto Research Design

## Goal

Create Auto Research as a standalone local product for running systematic,
agent-driven research loops across multiple software projects.

Orbit is the first project managed by Auto Research, but the research loop must
not be Orbit-specific. Future projects should be registered by adding a project
packet, topic packets, and verification rules.

The aim is not autonomous product development. The aim is repeatable research:
baseline a project behavior, try small changes in isolated worktrees, verify
with project-owned checks, and leave evidence for morning review.

## Product Shape

Auto Research lives outside project repositories:

```text
~/auto-research/
  README.md
  config.yaml
  templates/
  projects/
```

Each registered project owns its own configuration, topics, findings, logs, and
research worktrees. Worktrees live under each project directory, so a project
can be moved, archived, or inspected as one unit. Multiple projects can run
research loops at the same time because their state and worktrees are isolated.

## Current First Project

Orbit is the first Auto Research project because it already has useful
verification surfaces:

- `composer test:e2e`, `composer test:e2e:docker`,
  `composer test:e2e:incus`, and `composer test:e2e:provision` for real
  infrastructure behavior.
- E2E timing output through `ORBIT_E2E_TIMINGS=1`.
- `doctor` for convergence and security posture.
- `profile` for request timing.
- activity logs for auditability.
- Solo agent orchestration and process lifecycle tools.

Those surfaces belong to the Orbit project packet. They are not built into Auto
Research itself.

## Runtime Decisions

- Auto Research is a standalone directory and future standalone repository.
- Solo owns orchestration in v1.
- Worker tool and model selection are runtime configuration.
- No specific worker provider, model, or coding tool is part of the product
  contract.
- Each project can define its own verification commands, immutable paths, and
  forbidden scopes.
- Each topic has exactly one persistent shared worktree.
- Each task gets a fresh worker session.
- A topic ledger carries durable context between fresh worker sessions.
- Workers may leave code changes in the topic worktree when the task allows
  edits.
- Workers must not merge, rebase, delete worktrees, or push branches.
- Workers must not edit immutable verification scenarios unless the topic
  explicitly allows it.
- The orchestrator checks mechanical completion, required artifacts, forbidden
  path changes, and verification evidence.
- Humans and stronger agents do morning review and decide whether to merge,
  revise, continue, or discard a result.

## Non-Goals

- No hosted service in v1.
- No UI in v1.
- No autonomous merging.
- No autonomous project registration.
- No autonomous generation of new app ideas.
- No standing live-node verification lane unless a project explicitly defines
  one.
- No long-lived worker sessions that accumulate stale context.
- No cross-project worktree or dependency mutation.

## Directory Shape

```text
~/auto-research/
  README.md
  config.yaml
  templates/
    project.yaml
    topic.yaml
    task.yaml
    ledger.md
    worker-task-prompt.md
    findings.md
    orchestrator.md
    morning-review.md
  projects/
    index.yaml
    orbit/
      project.yaml
      worktrees/
        e2e-docker-speed/
      topics/
        e2e-docker-speed/
          topic.yaml
          ledger.md
          tasks/
            001-baseline.yaml
          findings/
          logs/
          worktree -> ../../worktrees/e2e-docker-speed
```

## Global Config

`config.yaml` controls scheduler limits and worker runtime defaults:

```yaml
research_root: ~/auto-research

scheduler:
  max_total_workers: 2
  max_workers_per_project: 1
  max_workers_per_topic: 1

worker:
  solo_tool_type: configured-worker-tool
  model: provider/model-id

safety:
  preserve_failed_worktrees: true
  require_forbidden_path_check: true
  allow_autonomous_merge: false
  allow_autonomous_push: false
```

The worker values are deliberately operational. Topics and project packets must
not assume a specific provider, model, or coding tool.

## Project Packet

`projects/PROJECT_ID/project.yaml` describes one researchable project:

```yaml
id: orbit
name: Orbit
repo_path: ~/orbit
default_base: origin/main

immutable_paths:
  - tests/E2E/**/*.php

default_forbidden_scope:
  - tests/E2E/**/*.php

verification_profiles:
  docs:
    commands:
      - composer docs-lint
  e2e_docker:
    commands:
      - composer test:e2e:docker
  quality:
    commands:
      - composer quality-check

review:
  morning_review_owner: human
```

Project packets are human-authored. Workers may read them but must not rewrite
them.

## Topic Packet

Each topic belongs to one project:

```yaml
id: e2e-docker-speed
project_id: orbit
title: Reduce Docker E2E lane wall time
category: speed
priority: high

goal: >
  Make Docker-backed Orbit E2E faster without weakening coverage.

hypothesis: >
  Checkout/cache reuse and topology reuse are likely bottlenecks.

worktree:
  branch: research/auto/e2e-docker-speed
  base: origin/main
  path: ~/auto-research/projects/orbit/worktrees/e2e-docker-speed

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
  profile: e2e_docker
```

The topic is human-authored. Workers may read it but must not rewrite the topic
contract.

## Task Packet

Each task is smaller than the topic:

```yaml
id: 001-baseline
status: open
title: Measure current Docker E2E timing bottlenecks

prompt: >
  Run the configured baseline command exactly as written. Do not edit project
  files. Summarize timing output, pass/fail status, test count, assertion
  count, and expensive visible phases.

allowed_actions:
  - read
  - run-safe-commands

forbidden_actions:
  - edit-project-files
  - commit
  - merge
  - rebase
  - push

required_outputs:
  - findings/001-baseline.md
  - logs/001-baseline.log
```

Allowed task statuses:

- `open` - ready for the orchestrator to dispatch.
- `running` - claimed by a spawned worker.
- `done` - required artifacts exist and no mechanical guard failed.
- `failed` - worker could not complete the task or verification failed.
- `needs-human-review` - worker finished but surfaced ambiguity or risk.

The orchestrator owns status transitions. The worker reports its proposed
result in the findings file.

## Topic Ledger

`ledger.md` is the memory bridge between fresh worker sessions. It should stay
short enough for every worker to read.

```markdown
# Research Ledger: orbit/e2e-docker-speed

## Current State

Project: orbit
Shared worktree: ~/auto-research/projects/orbit/worktrees/e2e-docker-speed
Branch: research/auto/e2e-docker-speed

## Durable Findings

- No completed tasks yet.

## Active Patch State

- No code changes yet.
- Immutable verification scenarios untouched.

## Open Questions

- Which Docker E2E phase dominates the baseline?
```

The worker appends a short ledger entry after each task. It must not replace
the whole ledger.

## Orchestration Loop

The Solo orchestrator is mechanical:

1. Read `~/auto-research/config.yaml`.
2. Read `projects/index.yaml`.
3. Select runnable projects and topics within scheduler limits.
4. For each selected topic, create its worktree if missing.
5. Select the first `open` task for that topic.
6. Mark the task `running`.
7. Spawn the configured worker through Solo.
8. Send a generated prompt containing:
   - project path;
   - topic path;
   - worktree path;
   - ledger path;
   - current task packet;
   - allowed and forbidden paths;
   - verification rules;
   - required output format.
9. Wait for the worker process to become idle.
10. Check required artifacts.
11. Check `git diff --name-only` and untracked files against forbidden paths.
12. Mark the task `done`, `failed`, or `needs-human-review`.
13. Close the worker process.
14. Continue only when scheduler limits and topic state allow it.

The orchestrator may summarize a worker's findings, but it must not rewrite
findings as if it reviewed the code.

## Worker Contract

Every worker prompt should include these rules:

```text
You are a fast, narrow Auto Research worker.

You have one task. Do not broaden it.
Read the project packet, topic packet, ledger, relevant findings, and current
task before acting.

Do not edit forbidden paths.
Do not change immutable verification scenarios unless the task explicitly
permits it.
Do not merge, commit, rebase, push, or delete the worktree.
Do not rewrite project.yaml or topic.yaml.

Before changing code:
1. Record baseline evidence when the task requires it.
2. State the smallest change you will try.

After changing code:
1. Run the exact verification command required by the task or topic.
2. Compare against baseline when applicable.
3. Write the required findings file.
4. Append one short entry to ledger.md.
5. Stop.
```

## Findings Shape

```markdown
# Findings: 001-baseline

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

- `none`

## Risks

- `none`

## Next Suggested Task

One concrete next step, or `none`.
```

## Morning Review

Morning review is a human/strong-agent activity. It reads:

- project packet;
- topic packet;
- ledger;
- each task's findings;
- `git diff` in the shared worktree;
- verification logs.

Possible decisions:

- promote the work to a normal implementation/review lane in the project;
- add follow-up research tasks;
- reset the topic worktree and keep the findings;
- discard the topic as not useful.

## First Prototype

The first project is `orbit`.

The first topic is read-only:

```text
Measure current Docker E2E timing bottlenecks.
```

This validates Auto Research project registration, Solo orchestration,
configured workers, findings, and ledgers without allowing project code edits.

## Open Questions

- Should Auto Research become a dedicated git repository immediately, or start
  as a local directory until the first overnight run proves the shape?
- Should the first scheduler be only a prompt-driven Solo orchestrator, or a
  small script that uses Solo MCP after the prompt shape is proven?
