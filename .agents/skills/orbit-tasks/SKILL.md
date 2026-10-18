---
name: orbit-tasks
description: Use when preparing, implementing, or reviewing an Orbit task group.
---

# Orbit Tasks

The Gateway Tasks engine is generic. This file is Orbit's repository policy for task groups. The [quality bar](../../../AGENTS.md#quality-bar) and the check policy in the root `AGENTS.md` apply to every subtask. The Gateway's prompts carry the turn command, the blocking rule, and the handoff mechanics, so this file does not repeat them.

## Plan a group

Start after [grill-with-docs](../grill-with-docs/SKILL.md), once the behaviour is agreed. The ADRs and documentation on the group's branch are the contract that every subtask implements.

- Plan at most five subtasks. A feature that needs more becomes several groups, and each group's pull request merges before the next group starts.
- Give each subtask one goal that one implementation session can finish and verify, with at most five deliverables. Split a subtask when its acceptance would likely need another session.
- Prefer a narrow vertical slice that a reviewer can verify end to end. Put a prefactoring step first. For a wide refactor, add the new form, move the callers, then remove the old form.
- Keep CI, release, and deployment work apart from product code.
- Subtasks carry no proof scripts. Behaviour tests and the review accept a subtask.
- Do not add a subtask that only runs the final gate or prepares the pull request. Every handoff runs the task check, and the last approval proposes the pull request.
- Never create a group to rebase a task branch or merge `main` into it. Orbit repairs a conflicting pull request with a fixup subtask.

### Documentation first

Every group starts with a docs subtask. Run `bin/docs-impact --base <start-commit>` once, with one `--paths` for each planned path, including paths that do not exist yet; the start commit is the merge base with `origin/main`. The docs subtask updates the impacted pages, or delivers the JSON report when nothing is impacted. The [contributor guide](../../../docs/contributor-guide.md#2-write-the-documentation) defines the report. The handoff check runs the impact check again on every later subtask.

Record a significant decision in an ADR with the status `In progress.`, as the [ADR guide](../../../docs/decisions/README.md) describes. The last subtask that completes a decision absorbs the ADR into its owning page; give that subtask a `file` deliverable for the page. An implementer absorbs an ADR only when its deliverables name that page.

### Topology and web UI

A subtask that changes Node, agent, guest, deployment, or routing code declares the test nodes it needs in `topology`, such as `["app-dev", "app-prod+ingress"]`. The reviewer proves its deliverables there; see [using-incus-topologies](../using-incus-topologies/SKILL.md). A check on shared machines, such as beast or the live Gateway, is an operator step after deploy, listed in the pull request.

A subtask that changes the web UI gets a `review` deliverable for the phone and desktop screenshots from `bin/web-verify`. The reviewer judges the phone layout as [verifying-web-ui](../verifying-web-ui/SKILL.md) describes.

### Bugs

The first code subtask of a bug group adds a `command` deliverable that fails on its start commit and passes on the working tree. Set `fails_on_base` to the JSON boolean `true`, and list in `paths` every new or changed file the base run needs. If the command runs `pest --parallel`, give it one test path, with `--filter` if needed, because ParaTest takes only one path:

```json
{"id": "layout-repro", "type": "command", "description": "The home-screen regression fails before the fix", "command": "vendor/bin/pest tests/Feature/HomeScreenTest.php --filter='home screen layout'", "directory": "apps/gateway", "fails_on_base": true, "paths": ["apps/gateway/tests/Feature/HomeScreenTest.php"]}
```

When no command can reproduce the bug, say so in the brief and add a `review` deliverable for the manual check instead. The [tasks reference](../../../docs/reference/tasks.md#prove-a-command-fails-on-the-start-commit) defines the two runs.

## Write the briefs

Use this shape for every subtask brief:

```markdown
**Goal:** the behaviour this subtask makes work, in one or two sentences.

**Evidence:** what shows the problem or the need, with file:line references and data.

**Read first:** the ADR sections and reference pages it implements, with anchors.

**Risk:** what this change could break, for the reviewer to check first.

**Acceptance:** the tests that must fail on the start commit and pass after the change, and the checks that must pass.

**Out of scope:** what this subtask must not touch.
```

Name implementation paths only where the contract fixes them. Express each promised result as a typed deliverable; the [tasks reference](../../../docs/reference/tasks.md#deliverables) defines `file`, `command`, and `review`. A measurement or investigation result is a `command` deliverable, so the handoff check records its output.

## Agree and create the group

Before you move the group to Todo, show the operator a numbered list: each subtask's title, what it builds on, its goal, and its deliverables. Ask whether the granularity and the dependencies are right, and revise until the operator approves.

Create the group in Backlog with `tasks-create` for the Orbit Project, and its subtasks with `tasks-subtask-create`.

## Implement a subtask

- Keep evidence, such as logs and check output, under `.orbit-artifacts/`. Git ignores it.
- Build test fixtures from real output.
- When the handoff check fails, read the failed step's log and fix the cause before you hand off again.

## Review a subtask

Check the risk that the brief names first, then the quality bar. The review prompt's blocking rule decides what blocks.

The reviewer whose approval opens the pull request puts any open follow-up that users must know about into `--pr-summary`. The operator decides which follow-ups become a later group.
