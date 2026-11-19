---
name: orbit-tasks
description: Use when preparing, implementing, or reviewing an Orbit task group.
---

# Orbit Tasks

The Gateway Tasks engine is generic. This file is Orbit's repository policy for task groups. The [quality bar](../../../AGENTS.md#quality-bar) and the check policy in the root `AGENTS.md` apply to every subtask. The Gateway's prompts carry the turn receipt schema, the [reviewer rule](../../../docs/reference/tasks.md#the-reviewer-rule), and the handoff mechanics, so this file does not repeat them.

## Plan a group

Start after [grill-with-docs](../grill-with-docs/SKILL.md), once the behaviour is agreed. The ADRs and documentation on the group's branch are the contract that every subtask implements.

- Build and prove the whole feature in the group's copy before its pull request: [Build a feature in one copy](../../../docs/reference/tasks.md#build-a-feature-in-one-copy).
- Plan at most five subtasks. When a feature needs more, ask the operator before you split it into groups. Never split it into groups whose pull requests each wait for a release to find the next problem.
- Give each subtask one goal that one implementation session can finish and verify, with at most five deliverables.
- Prefer a narrow vertical slice that a reviewer can verify end to end. Put a prefactoring step first. For a wide refactor, add the new form, move the callers, then remove the old form.
- Keep CI, release, and deployment work apart from product code.
- Subtasks carry no proof scripts. Behaviour tests and the review accept a subtask.
- Never create a group to rebase a task branch or merge `main` into it. Orbit repairs a conflicting pull request with a fixup subtask.

### Split into subtasks

Plan one subtask unless a reason below calls for more. Split when:

- a later part builds on an earlier part that can be checked on its own;
- a finding or a decision must come first, and it can change what follows;
- the work is more than one session can finish and check.

Results that depend on each other, or that touch the same files, are subtasks of one group. Results that can ship alone and touch different files are separate groups.

An outcome found during the task becomes an appended subtask when it must ship in the same pull request, and a new group when it can ship alone.

The last subtask owns the full proof. These are never a subtask: the final gate, the pull request preparation, a merge of `main`, and a split by file or by layer. Every handoff runs the task check, and the last approval proposes the pull request.

### Choose the deliverables

Each deliverable checks one expected outcome. The [tasks reference](../../../docs/reference/tasks.md#deliverables) defines `file`, `command`, and `review`.

| Result | Deliverable |
| --- | --- |
| A bug that a test can reproduce | `command` with `fails_on_base` on one test file |
| A feature or an optimization | `command` that runs its tests, without `fails_on_base` |
| A reference page that must change | `file` |
| A real run, a timing, or a live proof | `review` that says what the receipt must show |

Never use a `file` deliverable on a doc page as a stand-in for a real run. A deliverable may name a test file: the file is the check, not the design. An investigation result that a command can print is a `command` deliverable, so the handoff check records its output.

### Documentation

No group starts with a fixed docs subtask. When you know the page that must change, give the subtask that changes the behaviour a `file` deliverable for it. Otherwise the docs-impact part of the handoff check names the page. To find the pages while you plan, run `bin/docs-impact --base <start-commit>` with one `--paths` for each planned path, including paths that do not exist yet; the start commit follows [its one rule](../../../docs/reference/tasks.md#start-commit). The [contributor guide](../../../docs/contributor-guide.md#2-write-the-documentation) defines the report.

The handoff check runs the impact check on every subtask. For a page that needs no update, follow [Docs-impact waivers in a copy](../../../docs/reference/tasks.md#docs-impact-waivers-in-a-copy). The reviewer confirms the reason. Orbit's publish copies the same lines into the PR body; for a pull request opened another way, copy them before READY. Private Git notes cannot waive the gate.

Record a significant decision in an ADR with the status `In progress.`, as the [ADR guide](../../../docs/decisions/README.md) describes. The last subtask that completes a decision absorbs the ADR into its owning page; give that subtask a `file` deliverable for the page. An implementer absorbs an ADR only when its deliverables name that page.

### Fleet proof and web UI

A task group cannot declare a `topology`: the Gateway refuses any nonempty list with `tasks.orchestration_unsupported`. For live behavior checks, use [verifying-commands](../verifying-commands/SKILL.md) against the copy's nested fleet before opening the PR. A check on shared machines, such as beast or the live Gateway, is an operator step after deploy, listed in the pull request; it does not replace that proof.

A subtask that changes the web UI gets a `review` deliverable for the phone and desktop screenshots from `bin/web-verify`. The reviewer judges the phone layout as [verifying-web-ui](../verifying-web-ui/SKILL.md) describes.

### Bugs

The first code subtask of a bug group adds a `command` deliverable that fails on its start commit and passes on the working tree. Set `fails_on_base` to the JSON boolean `true`, and list in `paths` every new or changed file the base run needs. If the command runs `pest --parallel`, give it one test path, with `--filter` if needed, because ParaTest takes only one path:

```json
{"id": "layout-repro", "type": "command", "description": "The home-screen regression fails before the fix", "command": "vendor/bin/pest tests/Feature/HomeScreenTest.php --filter='home screen layout'", "directory": "apps/gateway", "fails_on_base": true, "paths": ["apps/gateway/tests/Feature/HomeScreenTest.php"]}
```

When no command can reproduce the bug, say so in the brief and add a `review` deliverable for the manual check instead. The [tasks reference](../../../docs/reference/tasks.md#prove-a-command-fails-on-the-start-commit) defines the two runs.

## Write the briefs

A brief states the problem and the outcome the group must reach. The implementer derives the code from the repository. [Brief examples](references/brief-examples.md) shows real groups in this shape.

### Group brief

The title states the outcome. Problem, Expected outcome and Guardrails are always there. Open question is there only when the cause is not known. Proof is there only when tests cannot show the outcome.

```markdown
**Problem:** What is wrong or missing, and why it matters now.

**Expected outcome:**
1. A rule that holds in every case.

**Guardrails:**
- Decided: a choice that is already made or proven.
- Must hold: what must never break.
- Accepted limits: what the reviewer must not block on, and why.
- Out of scope: what the group must not touch.

**Open question:** what is not known yet, and what the handoff note must name.

**Proof:** the entry point, the starting state, the final observation, the
cases that would expose a wrong answer, what must be real, what may be a
stand-in, and the subtask that owns the full proof.
```

Guardrails holds only those four kinds of line.

### Subtask brief

Repeat nothing from the group brief. With one subtask in the group, the brief is one line:

```markdown
All expected outcomes of the group. Read first: <paths>.
```

With several subtasks:

```markdown
**Slice:** Expected outcomes <numbers>. Builds on <subtask>. After this
subtask, <what works>.

**Read first:** <paths>

**Guardrails:** <only what is specific to this slice>
```

Leave out the slice Guardrails when the slice has none.

### Writing rules

1. State the problem and the expected outcome, not the fix.
2. Put in a design only when it is already decided or proven, under Decided.
3. Problem holds facts: Operation IDs, times, numbers, and what ran at that moment. Label a guess as a guess.
4. Write each expected outcome as a rule that holds in every case, not as one transition.
5. What must keep working is an expected outcome too.
6. Give a number with its source and its two end points, such as "from the wake request to `ready_at`".
7. Put everything the implementer needs inline or in the repository. Name no path on another machine or in another repository.
8. Accepted limits say what the reviewer must not block on, with the reason.
9. Say what the implementer does with another bug found on the way: report it and commit no fix. If it blocks the proof, ask.
10. Leave out a section that has nothing to say.
11. Add no fact, path, limit or requirement that the source does not give. When the source leaves a choice open, or a keep-working outcome seems needed but the source does not state it, put it to the owner as a marked question, such as `<Owner: retry the failed step, or fail the Operation?>`. Never write it as a settled fact, outcome, guardrail or title. Word the title and the outcomes so that they hold whichever way the owner answers. A request in the source is not a fact about today: "report the error" does not say that no error is reported now.

Name implementation paths only where the contract fixes them.

### Turn prompts

A turn prompt carries review findings, new evidence, and corrections inside the agreed outcome. It adds no outcome and restates neither the brief nor these rules. One exception holds until the copy rules name the task check: the owner may tell the implementer to run the Project task check before it hands off.

## Agree and create the group

A task that the outer loop filed arrives in Backlog with a docs subtask and a placeholder test deliverable. Its owner rewrites it into the shape above before Todo.

Before you move the group to Todo, show the operator a numbered list: each subtask's title, what it builds on, the expected outcomes it owns, and the deliverable that would disprove success. Ask whether the granularity and the dependencies are right, and revise until the operator approves.

Create the parent in Backlog with `task-create` and `project_id` for the Orbit Project. Create its subtasks with the same tool and `parent_id`. Select either level by its existing Task ID. Moving the parent to Todo changes board state only. `task-start` creates a [task copy](../../../docs/reference/tasks.md#task-copy) of the Project's default container Instance. Follow that contract for start failures and removal, and [Task Instance workspace phases](../../../docs/reference/task-instance-workspace.md) for workspace checks and export. Select a child directly for `task-check` and `task-turn`.

## Implement a subtask

- Keep evidence, such as logs and check output, under `.orbit-artifacts/`. Git ignores it.
- Build test fixtures from real output.
- When the handoff check fails, read the failed step's log and fix the cause before you hand off again.

Every task read uses [recorded Gateway state](../../../docs/reference/tasks.md#recorded-snapshots-for-all-readers), including the agent viewer and stream; it does not contact the runtime. Follow [copy turn agents](../../../docs/reference/tasks.md#copy-turn-agents) for execution and evidence.

## Review a subtask

Judge the work against the group's expected outcomes and guardrails, then the quality bar. Do not block on an accepted limit. This does not widen the [reviewer rule](../../../docs/reference/tasks.md#the-reviewer-rule): it still decides what blocks. When you write the reviewer prompt for `task-turn`, do not widen that bar: the rule sets the reviewer's scope.

The reviewer whose approval opens the pull request puts any open follow-up that users must know about into the receipt's `pull_request.summary`. The operator decides which follow-ups become a later group.
