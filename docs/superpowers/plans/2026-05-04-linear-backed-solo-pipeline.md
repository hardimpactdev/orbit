# Linear-Backed Solo Pipeline Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move Solo pipeline inventory out of the repo and into a simple Linear backlog while keeping product authority in repo docs and detailed execution state in Solo todos.

**Architecture:** Linear owns human backlog selection with three statuses: Backlog, In Progress, Done. The Solo pipeline filler reads pipeline-ready Linear issues, creates the first draft Solo todo, and scout validates/refines that draft before it can become dispatchable. `docs/PORTING.md` remains a migration ledger and product-progress tracker, not the working queue.

**Tech Stack:** Markdown orchestration prompts, Solo MCP todos/processes, Linear MCP once installed, current Orbit docs, optional temporary `docs/PORTING.md` fallback during migration.

---

## File Map

- Create: `docs/superpowers/plans/solo-orchestration/backlog-source.md`
  - Defines Linear as the backlog source, the three-status model, required labels, issue-body shape, and transition fallback.
- Modify: `docs/superpowers/plans/solo-orchestration/README.md`
  - Adds backlog-source docs to shared authority and helper role bootstrap context.
- Modify: `docs/superpowers/plans/solo-orchestration/control-config.md`
  - Adds a small `backlog` runtime config section. Default remains `source: porting` until Linear MCP is connected.
- Modify: `docs/superpowers/plans/solo-orchestration/pipeline-filler.md`
  - Changes candidate discovery from direct `PORTING.md` selection to backlog-source-driven selection. Filler drafts Solo todos first.
- Modify: `docs/superpowers/plans/solo-orchestration/todo-scout.md`
  - Makes scout validate/refine filler-created draft todos and check source issue/docs freshness.
- Modify: `docs/superpowers/plans/solo-orchestration/references/worker-todo-template.md`
  - Adds a required `Backlog Source` section so generated Solo todos can be reconciled to Linear or the temporary porting fallback.
- Optional later modify: `docs/PORTING.md`
  - After Linear is live and current queued work is represented there, remove `Todo Pipeline Hints` and keep only migration ledger content.

## Task 1: Add Backlog Source Contract

**Files:**
- Create: `docs/superpowers/plans/solo-orchestration/backlog-source.md`

- [ ] **Step 1: Create the backlog-source doc**

Create `docs/superpowers/plans/solo-orchestration/backlog-source.md` with:

```markdown
# Backlog Source

Source-of-truth contract for work candidates consumed by the Solo pipeline.

## Authority Boundary

- Product behavior lives in current repo docs, especially `docs/commands/**`,
  `docs/BLUEPRINT.md`, `docs/MISSION.md`, `docs/CONCEPTS.md`, and
  `docs/BUILDING-BLOCKS.md`.
- Porting progress lives in `docs/PORTING.md`.
- Human backlog priority lives in Linear.
- Execution detail lives in Solo todos, comments, tags, blockers, locks, and
  process state.

Linear is not product authority. Linear issues point to product authority.
Solo todos are not backlog authority. Solo todos are executable slices derived
from backlog issues and validated by scout.

## Linear Workflow

Use one Linear project for Orbit work and at most three workflow statuses:

- `Backlog`: candidate work not yet owned by Solo.
- `In Progress`: Solo owns at least one open todo for the issue.
- `Done`: verified Solo close-out is complete, or the issue was intentionally
  closed with a recorded reason.

Do not mirror Solo phase tags into Linear statuses.

## Labels

Use labels for selection and classification:

- `pipeline-ready`: eligible for the pipeline filler.
- `type:port`
- `type:bug`
- `type:feature`
- `type:docs-refresh`
- `type:family-review`

Additional labels may express domain, priority, or safety, but they must not
duplicate Solo lifecycle state such as `worker-ready`, `review-ready`, or
`e2e-ready`.

## Issue Body Shape

Each pipeline-ready issue should contain:

```markdown
### Objective

<Observable outcome. Describe behavior, not preferred internals.>

### Product Authority

- <repo docs paths that define expected behavior>

### Evidence

- <legacy paths, bug reports, failing output, or "none: new feature">

### Sequencing

- <human priority, dependencies, or "none">

### Stop Conditions

- <conditions that require direction instead of implementation guessing>
```

## Pipeline Filler Responsibility

When `backlog.source=linear`, the pipeline filler:

1. Reads Linear issues in `Backlog` with `pipeline-ready`.
2. Selects only enough issues to reach `pipeline.ready_target`.
3. Creates or refreshes a draft Solo todo from the Linear issue.
4. Includes a `Backlog Source` section that names the Linear issue id/link,
   status, labels, and last-known updated timestamp.
5. Moves the Linear issue to `In Progress` only after a Solo draft todo exists.
6. Spawns scout for the draft todo.

The filler makes a first useful todo draft. It does not implement, review, run
E2E, or pretend the draft is ready.

## Scout Responsibility

Scout validates and refines the filler-created draft:

1. Confirms the Linear issue still exists and is not already `Done`.
2. Confirms cited product docs exist.
3. For `type:port`, reconciles `docs/PORTING.md` with current command docs and
   relevant `../orbit-old-may` evidence.
4. Checks whether cited docs changed after the Solo draft was created or last
   scouted. If so, refreshes scope or reports `NEEDS_DIRECTION`.
5. Adds exact scope, blockers, quality gate, E2E lane, and reviewer checks.
6. Reports `SCOUT_REPORT status=READY|BLOCKED|NEEDS_DOCS|SCOPE_TOO_BROAD|NEEDS_DIRECTION`.

## Temporary Porting Fallback

Until Linear MCP is configured, `backlog.source=porting` preserves the current
behavior: the pipeline filler may read `docs/PORTING.md` directly for candidate
work. Fallback-created todos must still include `Backlog Source` with
`source=docs/PORTING.md`.

After Linear is live, switch `backlog.source` to `linear` and stop creating new
work directly from `docs/PORTING.md`.
```

- [ ] **Step 2: Verify formatting**

Run:

```bash
git diff --check -- docs/superpowers/plans/solo-orchestration/backlog-source.md
```

Expected: no output.

- [ ] **Step 3: Commit**

```bash
git add docs/superpowers/plans/solo-orchestration/backlog-source.md
git commit -m "docs: define solo backlog source"
```

## Task 2: Wire Backlog Source Into Orchestration Docs

**Files:**
- Modify: `docs/superpowers/plans/solo-orchestration/README.md`
- Modify: `docs/superpowers/plans/solo-orchestration/control-config.md`

- [ ] **Step 1: Update README role context**

In `docs/superpowers/plans/solo-orchestration/README.md`, add `backlog-source.md`
to the list of files read by helper roles when backlog selection or todo shape
matters.

Use this exact bullet in the helper bootstrap list:

```markdown
- docs/superpowers/plans/solo-orchestration/backlog-source.md when backlog selection or source reconciliation matters
```

- [ ] **Step 2: Update README shared authority**

In the `Shared Authority` section, replace the current authority list with:

```markdown
Use this evidence order when product, backlog, or architecture choices fork:

1. Current docs as product authority.
2. Linear for human backlog priority when `backlog.source=linear`.
3. `docs/PORTING.md` for migration order and tracker state.
4. `../orbit-old-may` as legacy implementation evidence.
5. Current code, tests, Solo todos, and todo comments as implementation evidence.

Current docs decide product behavior. Linear decides what humans want considered
next. Current implementation, Solo state, and the old repo are evidence, not
automatic authority.
```

- [ ] **Step 3: Add backlog config**

In `docs/superpowers/plans/solo-orchestration/control-config.md`, add this
section below `pipeline`:

```yaml
backlog:
  source: porting
  linear:
    statuses:
      backlog: Backlog
      in_progress: In Progress
      done: Done
    required_label: pipeline-ready
    project: Orbit
```

Keep `source: porting` until Linear MCP is installed and the first candidate
issues exist.

- [ ] **Step 4: Verify docs diff**

Run:

```bash
git diff --check -- docs/superpowers/plans/solo-orchestration/README.md docs/superpowers/plans/solo-orchestration/control-config.md
```

Expected: no output.

- [ ] **Step 5: Commit**

```bash
git add docs/superpowers/plans/solo-orchestration/README.md docs/superpowers/plans/solo-orchestration/control-config.md
git commit -m "docs: add backlog source config"
```

## Task 3: Update Pipeline Filler Prompt

**Files:**
- Modify: `docs/superpowers/plans/solo-orchestration/pipeline-filler.md`

- [ ] **Step 1: Add backlog-source to required reads**

In Tick Procedure step 1, add:

```markdown
   - `docs/superpowers/plans/solo-orchestration/backlog-source.md`
```

- [ ] **Step 2: Replace candidate selection behavior**

Replace current steps 4 and 5 with:

```markdown
4. Read `backlog.source` from `control-config.md`.

5. Pick only enough next candidates to reach the target:
   - If `backlog.source=linear`, use Linear issues in the configured Backlog
     status with the configured `pipeline-ready` label.
   - If `backlog.source=porting`, use `docs/PORTING.md` as the temporary
     fallback source.
   - Avoid duplicates, broad backlog, and blocked downstream work.

6. Create or refresh each candidate as `draft`. The filler makes the first
   useful Solo todo draft from the backlog issue or porting entry. Include a
   `Backlog Source` section. Use `worker-todo-template.md`; command ports need
   a paired E2E gate with `lane=e2e-provisioning`, `lane=e2e-feature`, or
   `lane=none`.
   For candidates tagged `family-review`, also use
   `family-review-todo-template.md`; they are normal worker todos and usually
   declare `lane=none`.

7. When `backlog.source=linear`, move the Linear issue to the configured
   In Progress status only after the Solo draft todo exists. Add a short Linear
   comment with the Solo todo id.
```

Renumber the remaining steps so scout dispatch, report application, process
close-out, and final report still read sequentially.

- [ ] **Step 3: Expand final report**

Add `backlog_source` and `linear_updates` to the report block:

```text
backlog_source: <linear|porting>
linear_updates:
  - <issue id/status/comment action or none>
```

- [ ] **Step 4: Verify docs diff**

Run:

```bash
git diff --check -- docs/superpowers/plans/solo-orchestration/pipeline-filler.md
```

Expected: no output.

- [ ] **Step 5: Commit**

```bash
git add docs/superpowers/plans/solo-orchestration/pipeline-filler.md
git commit -m "docs: make pipeline filler backlog-source driven"
```

## Task 4: Update Scout Prompt And Worker Template

**Files:**
- Modify: `docs/superpowers/plans/solo-orchestration/todo-scout.md`
- Modify: `docs/superpowers/plans/solo-orchestration/references/worker-todo-template.md`

- [ ] **Step 1: Add backlog-source to scout reads**

In `todo-scout.md` Tick Procedure step 1, add:

```markdown
   - `docs/superpowers/plans/solo-orchestration/backlog-source.md`
```

- [ ] **Step 2: Add source validation to scout**

In `todo-scout.md`, add this check after the assigned todo/comments read:

```markdown
2. Validate the todo's `Backlog Source` section:
   - For Linear-backed todos, confirm the issue id/link, status, labels, and
     source updated timestamp are present.
   - For temporary porting fallback todos, confirm `source=docs/PORTING.md`.
   - If the source is missing or incoherent, edit the todo when obvious or
     report `SCOUT_REPORT status=NEEDS_DIRECTION`.
```

Renumber later steps.

- [ ] **Step 3: Add freshness rule to scout**

Add this rule to scout's readiness decision:

```markdown
If cited product docs changed after the Solo draft was created or last scouted,
refresh the todo scope before reporting READY. If the docs change alters product
behavior enough that the issue is ambiguous, report `NEEDS_DIRECTION` instead
of preserving stale implementation assumptions.
```

- [ ] **Step 4: Add Backlog Source to worker template**

In `worker-todo-template.md`, add `backlog source` to the Required Shape list:

```markdown
- backlog source;
```

Add this section to the body template before `Objective`:

```markdown
### Backlog Source

- source=<linear|docs/PORTING.md>
- issue=<Linear issue id/link or none>
- source_status=<Backlog|In Progress|Done|porting-fallback>
- source_labels=<labels or none>
- source_updated_at=<timestamp or none for porting fallback>
```

- [ ] **Step 5: Verify docs diff**

Run:

```bash
git diff --check -- docs/superpowers/plans/solo-orchestration/todo-scout.md docs/superpowers/plans/solo-orchestration/references/worker-todo-template.md
```

Expected: no output.

- [ ] **Step 6: Commit**

```bash
git add docs/superpowers/plans/solo-orchestration/todo-scout.md docs/superpowers/plans/solo-orchestration/references/worker-todo-template.md
git commit -m "docs: require backlog source on solo todos"
```

## Task 5: Linear Setup Handoff

**Files:**
- No repo files unless setup findings require config adjustments.

- [ ] **Step 1: Create Linear project**

Create or identify one Linear project named `Orbit`.

Expected statuses:

```text
Backlog
In Progress
Done
```

- [ ] **Step 2: Create labels**

Create these labels:

```text
pipeline-ready
type:port
type:bug
type:feature
type:docs-refresh
type:family-review
```

- [ ] **Step 3: Create first seed issues**

Create only the next small set of issues, not the whole porting universe. Seed
with 5 to 10 candidates from `docs/PORTING.md` and active Solo queue needs.

Each issue body must include:

```markdown
### Objective

<Observable outcome.>

### Product Authority

- <docs path>

### Evidence

- <legacy path or current bug evidence>

### Sequencing

- <dependency or none>

### Stop Conditions

- <when Solo must ask for direction>
```

- [ ] **Step 4: Switch config after MCP works**

After Linear MCP can list and update the seed issues, change
`docs/superpowers/plans/solo-orchestration/control-config.md`:

```yaml
backlog:
  source: linear
```

Leave the rest of the `linear` config unchanged unless the actual project name
differs from `Orbit`.

- [ ] **Step 5: Commit config switch**

```bash
git add docs/superpowers/plans/solo-orchestration/control-config.md
git commit -m "docs: switch solo backlog source to linear"
```

## Task 6: Retire PORTING Pipeline Hints After Linear Proves Out

**Files:**
- Modify: `docs/PORTING.md`

- [ ] **Step 1: Confirm no active dependency on Todo Pipeline Hints**

Check:

```bash
rg "Todo Pipeline Hints|Current Short Queue|Gateway Forwarding Chain|App Workstream Entry Point" docs/superpowers/plans docs/PORTING.md
```

Expected: only historical references in `docs/PORTING.md` before this task.

- [ ] **Step 2: Remove operational queue details**

In `docs/PORTING.md`, remove the `Todo Pipeline Hints` section after all active
queue details are represented by Linear issues or completed Solo todos.

Keep:

- Rules
- Status Legend
- Porting Workflow
- Implementation Order
- Current Clean Implementation
- Documentation Porting
- Workstream status sections
- Testing Infrastructure
- Next Priorities, rewritten as broad migration priorities instead of todo ids

- [ ] **Step 3: Verify docs diff**

Run:

```bash
git diff --check -- docs/PORTING.md
```

Expected: no output.

- [ ] **Step 4: Commit**

```bash
git add docs/PORTING.md
git commit -m "docs: keep porting tracker out of pipeline queue"
```

## Open Questions

1. Linear MCP identity: which Linear team/project id should the pipeline use when multiple are visible?
2. Linear comments: should Solo write one summary comment per issue or append a comment per major state change?
3. Closing policy: should Linear move to Done when Solo todo is `verified`, or only after commit/push is confirmed?

## Self-Review

- Spec coverage: covers three-lane Linear, simple labels, filler drafts first, scout validates/refines, `PORTING.md` remains ledger, and safe transition from current porting fallback.
- Placeholder scan: no blocked placeholder markers or deferred-work wording remains.
- Scope check: plan is docs/orchestration only. Linear MCP installation and issue creation are an explicit handoff task, not hidden implementation work.
