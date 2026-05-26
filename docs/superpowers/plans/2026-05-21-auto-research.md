# Auto Research Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the first usable Auto Research product directory, with Orbit registered as the first project and a read-only Orbit timing topic as the first smoke run.

**Architecture:** Auto Research lives outside application repositories under `~/auto-research`. It stores reusable templates, global scheduler config, per-project packets, per-topic state, findings, logs, and project-scoped research worktrees. Solo runs the orchestrator and spawns a configurable worker per task.

**Tech Stack:** Solo MCP, configurable Solo worker tool, configurable worker model, git worktrees, Markdown/YAML packets, project-owned verification commands.

---

## Reference Contract

- Design spec: `docs/superpowers/specs/2026-05-21-auto-research-design.md`
- Existing Solo orchestration reference: `docs/superpowers/plans/solo-orchestration/README.md`
- Existing dispatch reference: `docs/superpowers/plans/solo-orchestration/references/dispatch-protocol.md`

## Scope

This plan creates the standalone Auto Research local product directory and
bootstraps Orbit as its first project. It does not add an Orbit CLI command and
does not change Orbit application code.

## Complexity

Files: 16 external Auto Research files plus this plan/spec | Modules:
standalone product skeleton, templates, project registry, Orbit project packet,
first topic | Risk: Low for docs/templates, Medium for the first real run
because it consumes project verification resources.

## File Map

Create external Auto Research files:

- `~/auto-research/README.md` - product overview and operating model.
- `~/auto-research/.gitignore` - keeps project worktrees out of the Auto
  Research repository.
- `~/auto-research/config.yaml` - global scheduler and worker runtime config.
- `~/auto-research/templates/project.yaml` - project packet template.
- `~/auto-research/templates/topic.yaml` - topic packet template.
- `~/auto-research/templates/task.yaml` - task packet template.
- `~/auto-research/templates/ledger.md` - topic ledger template.
- `~/auto-research/templates/worker-task-prompt.md` - worker prompt template.
- `~/auto-research/templates/findings.md` - required findings template.
- `~/auto-research/templates/orchestrator.md` - Solo orchestrator prompt.
- `~/auto-research/templates/morning-review.md` - review checklist.
- `~/auto-research/projects/index.yaml` - registered project list.
- `~/auto-research/projects/orbit/project.yaml` - Orbit project packet.
- `~/auto-research/projects/orbit/topics/e2e-docker-speed/topic.yaml` - first Orbit topic.
- `~/auto-research/projects/orbit/topics/e2e-docker-speed/ledger.md` - first topic ledger.
- `~/auto-research/projects/orbit/topics/e2e-docker-speed/tasks/001-baseline.yaml` - first read-only task.
- `~/auto-research/projects/orbit/topics/e2e-docker-speed/findings/.gitkeep`
- `~/auto-research/projects/orbit/topics/e2e-docker-speed/logs/.gitkeep`
- `~/auto-research/projects/orbit/worktrees/e2e-docker-speed` - first topic worktree.
- `~/auto-research/projects/orbit/topics/e2e-docker-speed/worktree` - symlink to the worktree.

## Implementation Tasks

### Task 1: Create the Standalone Auto Research Skeleton

**Files:**
- Create: `~/auto-research/README.md`
- Create: `~/auto-research/.gitignore`
- Create: `~/auto-research/config.yaml`

- [ ] **Step 1: Create the product directories**

Run:

```bash
mkdir -p ~/auto-research/templates
mkdir -p ~/auto-research/projects
```

Expected: the two directories exist.

- [ ] **Step 2: Initialize the Auto Research repository**

Run:

```bash
git -C ~/auto-research init
```

Expected: `~/auto-research/.git` exists.

- [ ] **Step 3: Add the product README**

Create `~/auto-research/README.md` with:

```markdown
# Auto Research

Auto Research is a standalone local research lab for running systematic
agent-driven research loops across software projects.

## Core Shape

- Projects are registered under `projects/`.
- Each project owns its research topics, findings, logs, and worktrees.
- One topic owns one persistent shared worktree.
- Each task gets one fresh Solo-spawned worker process.
- The topic ledger carries durable context between fresh worker sessions.
- The orchestrator is mechanical: it dispatches tasks, checks required
  artifacts, checks forbidden-path changes, and closes workers.
- Morning review is human or strong-agent work.
- Auto Research does not autonomously merge, push, rebase, or delete
  worktrees.

## Directory Shape

```text
~/auto-research/
  config.yaml
  templates/
  projects/
    index.yaml
    PROJECT_ID/
      project.yaml
      topics/
        TOPIC_ID/
          topic.yaml
          ledger.md
          tasks/
          findings/
          logs/
          worktree -> ../../worktrees/TOPIC_ID
      worktrees/
        TOPIC_ID/
```

## First Project

`orbit` is the first registered project. It proves the loop with a read-only
timing research topic before any code-mutating task is allowed.
```

Expected: the README describes Auto Research as standalone and Orbit as only
the first project.

- [ ] **Step 4: Add the repository ignore file**

Create `~/auto-research/.gitignore` with:

```gitignore
projects/*/worktrees/
```

Expected: project worktrees are ignored while topic packets, ledgers, findings,
and logs remain trackable.

- [ ] **Step 5: Add global config**

Create `~/auto-research/config.yaml` with:

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

Expected: the config keeps worker runtime configurable and does not name a
specific provider.

- [ ] **Step 6: Commit the skeleton**

```bash
git -C ~/auto-research add README.md .gitignore config.yaml
git -C ~/auto-research commit -m "docs: add auto research skeleton"
```

Expected: the initial Auto Research commit exists.

### Task 2: Add Reusable Packet Templates

**Files:**
- Create: `~/auto-research/templates/project.yaml`
- Create: `~/auto-research/templates/topic.yaml`
- Create: `~/auto-research/templates/task.yaml`
- Create: `~/auto-research/templates/ledger.md`

- [ ] **Step 1: Add the project packet template**

Create `~/auto-research/templates/project.yaml` with:

```yaml
id: project-id
name: Project Name
repo_path: ~/path/to/project
default_base: origin/main

immutable_paths:
  - tests/E2E/**/*.php

default_forbidden_scope:
  - tests/E2E/**/*.php

verification_profiles:
  smoke:
    commands:
      - vendor/bin/test-command

review:
  morning_review_owner: human
```

- [ ] **Step 2: Add the topic packet template**

Create `~/auto-research/templates/topic.yaml` with:

```yaml
id: topic-id
project_id: project-id
title: Short research topic title
category: speed
priority: medium

goal: >
  State the improvement target.

hypothesis: >
  State the testable hypothesis.

worktree:
  branch: research/auto/topic-id
  base: origin/main
  path: ~/auto-research/projects/project-id/worktrees/topic-id

immutable_paths: []

allowed_scope: []

forbidden_scope: []

baseline:
  command: vendor/bin/test-command
  runs: 1

success:
  require_all_runs_passing: true
  require_no_forbidden_path_changes: true

verification:
  profile: smoke
```

- [ ] **Step 3: Add the task packet template**

Create `~/auto-research/templates/task.yaml` with:

```yaml
id: 001-baseline
status: open
title: Measure the current baseline

prompt: >
  Run the configured baseline command exactly as written. Do not edit project
  files. Summarize pass/fail status, relevant counts, and visible timing data.

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

- [ ] **Step 4: Add the ledger template**

Create `~/auto-research/templates/ledger.md` with:

```markdown
# Research Ledger: project-id/topic-id

## Current State

Project: project-id
Shared worktree: ~/auto-research/projects/project-id/worktrees/topic-id
Branch: research/auto/topic-id

## Durable Findings

- No completed tasks yet.

## Active Patch State

- No code changes yet.
- Immutable verification scenarios untouched.

## Open Questions

- What should the next worker measure?
```

- [ ] **Step 5: Verify template tokens**

Run:

```bash
rg -n "project-id|topic-id|provider/model-id|immutable_paths|Research Ledger" ~/auto-research
```

Expected: the tokens appear only in templates or generic config.

- [ ] **Step 6: Commit packet templates**

```bash
git -C ~/auto-research add templates/project.yaml templates/topic.yaml templates/task.yaml templates/ledger.md
git -C ~/auto-research commit -m "docs: add auto research packet templates"
```

### Task 3: Add Worker, Findings, Orchestrator, and Review Templates

**Files:**
- Create: `~/auto-research/templates/worker-task-prompt.md`
- Create: `~/auto-research/templates/findings.md`
- Create: `~/auto-research/templates/orchestrator.md`
- Create: `~/auto-research/templates/morning-review.md`

- [ ] **Step 1: Add the worker prompt template**

Create `~/auto-research/templates/worker-task-prompt.md` with:

```markdown
# Auto Research Worker Prompt

You are a fast, narrow Auto Research worker running through a configured Solo
worker tool.

## Inputs

- Project path: PROJECT_PATH
- Topic path: TOPIC_PATH
- Worktree path: WORKTREE_PATH
- Ledger path: LEDGER_PATH
- Task path: TASK_PATH

## Required Reading Order

1. Read PROJECT_PATH.
2. Read TOPIC_PATH.
3. Read LEDGER_PATH.
4. Read relevant prior findings under the topic's `findings/` directory.
5. Read TASK_PATH.
6. Inspect only the worktree paths needed for the task.

## Hard Rules

- You have one task. Do not broaden it.
- Do not edit forbidden paths from the topic packet.
- Do not change immutable verification scenarios unless the task explicitly
  permits it.
- Do not merge, commit, rebase, push, or delete the worktree.
- Do not rewrite `project.yaml` or `topic.yaml`.
- If the task is read-only, do not edit any file except required findings,
  logs, and ledger files in the topic directory.
- If you cannot complete verification, write `needs-human-review` with the
  exact blocker.

## Work Procedure

1. Restate the task in one sentence.
2. If the task requires a baseline, run the exact baseline command from
   `topic.yaml`.
3. Record command output in the required log file.
4. Make only the smallest allowed change, if the task permits edits.
5. Run the exact verification command required by the topic or task.
6. Compare against baseline when applicable.
7. Write the required findings file.
8. Append one short durable finding to `ledger.md`.
9. Stop.

## Findings Status Values

Use exactly one:

- `done`
- `failed`
- `needs-human-review`
```

- [ ] **Step 2: Add the findings template**

Create `~/auto-research/templates/findings.md` with:

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

- [ ] **Step 3: Add the orchestrator prompt**

Create `~/auto-research/templates/orchestrator.md` with:

```markdown
# Auto Research Orchestrator Prompt

You are the mechanical orchestrator for Auto Research.

## Procedure

1. Read `~/auto-research/config.yaml`.
2. Stop with `NEEDS_WORKER_CONFIG` when `worker.solo_tool_type` is
   `configured-worker-tool` or `worker.model` is `provider/model-id`.
3. Read `~/auto-research/projects/index.yaml`.
4. Select runnable project topics without exceeding:
   - `scheduler.max_total_workers`
   - `scheduler.max_workers_per_project`
   - `scheduler.max_workers_per_topic`
5. For each selected topic, read:
   - `projects/PROJECT_ID/project.yaml`
   - `projects/PROJECT_ID/topics/TOPIC_ID/topic.yaml`
   - `projects/PROJECT_ID/topics/TOPIC_ID/ledger.md`
   - `projects/PROJECT_ID/topics/TOPIC_ID/tasks/*.yaml`
6. If the topic worktree symlink is missing, stop that topic with
   `NEEDS_WORKTREE_SETUP`.
7. Select the first task whose `status` is `open`.
8. Mark the selected task `running` by editing only its `status` value.
9. Call Solo `list_agent_tools` and resolve the enabled tool whose `tool_type`
   matches `worker.solo_tool_type`.
10. Spawn a Solo agent named `AUTO-RESEARCH PROJECT_ID TOPIC_ID TASK_ID` with
    the configured worker runtime arguments.
11. Send the worker prompt from `templates/worker-task-prompt.md` after
    replacing:
    - PROJECT_PATH
    - TOPIC_PATH
    - WORKTREE_PATH
    - LEDGER_PATH
    - TASK_PATH
12. Wait until the worker process becomes idle.
13. Read the required findings and log paths listed by the task.
14. Check the shared worktree diff:

    ```bash
    git -C WORKTREE_PATH diff --name-only
    git -C WORKTREE_PATH ls-files --others --exclude-standard
    ```

15. Compare changed paths with `forbidden_scope`, `immutable_paths`, and the
    project packet defaults.
16. Mark the task:
    - `done` when required artifacts exist, findings status is `done`, and no
      forbidden paths changed.
    - `failed` when required artifacts are missing or findings status is
      `failed`.
    - `needs-human-review` when forbidden paths changed, findings status is
      `needs-human-review`, verification was skipped, or the result is
      ambiguous.
17. Close the worker process.
18. Continue only when scheduler limits and topic state allow it.

## Boundaries

- Do not review code quality.
- Do not modify files in a project worktree.
- Do not merge, commit, rebase, push, or delete worktrees.
- Do not rewrite findings. Read them only to classify mechanical completion.
- Preserve failed worktrees.
```

- [ ] **Step 4: Add the morning review template**

Create `~/auto-research/templates/morning-review.md` with:

```markdown
# Morning Review: PROJECT_ID/TOPIC_ID

## Inputs

- Project: PROJECT_PATH
- Topic: TOPIC_PATH
- Ledger: LEDGER_PATH
- Worktree: WORKTREE_PATH

## Checklist

- [ ] Read `project.yaml`.
- [ ] Read `topic.yaml`.
- [ ] Read `ledger.md`.
- [ ] Read every finding under `findings/`.
- [ ] Inspect `git -C WORKTREE_PATH status --short --untracked-files=all`.
- [ ] Inspect `git -C WORKTREE_PATH diff --stat`.
- [ ] Inspect full diffs for changed production/support files.
- [ ] Confirm forbidden paths and immutable verification scenarios were not
      changed unless explicitly permitted.
- [ ] Confirm verification commands, counts, and timing deltas are recorded.

## Decision

Choose one:

- Promote to the project's normal implementation/review lane.
- Add follow-up research tasks.
- Reset topic worktree and keep findings.
- Discard topic result.

## Notes

Record the decision, reasoning, and any follow-up task ids here.
```

- [ ] **Step 5: Verify guardrails**

Run:

```bash
rg -n "Do not merge|Do not rewrite|NEEDS_WORKER_CONFIG|scheduler.max_workers_per_project|Promote to the project's normal" ~/auto-research/templates
```

Expected: every guardrail phrase appears.

- [ ] **Step 6: Commit role templates**

```bash
git -C ~/auto-research add templates/worker-task-prompt.md templates/findings.md templates/orchestrator.md templates/morning-review.md
git -C ~/auto-research commit -m "docs: add auto research role templates"
```

### Task 4: Register Orbit as the First Project

**Files:**
- Create: `~/auto-research/projects/index.yaml`
- Create: `~/auto-research/projects/orbit/project.yaml`

- [ ] **Step 1: Create the Orbit project directory**

Run:

```bash
mkdir -p ~/auto-research/projects/orbit/topics
```

Expected: the Orbit project directory exists.

- [ ] **Step 2: Add the project index**

Create `~/auto-research/projects/index.yaml` with:

```yaml
projects:
  - id: orbit
    path: ~/auto-research/projects/orbit/project.yaml
    enabled: true
```

- [ ] **Step 3: Add the Orbit project packet**

Create `~/auto-research/projects/orbit/project.yaml` with:

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

Expected: Orbit-specific commands live only in Orbit's project packet.

- [ ] **Step 4: Verify project registration**

Run:

```bash
rg -n "id: orbit|repo_path: ~/orbit|e2e_docker|composer test:e2e:docker" ~/auto-research/projects
```

Expected: all terms appear in the Orbit project files.

- [ ] **Step 5: Commit Orbit registration**

```bash
git -C ~/auto-research add projects/index.yaml projects/orbit/project.yaml
git -C ~/auto-research commit -m "docs: register orbit project"
```

### Task 5: Add the First Orbit Research Topic

**Files:**
- Create: `~/auto-research/projects/orbit/topics/e2e-docker-speed/topic.yaml`
- Create: `~/auto-research/projects/orbit/topics/e2e-docker-speed/ledger.md`
- Create: `~/auto-research/projects/orbit/topics/e2e-docker-speed/tasks/001-baseline.yaml`
- Create: `~/auto-research/projects/orbit/topics/e2e-docker-speed/findings/.gitkeep`
- Create: `~/auto-research/projects/orbit/topics/e2e-docker-speed/logs/.gitkeep`

- [ ] **Step 1: Create the topic directories**

Run:

```bash
mkdir -p ~/auto-research/projects/orbit/topics/e2e-docker-speed/tasks
mkdir -p ~/auto-research/projects/orbit/topics/e2e-docker-speed/findings
mkdir -p ~/auto-research/projects/orbit/topics/e2e-docker-speed/logs
touch ~/auto-research/projects/orbit/topics/e2e-docker-speed/findings/.gitkeep
touch ~/auto-research/projects/orbit/topics/e2e-docker-speed/logs/.gitkeep
```

Expected: the topic directories exist.

- [ ] **Step 2: Add the Orbit topic packet**

Create `~/auto-research/projects/orbit/topics/e2e-docker-speed/topic.yaml`
with:

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
  - docs/testing/README.md
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

- [ ] **Step 3: Add the topic ledger**

Create `~/auto-research/projects/orbit/topics/e2e-docker-speed/ledger.md`
with:

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

- [ ] **Step 4: Add the read-only baseline task**

Create `~/auto-research/projects/orbit/topics/e2e-docker-speed/tasks/001-baseline.yaml`
with:

```yaml
id: 001-baseline
status: open
title: Measure current Docker E2E timing bottlenecks

prompt: >
  Run the configured baseline command exactly as written. Do not edit files.
  Summarize timing output, pass/fail status, test count, assertion count, and
  the most expensive visible phases.

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

- [ ] **Step 5: Commit the first Orbit topic**

```bash
git -C ~/auto-research add projects/orbit/topics/e2e-docker-speed
git -C ~/auto-research commit -m "docs: add first orbit research topic"
```

### Task 6: Create the First Topic Worktree

**Files:**
- Create: `~/auto-research/projects/orbit/worktrees/e2e-docker-speed`
- Create: `~/auto-research/projects/orbit/topics/e2e-docker-speed/worktree`
- Modify: `~/auto-research/projects/orbit/topics/e2e-docker-speed/ledger.md`

- [ ] **Step 1: Create the worktree parent directory**

Run:

```bash
mkdir -p ~/auto-research/projects/orbit/worktrees
```

Expected: the parent directory exists.

- [ ] **Step 2: Create the shared topic worktree**

Run from the Orbit repo:

```bash
git fetch origin main
git worktree add -b research/auto/e2e-docker-speed ~/auto-research/projects/orbit/worktrees/e2e-docker-speed origin/main
```

Expected: the worktree exists on branch `research/auto/e2e-docker-speed`.

- [ ] **Step 3: Create the topic symlink**

Run:

```bash
ln -s ../../worktrees/e2e-docker-speed ~/auto-research/projects/orbit/topics/e2e-docker-speed/worktree
```

Expected: the topic symlink points to the shared worktree.

- [ ] **Step 4: Verify the worktree state**

Run:

```bash
git -C ~/auto-research/projects/orbit/worktrees/e2e-docker-speed status --short --branch --untracked-files=all
test -e ~/auto-research/projects/orbit/topics/e2e-docker-speed/worktree/.git
```

Expected:

- branch line contains `research/auto/e2e-docker-speed`;
- no modified or untracked files are present in the worktree;
- `test` exits `0`.

- [ ] **Step 5: Record bootstrap evidence**

Append this entry to
`~/auto-research/projects/orbit/topics/e2e-docker-speed/ledger.md`:

```markdown

## Bootstrap

- Created shared worktree `~/auto-research/projects/orbit/worktrees/e2e-docker-speed` from `origin/main`.
- First task is read-only baseline measurement.
```

- [ ] **Step 6: Commit the worktree metadata**

```bash
git -C ~/auto-research add projects/orbit/topics/e2e-docker-speed/ledger.md projects/orbit/topics/e2e-docker-speed/worktree
git -C ~/auto-research commit -m "docs: record orbit topic worktree"
```

### Task 7: Configure and Smoke Test the Worker Runtime

**Files:**
- Modify: `~/auto-research/config.yaml`
- Modify: `~/auto-research/projects/orbit/topics/e2e-docker-speed/ledger.md`

- [ ] **Step 1: Set the worker runtime**

Edit `~/auto-research/config.yaml` so:

```yaml
worker:
  solo_tool_type: configured-worker-tool
  model: provider/model-id
```

uses the worker tool type and model selected for this run.

Expected: neither `configured-worker-tool` nor `provider/model-id` remains in
the active config.

- [ ] **Step 2: Confirm the configured Solo worker tool exists**

Use Solo MCP `list_agent_tools`.

Expected: one enabled tool has `tool_type` equal to
`worker.solo_tool_type`.

- [ ] **Step 3: Confirm the worker tool can run a trivial prompt**

Use Solo MCP `spawn_agent` with the configured tool id and configured runtime
arguments:

```json
{
  "agent_tool_id": "WORKER_TOOL_ID",
  "name": "AUTO-RESEARCH-WORKER-SMOKE",
  "extra_args": ["--model", "CONFIGURED_WORKER_MODEL"],
  "include_agent_instructions": false
}
```

Then use Solo MCP `send_input`:

```text
Reply with exactly: auto-research-solo-ok
```

Expected: the process output eventually contains:

```text
auto-research-solo-ok
```

- [ ] **Step 4: Close the smoke worker**

Use Solo MCP `close_process` against the returned process id.

Expected: the process is no longer available through `get_process_status`.

- [ ] **Step 5: Append smoke evidence**

Append this entry to
`~/auto-research/projects/orbit/topics/e2e-docker-speed/ledger.md`:

```markdown

## Worker Runtime Smoke Test

- Solo-spawned worker with configured `worker.solo_tool_type` and `worker.model` returned `auto-research-solo-ok`.
```

- [ ] **Step 6: Commit runtime smoke evidence**

```bash
git -C ~/auto-research add config.yaml projects/orbit/topics/e2e-docker-speed/ledger.md
git -C ~/auto-research commit -m "docs: record worker runtime smoke"
```

### Task 8: Run the First Read-Only Orbit Research Task

**Files:**
- Modify: `~/auto-research/projects/orbit/topics/e2e-docker-speed/tasks/001-baseline.yaml`
- Create: `~/auto-research/projects/orbit/topics/e2e-docker-speed/findings/001-baseline.md`
- Create: `~/auto-research/projects/orbit/topics/e2e-docker-speed/logs/001-baseline.log`
- Modify: `~/auto-research/projects/orbit/topics/e2e-docker-speed/ledger.md`

- [ ] **Step 1: Manually dispatch the first task**

Follow `~/auto-research/templates/orchestrator.md` for only:

```text
project: orbit
topic: e2e-docker-speed
task: 001-baseline
```

Expected: one configured research worker runs in the shared worktree and then
becomes idle.

- [ ] **Step 2: Verify required artifacts exist**

Run:

```bash
test -f ~/auto-research/projects/orbit/topics/e2e-docker-speed/findings/001-baseline.md
test -f ~/auto-research/projects/orbit/topics/e2e-docker-speed/logs/001-baseline.log
```

Expected: both commands exit `0`.

- [ ] **Step 3: Verify the task stayed read-only**

Run:

```bash
git -C ~/auto-research/projects/orbit/worktrees/e2e-docker-speed status --short --untracked-files=all
```

Expected: no modified or untracked files in the worktree.

- [ ] **Step 4: Verify the findings classify the result**

Run:

```bash
rg -n "status: (done|failed|needs-human-review)|Timing delta|Verification|Next Suggested Task" ~/auto-research/projects/orbit/topics/e2e-docker-speed/findings/001-baseline.md
```

Expected: all fields are present.

- [ ] **Step 5: Mark the task status mechanically**

If Step 2 and Step 3 passed, edit only the task status line:

```yaml
status: done
```

If the findings file says `failed`, edit it to:

```yaml
status: failed
```

If the findings file says `needs-human-review`, edit it to:

```yaml
status: needs-human-review
```

Expected: the task packet status matches the mechanical result.

- [ ] **Step 6: Commit the first research result**

```bash
git -C ~/auto-research add projects/orbit/topics/e2e-docker-speed
git -C ~/auto-research commit -m "docs: record first orbit research result"
```

### Task 9: Final Verification and Handoff

**Files:**
- Inspect: `~/auto-research`
- Inspect: `~/auto-research/projects/orbit/topics/e2e-docker-speed`
- Inspect: `~/auto-research/projects/orbit/worktrees/e2e-docker-speed`

- [ ] **Step 1: Verify Auto Research repository status**

Run:

```bash
git -C ~/auto-research status --short --branch --untracked-files=all
```

Expected: clean status.

- [ ] **Step 2: Verify Orbit topic worktree status**

Run:

```bash
git -C ~/auto-research/projects/orbit/worktrees/e2e-docker-speed status --short --branch --untracked-files=all
```

Expected: clean status for the read-only smoke topic.

- [ ] **Step 3: Verify Orbit docs still lint**

Run from the Orbit repo:

```bash
composer docs-lint
```

Expected: `issues: 0`, `errors: 0`.

- [ ] **Step 4: Prepare the review handoff**

Write a handoff containing:

- Auto Research root path;
- registered projects;
- active topics;
- configured worker tool type and model id;
- worker smoke result;
- first task status;
- whether the Auto Research repo is clean;
- whether the first topic worktree is clean.

## Verification Summary

Run these after all tasks:

```bash
git -C ~/auto-research status --short --branch --untracked-files=all
git -C ~/auto-research/projects/orbit/worktrees/e2e-docker-speed status --short --branch --untracked-files=all
composer docs-lint
```

Expected:

- Auto Research repo is clean;
- read-only Orbit topic worktree is clean;
- Orbit docs lint passes.

## Open Questions

- Should Auto Research become a dedicated remote git repository immediately
  after the first read-only run proves the shape?
- Should the next implementation pass add a tiny scheduler script, or keep the
  first overnight run prompt-driven through Solo?
