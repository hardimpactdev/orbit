# Product Decisions Intent Ledger Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `apps/docs/content/product-decisions.md` — a chronological, append-only intent ledger that anchors current product intent above the authority-doc chain — seed it, index it, and wire the four skills that capture and consume it.

**Architecture:** A single free-form top-level Markdown file is the source of intent. Three skills (`handling-feature-requests`, `implementing-features`, `updating-documentation`) gain a small step to *append* a one-line entry when a direction changes. One skill (`auditing-docs-drift`) gains steps to *consume* the ledger and pre-fill drift-fix direction. `README.md` and `AGENTS.md` (which `CLAUDE.md` symlinks to) get index/authority references.

**Tech Stack:** Markdown only. No PHP, no Pest, no JS. The only automated gate is `composer docs-lint` (must not regress); everything else is verified by `grep`.

---

## Notes for the executor (read first)

- **This is a docs + skills change. There is no TDD loop and no new test.** The
  spec deliberately keeps the ledger free-form and out of the linted paths, so
  there is no Librarian rule and no Pest test to add. Each task below is
  *edit → verify by grep/lint → commit*. Do not invent tests or linter rules —
  that is explicit scope creep beyond the approved spec
  (`docs/superpowers/specs/2026-06-03-product-decisions-design.md`).
- **No worktree needed.** Unlike normal `implementing-features` work, nothing
  here touches PHP or runs Pest, so the worktree + `composer install` +
  `key:generate` bootstrap is unnecessary overhead. Work directly on the
  current branch `product-decisions-ledger` (already created off `main`).
- **Skill edit target.** `.claude/skills` is a symlink to `.agents/skills`.
  Edit only the files under `.agents/skills/...`.
- **Authority file target.** Root `CLAUDE.md` is a symlink to `AGENTS.md`. Edit
  `AGENTS.md`.
- **Path style.** Reference the ledger by its real path
  `apps/docs/content/product-decisions.md` everywhere. Do not "fix" the
  pre-existing `docs/mission.md` shorthand in the skills — that is out of scope.
- Run all commands from the repo root `/Users/nckrtl/orbit`.

## File map

| File | Action | Responsibility |
|------|--------|----------------|
| `apps/docs/content/product-decisions.md` | Create | The ledger: header, usage rules, the bar, entry format, `## Decisions`, `## Archive` |
| `apps/docs/content/README.md` | Modify | Index entry pointing at the ledger |
| `AGENTS.md` | Modify | Product Authority block names the ledger as the intent anchor |
| `.agents/skills/auditing-docs-drift/SKILL.md` | Modify | Consume the ledger: authority-order note, read in step 1, per-finding lookup |
| `.agents/skills/handling-feature-requests/SKILL.md` | Modify | Append rule at decision time |
| `.agents/skills/implementing-features/SKILL.md` | Modify | Append rule when implementation lands a direction change + report line |
| `.agents/skills/updating-documentation/SKILL.md` | Modify | Backstop append + ledger in authority-read list |

---

## Task 1: Create the ledger file

**Files:**
- Create: `apps/docs/content/product-decisions.md`

- [ ] **Step 1: Write the file**

Create `apps/docs/content/product-decisions.md` with exactly this content:

```markdown
# Product Decisions

This is Orbit's **intent ledger**: a chronological, append-only record of
*direction-change* decisions. It sits above the authority-doc chain
(mission → architecture → concepts → tech-stack → domains) as the anchor for
**current intent**. It does not restate contracts — detailed behavior still
lives in the authority docs.

## How to use it

- **Find current intent on a topic:** `grep` this file for the topic noun
  (e.g. `scheduler`, `gateway`, `swarm`, `php`, `s3`). The matching entry with
  the **latest date is the current direction**; older entries on the same topic
  are superseded.
- **Resolving drift:** when two docs disagree, the latest dated decision here is
  current intent. The stale doc is the side that contradicts it, unless a
  *later* decision reaffirms the doc. This pre-fills the fix direction; it does
  not authorize silent edits.

## What gets a line (the bar)

Only a decision that **establishes a new product direction** or
**changes/reverses a previously-documented one**. Not a feature changelog: no
flags, bug fixes, refactors, test-lane tweaks, or gap-filling within an existing
direction.

## Entry format

`- YYYY-MM-DD — <decision, present tense, current direction; include the topic noun>. (solo todo #NNNN)`

- Present tense ("Gateway runs as…"); the date carries when it became intent.
- Put the topic noun in the line so `grep` finds it.
- The `(solo todo #NNNN)` link is optional — include it when a Solo todo drove
  the decision (it is the context trail and timeline anchor); omit it otherwise.
- Newest entry first.

## Decisions

<!-- newest first; add new entries directly under this comment -->

## Archive

<!-- Manually move fully-absorbed, settled decisions here once the authority
docs reflect them. Keeps the active list above high-signal while preserving the
dated intent trail. -->
```

- [ ] **Step 2: Verify the file exists and is well-formed**

Run:
```bash
test -f apps/docs/content/product-decisions.md && \
grep -nE '^## (Decisions|Archive)$' apps/docs/content/product-decisions.md
```
Expected: both `## Decisions` and `## Archive` headings print with line numbers.

- [ ] **Step 3: Commit**

```bash
git add apps/docs/content/product-decisions.md
git commit -m "Add product-decisions intent ledger (empty)"
```

---

## Task 2: Seed the ledger

**Files:**
- Modify: `apps/docs/content/product-decisions.md` (insert entries under `## Decisions`)

The seed entries are reconstructed from `docs/superpowers/plans/*` (dates come
from the filename prefix) plus two decisions the user named directly. Solo todo
links are unknown for these historical decisions and are therefore omitted
("when applicable").

- [ ] **Step 1: Confirm wording against each source plan**

For each plan-derived entry, read the plan's opening goal to confirm the
one-liner is accurate:
```bash
for f in 2026-05-21-docker-first-orbit-runtime 2026-05-24-gateway-cli-monorepo-local-executor \
         2026-05-27-cli-first-command-surface 2026-05-30-s3-role-seaweedfs \
         2026-06-01-orbit-gateway-swarm-update-runner \
         2026-06-02-production-swarm-substrate-and-process-runtime \
         2026-06-02-app-prod-frankenphp-runtime-isolation; do
  echo "### $f"; sed -n '1,12p' "docs/superpowers/plans/$f.md"; echo;
done
```
Expected: each plan's title/goal matches the corresponding draft line below.
Adjust a line's wording only if the plan goal contradicts it.

- [ ] **Step 2: Confirm dates for the two non-plan decisions**

The centralized-scheduler and host-PHP-toolchain decisions have no dedicated
plan file. Derive a defensible date from when the authority docs adopted them:
```bash
echo "=== scheduler (gateway-centralized) ==="
git log --diff-filter=AM --format='%ad' --date=short -S'centralized' -- \
  apps/docs/content/tech-stack.md apps/docs/content/domains/9_schedule/ | tail -1
echo "=== host php toolchain ==="
git log --diff-filter=AM --format='%ad' --date=short -iS'static php' -- \
  apps/docs/content/domains/14_php/ apps/docs/content/tech-stack.md | tail -1
```
Use the earliest matching date each command returns. If a command returns
nothing, present the entry to the user in Step 4 with the date left blank for
them to supply, rather than guessing.

- [ ] **Step 3: Insert the seed entries**

Insert these lines directly under the `<!-- newest first ... -->` comment in the
`## Decisions` section, newest first. Replace the `<derived>` dates from Step 2:

```markdown
- 2026-06-02 — Gateway and app production runtime ship as a FrankenPHP Docker image with per-app runtime isolation.
- 2026-06-02 — The production substrate is Docker Swarm; Orbit processes run as Swarm-managed service workloads.
- 2026-06-01 — The gateway orchestrates node updates through a Docker Swarm update runner.
- 2026-05-30 — The S3 role is backed by SeaweedFS.
- 2026-05-27 — The host `orbit` CLI is Orbit's primary command surface (CLI-first).
- 2026-05-24 — Orbit is a gateway + CLI monorepo with a host-side local executor.
- 2026-05-21 — Orbit roles run as Docker workloads (Docker-first runtime).
- <derived> — App nodes carry a host static PHP toolchain (php 8.3/8.4/8.5 + composer + laravel); reverses the earlier no-host-PHP direction.
- <derived> — Scheduling is centralized on the gateway, which runs due schedules against each schedule's target nodes; no per-node scheduler.
```

Note: the two `<derived>`-dated lines sort by their derived dates — place each in
correct newest-first position once dated.

- [ ] **Step 4: Present to the user for trim**

Show the user the full seeded `## Decisions` list. Ask: "Trim, reword, or add any
before I commit the seed?" Apply requested changes. (The spec calls for this trim
gate explicitly.)

- [ ] **Step 5: Verify entry format**

Run:
```bash
grep -nE '^- [0-9]{4}-[0-9]{2}-[0-9]{2} — ' apps/docs/content/product-decisions.md
```
Expected: every seeded decision line prints; no line is missing the
`- YYYY-MM-DD — ` prefix. Confirm topic-noun grep works:
```bash
grep -i 'scheduler\|swarm\|php' apps/docs/content/product-decisions.md
```
Expected: the scheduler, swarm, and php lines print.

- [ ] **Step 6: Commit**

```bash
git add apps/docs/content/product-decisions.md
git commit -m "Seed product-decisions ledger with recent direction changes"
```

---

## Task 3: Add the README index entry

**Files:**
- Modify: `apps/docs/content/README.md`

- [ ] **Step 1: Edit the index**

Replace:
```markdown
4. [Concepts](concepts.md)

## Domains
```
with:
```markdown
4. [Concepts](concepts.md)
5. [Product Decisions](product-decisions.md) — chronological intent ledger; newest entry states current direction

## Domains
```

- [ ] **Step 2: Verify**

Run:
```bash
grep -n 'product-decisions.md' apps/docs/content/README.md
```
Expected: the new line 5 prints.

- [ ] **Step 3: Commit**

```bash
git add apps/docs/content/README.md
git commit -m "Index product-decisions ledger in docs README"
```

---

## Task 4: Name the ledger in Product Authority (AGENTS.md)

**Files:**
- Modify: `AGENTS.md` (root `CLAUDE.md` symlinks to it)

- [ ] **Step 1: Edit the Product Authority block**

Replace:
```markdown
- `apps/docs/content/domains/**`

Session artifacts (plans, specs) stay at `docs/superpowers/`. They are not
product authority and are not linted as product docs.
```
with:
```markdown
- `apps/docs/content/domains/**`

`apps/docs/content/product-decisions.md` is the chronological intent ledger. It
does not restate contracts; it records each direction-change decision with a
date. When docs conflict, the latest dated decision on a topic states current
intent and indicates which side is stale. Treat it as the intent anchor above
the authority chain.

Session artifacts (plans, specs) stay at `docs/superpowers/`. They are not
product authority and are not linted as product docs.
```

- [ ] **Step 2: Verify (through the symlink too)**

Run:
```bash
grep -n 'product-decisions.md' AGENTS.md && grep -n 'intent ledger' CLAUDE.md
```
Expected: the new paragraph prints from `AGENTS.md`, and `CLAUDE.md` (the
symlink) shows the same text.

- [ ] **Step 3: Commit**

```bash
git add AGENTS.md
git commit -m "Name product-decisions ledger as intent anchor in Product Authority"
```

---

## Task 5: Wire the drift audit to consume the ledger

**Files:**
- Modify: `.agents/skills/auditing-docs-drift/SKILL.md`

- [ ] **Step 1: Add the intent-anchor note to Authority Order**

Replace:
```markdown
## Authority Order

These docs are authoritative in this exact order. A downstream doc that
contradicts an upstream doc is drift, regardless of how recently it was edited.

1. `apps/docs/content/mission.md`
```
with:
```markdown
## Authority Order

`apps/docs/content/product-decisions.md` is the dated **intent ledger** and sits
above this chain as the anchor for *current intent*. The authority docs below
state the contract; the ledger states which direction is current when they
disagree. A dated decision overrides an undated authority doc for determining
intent — the contradicting doc is the stale side unless a *later* decision
reaffirms it. The ledger pre-fills a fix direction; it does not authorize silent
edits — every finding is still walked with the user.

These docs are authoritative in this exact order. A downstream doc that
contradicts an upstream doc is drift, regardless of how recently it was edited.

1. `apps/docs/content/mission.md`
```

- [ ] **Step 2: Read the ledger first in Workflow step 1**

Replace:
```markdown
### 1. Read authority docs (in order)

Read `apps/docs/content/mission.md`, `apps/docs/content/architecture.md`,
`apps/docs/content/concepts.md`, `apps/docs/content/tech-stack.md`,
`apps/docs/content/domains/README.md` in full. Build a mental model of what
the docs *claim* before checking what they *say*.
```
with:
```markdown
### 1. Read authority docs (in order)

Read `apps/docs/content/product-decisions.md` first — the dated intent ledger
tells you which direction is current before you compare what the docs say. Then
read `apps/docs/content/mission.md`, `apps/docs/content/architecture.md`,
`apps/docs/content/concepts.md`, `apps/docs/content/tech-stack.md`,
`apps/docs/content/domains/README.md` in full. Build a mental model of what
the docs *claim* before checking what they *say*.
```

- [ ] **Step 3: Add the per-finding ledger lookup to the Recommended-fix template**

Replace:
```markdown
**Recommended fix:**
Concrete numbered edits. Quote the exact replacement prose for any rewrites.
Name files affected by absolute path. If a product decision is required,
present 2–3 options with pros/cons and a recommendation.
```
with:
```markdown
**Recommended fix:**
First `grep apps/docs/content/product-decisions.md` for the finding's topic
noun. If a dated decision exists, the latest entry is current intent: the fix is
to align the contradicting (stale) doc to that decision — state it directly
rather than as an open choice. Then give concrete numbered edits. Quote the
exact replacement prose for any rewrites. Name files affected by absolute path.
If no ledger decision covers the topic and a product decision is required,
present 2–3 options with pros/cons and a recommendation.
```

- [ ] **Step 4: Verify all three edits landed**

Run:
```bash
grep -cn 'product-decisions.md' .agents/skills/auditing-docs-drift/SKILL.md
```
Expected: count is `3` (Authority Order note, step 1 read, recommended-fix
lookup).

- [ ] **Step 5: Commit**

```bash
git add .agents/skills/auditing-docs-drift/SKILL.md
git commit -m "Wire auditing-docs-drift to consume the product-decisions ledger"
```

---

## Task 6: Add the decision-time append rule to handling-feature-requests

**Files:**
- Modify: `.agents/skills/handling-feature-requests/SKILL.md`

- [ ] **Step 1: Insert the ledger subsection after the "Decision needed" block**

Replace:
```markdown
Recommended direction: <specific choice and why>
```

## Handoff Shape
```
with:
```markdown
Recommended direction: <specific choice and why>
```

### Log direction changes to the intent ledger

When a decision **establishes or changes a product direction** (not a flag, fix,
or gap-fill), append a one-line entry to
`apps/docs/content/product-decisions.md` at decision time, newest first:

`- YYYY-MM-DD — <decision, present tense, with the topic noun>. (solo todo #NNNN)`

Link the Solo todo that drove the decision. This is the chronological intent
anchor the `auditing-docs-drift` skill consults to resolve drift; capture it now
even if the full authority-doc update lands later.

## Handoff Shape
```

(Note: the `Recommended direction: ...` line above is inside a fenced ```` ```markdown ```` block followed by its closing fence; match the closing fence + blank line + `## Handoff Shape` exactly so the new subsection lands between the code block and the Handoff Shape heading.)

- [ ] **Step 2: Verify**

Run:
```bash
grep -n 'Log direction changes to the intent ledger' .agents/skills/handling-feature-requests/SKILL.md
```
Expected: the new subsection heading prints.

- [ ] **Step 3: Commit**

```bash
git add .agents/skills/handling-feature-requests/SKILL.md
git commit -m "handling-feature-requests: log direction changes to the ledger"
```

---

## Task 7: Add the append rule + report line to implementing-features

**Files:**
- Modify: `.agents/skills/implementing-features/SKILL.md`

- [ ] **Step 1: Add the ledger rule to Implementation Rules**

Replace:
```markdown
- Treat current docs as product authority.
- Keep docs, tests, and code aligned.
- Retire tests only when current docs reject the behavior or replacement coverage exists.
```
with:
```markdown
- Treat current docs as product authority.
- Keep docs, tests, and code aligned.
- When the change lands a direction change or reversal, append a one-line entry
  to `apps/docs/content/product-decisions.md` (newest first,
  `- YYYY-MM-DD — <decision with topic noun>. (solo todo #NNNN)`), linking the
  Solo todo being executed. Skip routine work — flags, fixes, refactors.
- Retire tests only when current docs reject the behavior or replacement coverage exists.
```

- [ ] **Step 2: Add a ledger line to the Report Shape**

Replace:
```markdown
Changed files:
- <path>: <why>

Tests:
- Pest unit/feature: <test added or changed>
```
with:
```markdown
Changed files:
- <path>: <why>

Product-decisions ledger:
- <appended line, or none>

Tests:
- Pest unit/feature: <test added or changed>
```

- [ ] **Step 3: Verify both edits**

Run:
```bash
grep -cn 'product-decisions.md\|Product-decisions ledger' .agents/skills/implementing-features/SKILL.md
```
Expected: count is `2` (the rule + the report line).

- [ ] **Step 4: Commit**

```bash
git add .agents/skills/implementing-features/SKILL.md
git commit -m "implementing-features: append direction changes to the ledger"
```

---

## Task 8: Add the backstop to updating-documentation

**Files:**
- Modify: `.agents/skills/updating-documentation/SKILL.md`

- [ ] **Step 1: Add the ledger to the authority-read list**

Replace:
```markdown
2. Read current product authority:
   - `AGENTS.md`
   - `docs/mission.md`
```
with:
```markdown
2. Read current product authority:
   - `AGENTS.md`
   - `apps/docs/content/product-decisions.md` (dated intent ledger — current direction)
   - `docs/mission.md`
```

- [ ] **Step 2: Add the backstop step before the docs-lint gate**

Replace:
```markdown
5. Record open questions and unresolved decisions explicitly.
6. Run the documentation quality gate:

   ```bash
   composer docs-lint
   ```
```
with:
```markdown
5. Record open questions and unresolved decisions explicitly.
6. Intent-ledger backstop: if this pass lands a direction-changing edit to an
   authority doc, confirm a matching line exists in
   `apps/docs/content/product-decisions.md`. If not, append it (newest first,
   `- YYYY-MM-DD — <decision with topic noun>. (solo todo #NNNN)`). The ledger
   is the dated intent anchor the drift audit consults.
7. Run the documentation quality gate:

   ```bash
   composer docs-lint
   ```
```

- [ ] **Step 3: Verify**

Run:
```bash
grep -cn 'product-decisions.md' .agents/skills/updating-documentation/SKILL.md
```
Expected: count is `2` (authority-read list + backstop step).

- [ ] **Step 4: Commit**

```bash
git add .agents/skills/updating-documentation/SKILL.md
git commit -m "updating-documentation: ledger in authority list + backstop append"
```

---

## Task 9: Final verification

**Files:** none (verification only)

- [ ] **Step 1: Confirm docs-lint does not regress**

Run:
```bash
composer docs-lint
```
Expected: passes. (The ledger and README live outside the linted
`content/domains` and `content/testing` paths, so this should be unaffected; run
it to confirm no incidental regression.)

- [ ] **Step 2: Confirm the ledger is discoverable from every entry point**

Run:
```bash
echo "README:"      ; grep -c 'product-decisions.md' apps/docs/content/README.md
echo "AGENTS:"      ; grep -c 'product-decisions.md' AGENTS.md
echo "drift skill:" ; grep -c 'product-decisions.md' .agents/skills/auditing-docs-drift/SKILL.md
echo "feature-req:" ; grep -c 'product-decisions.md' .agents/skills/handling-feature-requests/SKILL.md
echo "implement:"   ; grep -c 'product-decisions.md' .agents/skills/implementing-features/SKILL.md
echo "update-docs:" ; grep -c 'product-decisions.md' .agents/skills/updating-documentation/SKILL.md
```
Expected: README ≥1, AGENTS ≥1, drift skill 3, feature-req ≥1, implement ≥1
(plus the report line), update-docs 2.

- [ ] **Step 3: Confirm git history is clean and scoped**

Run:
```bash
git log --oneline main..HEAD
git status
```
Expected: one commit per task (plus the earlier spec commit), working tree
clean.

- [ ] **Step 4: Report**

Summarize to the user: files created/changed, the seeded decision count, and
confirmation that `composer docs-lint` passed. Offer to open a PR or merge to
`main` per the finishing-a-development-branch flow.

---

## Self-review (completed by plan author)

**Spec coverage:** §1 doc → Task 1; §2 entry format → Task 1 (header) + enforced
by seed verify in Task 2; §3 the bar → Task 1 header + Tasks 6/7 append rules;
§4 lifecycle (decision-time + backstop) → Tasks 6 (feature-req), 7 (implement),
8 (update-docs backstop); §5 drift integration → Task 5; §6 pruning (manual
Archive) → Task 1 `## Archive` section, no automation by design; §7 seeding →
Task 2. README + AGENTS discoverability (§1) → Tasks 3 + 4. All spec sections map
to a task.

**Placeholder scan:** The only intentional deferrals are the two `<derived>`
seed dates in Task 2, which come with exact `git log` commands and a user-ask
fallback — not open placeholders. No "TBD"/"add error handling"/"similar to" 
patterns.

**Consistency:** Entry format string
`- YYYY-MM-DD — <... topic noun>. (solo todo #NNNN)` is identical across the
ledger header (Task 1), feature-req append (Task 6), implement append (Task 7),
and update-docs backstop (Task 8). The path `apps/docs/content/product-decisions.md`
is used verbatim everywhere. Edit targets account for both symlinks
(`.claude/skills`→`.agents/skills`, `CLAUDE.md`→`AGENTS.md`).
