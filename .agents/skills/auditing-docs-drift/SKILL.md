---
name: auditing-docs-drift
description: Use when scanning Orbit documentation for contradictions, drift between authority docs and downstream docs, broken anchors, or stale legacy terminology — and resolving findings through an independent second-opinion review and a per-finding walkthrough.
---

# Auditing Docs Drift

## Overview

Find documentation that contradicts itself across the authority chain, get an
independent second opinion, then resolve each finding with the user before
touching any docs or code. This skill is the structured form of the audit we
ran on 2026-05-20. Run it when the user asks to scan docs for drift, check
consistency, find contradictions, or align documentation.

## Authority Order

These docs are authoritative in this exact order. A downstream doc that
contradicts an upstream doc is drift, regardless of how recently it was edited.

1. `docs/mission.md`
2. `docs/architecture.md`
3. `docs/concepts.md` (concept routing index)
4. `docs/tech-stack.md`
5. `docs/domains/*/README.md` and `docs/domains/*/*-concepts.md`
6. `docs/domains/*/**/technical/**.md`

`docs/superpowers/**` and `docs/porting/**` are out of scope — they are
historical or process docs, not product authority.

## Severity Buckets

- **A** — Direct contradiction with mission/architecture/concepts/tech-stack.
  Blocks docs coherence. Examples: a domain README enumerates fewer state
  families than `concepts.md`; a technical contract describes a per-node
  scheduler when tech-stack says gateway-only.
- **B** — Stale, ambiguous, or transitional terminology in normative docs.
  Same meaning end-to-end but vocabulary drifts. Examples: legacy term still
  appears outside the legacy-block; preset name collides with a caller noun.
- **C** — Broken cross-references, missing anchors, catalog gaps. Mechanical
  fixes that don't change product model. Examples: link to `architecture.md#x`
  where `#x` doesn't exist; tool table omits a slug the catalog defines.

If you're unsure between A and B, ask: *does the docs reader make a different
decision because of this?* If yes, A. If they just feel uncertain, B.

## Workflow

### 1. Read authority docs (in order)

Read `mission.md`, `architecture.md`, `concepts.md`, `tech-stack.md`,
`docs/domains/README.md` in full. Build a mental model of what the docs
*claim* before checking what they *say*.

Key things to extract:

- The set of node roles and what makes each category distinct.
- The state families list (count + names).
- The authorization model (grants? roles? both?).
- The doctor flag/mode taxonomy.
- The transport edges (CLI→gateway, gateway→node) and their primitives.
- Anchor headings exist in `architecture.md` and `tech-stack.md` — note them for later cross-link checks.

### 2. Read every domain README and concepts file

For each `docs/domains/N_*/`:

- `README.md` — domain rules, state ownership claims, JSON entity, caller-role wording.
- `<family>-concepts.md` — vocabulary and invariants.
- `<family>-doctor.md` when present — probe layers, issue codes, fix/adopt maps.

Watch for:

- "Caller Role Rule" sections — these usually leak the old role-based authorization model.
- JSON entity field tables that expose fields not defined in the concepts doc.
- Boundary sections that conflict with the authoritative state-families table.
- References to legacy terms that the concepts doc marks deprecated.

### 3. Sweep for cross-link and naming drift

Run these greps from the repo root:

```bash
# Broken architecture/tech-stack anchors
grep -rn "architecture\.md#\|tech-stack\.md#" docs/ --include="*.md" \
  | grep -v "superpowers\|porting"

# Then for each anchor target, confirm the heading exists:
grep -n "^#" docs/architecture.md docs/tech-stack.md

# Legacy terminology still in current docs
grep -rn "hosted node\|hosted host\|hosted role\|joined client\|operator node\|control node" \
  docs/ --include="*.md" | grep -v "superpowers\|porting\|Legacy\|legacy"

# Caller-role leakage
grep -rn "caller_role_not_allowed\|Caller Role Rule\|control.*callers\|app callers\|unknown callers" \
  docs/domains/ --include="*.md"

# Per-target scheduler model leakage
grep -rn "target node.*scheduler\|scheduler on the target\|node.*scheduler" \
  docs/domains/9_schedule/ --include="*.md"

# Node-level vs role-assignment TLD
grep -rn "node\.tld\|<node-tld>\|node\.tld_in_use" \
  docs/ --include="*.md" | grep -v "superpowers\|porting"
```

Add domain-specific sweeps as you spot patterns.

### 4. Cross-check doctor families

The architecture's state-families list is the source of truth. Confirm:

- Every family is enumerated in `domains/11_operation/README.md` doctor-routing list.
- Every family is enumerated in `domains/17_activity/README.md` doctor-handoff bullets.
- Each `<family>-concepts.md` matches the architecture's family description.
- Each `<family>-doctor.md` technical contract returns the correct singular family key (`node`, not `nodes`).

### 5. Write findings to a Solo scratchpad (`docs-audit-findings-claude`)

For each finding, use this exact shape:

```markdown
### <id> — <one-line title>

**Severity:** A | B | C
**Files:** path:line-range, path:line-range, ...

**The drift (what's wrong with the current docs):**
What the authority docs say (with line references), what the downstream doc
says, and where they diverge.

**Why it matters:**
What a reader does wrong, what a maintainer has to relitigate, or what risk
the silent inconsistency creates. One paragraph.

**Recommended fix:**
Concrete numbered edits. Quote the exact replacement prose for any rewrites.
Name files affected by absolute path. If a product decision is required,
present 2–3 options with pros/cons and a recommendation.
```

Use IDs `A1, A2, ...`, `B1, B2, ...`, `C1, C2, ...` per severity.

Tag the scratchpad with `["docs-audit", "review-input"]`.

### 6. Spawn an independent reviewer (Codex)

Spawn a Codex agent in Solo with this exact prompt template:

```
You are reviewing a docs consistency audit I performed across /Users/nckrtl/orbit/docs/
(excluding superpowers and porting subdirs).

My findings are in Solo scratchpad `docs-audit-findings-claude` (scratchpad_id N).
Read it first using scratchpad_read with scratchpad_id=N.

Authority order used: mission > architecture > concepts > tech-stack > domain READMEs > technical contracts.

Your job:
1. Read my findings.
2. Independently verify each finding by reading the cited docs at /Users/nckrtl/orbit/docs/.
3. Tell me: which of my findings are correct, which are wrong/overstated, and what I MISSED.
4. Write your review to a new Solo scratchpad named `docs-audit-review-codex` so I can read it back.
   Use scratchpad_write to create it.

Be direct. Flag overstatements. Flag missed contradictions. Don't repeat my finding text — just state agreement/disagreement plus any additions.

Output your review in this shape:
- Per-finding verdict (Agree / Partially agree / Disagree, with one-line reason)
- New findings I missed (numbered, with file paths and line ranges)
- Overall coherence score: how close are the docs to forming one coherent piece (1-10), and the biggest remaining blocker.
```

Set an idle-trigger timer that fires when Codex goes idle. Codex typically
takes 3–6 minutes for a full repo audit.

### 7. Synthesize Codex review with original findings

Read `docs-audit-review-codex` from Solo. Build a final synthesis scratchpad
named `docs-audit-final` with tag `["docs-audit", "final"]`. The synthesis must:

- Apply every Codex downgrade or correction (e.g. A→B, mislabeled file path).
- Keep every Codex addition as a numbered new finding under the right severity.
- Group findings by severity (A, B, C).
- Include a "Codex downgrades / additions applied" section so the diff between
  the two reviews is visible.
- End with a recommended execution order naming the 3–4 highest-leverage fixes.

### 8. Walk through each finding with the user

Present findings one at a time in this exact shape (matches step 5 plus an
explicit "Why it matters" framing):

```markdown
## <id> — <title>

**The drift (what's wrong with the current docs):**
<authority quote with line refs> ... <downstream quote with line refs> ... <where they diverge>

**Why it matters:**
<one paragraph; reader impact, maintainer cost, or future risk>

**Files affected:**
- <path:line-range>

**Recommended fix:**
1. <concrete edit>
2. <concrete edit>
...
```

After each finding ask: *"Approve <id>, want changes, or move on to <next>?"*

When the user pushes back, revise the fix in the same conversation — do not
defer. The fix shape can change radically (e.g. C3 inverted from "add row" to
"remove the entire concept"). Re-present after revision and re-ask.

Skill discipline:

- Start each finding with the intro (drift + why-it-matters). Skipping this
  forces the user to reconstruct context — they correctly objected during the
  2026-05-20 audit and we adopted this format thereafter.
- When the user proposes a different fix direction, restate the drift in their
  preferred frame, then present the revised fix. Don't argue the original.
- Track approvals in a task list so the walkthrough state is recoverable.

### 9. Apply approved fixes

After every finding is resolved:

1. Apply each docs change in the order the user prefers (default: A-tier first
   because they tend to unblock other edits).
2. For each finding flagged with a "code follow-up" note (e.g. retire a tool
   row, change a column name, rewire a renderer), follow up with the
   `implementing-features` skill in a worktree.
3. Run `composer docs-lint` after the docs pass.
4. Run `composer quality-check` after any code follow-up.

## Common Contradiction Patterns

These keep showing up. Look for them first.

- **Caller-role leakage.** Architecture is grants-only; downstream docs still
  enumerate `control` / `gateway` / `app` / `unknown` as authorization gates.
  Check shared README + per-domain "Caller Role Rule" sections + technical
  contract authorization bullets + activity JSON `actor.role` enum.
- **TLD ownership flip-flop.** Whether TLD lives on the node row or on the
  role assignment. Check `dns-bootstrap-contract.md`, `tool-doctor.md`, and
  `node:new` / `node:update` JSON renderers.
- **Per-target scheduler model.** Schedule docs that talk about "the target
  node's scheduler" when tech-stack says the scheduler is gateway-only.
- **State-family enumeration mismatch.** Operation and activity READMEs that
  list 8 families when architecture lists 9 (or whatever the current count is).
- **Broken architecture / tech-stack anchors.** Domain READMEs cite anchors
  that don't exist in the target file.
- **Legacy terminology in current prose.** `hosted role`, `joined client`,
  `operator node`, `control node` — node-concepts marks these legacy but they
  re-appear in current docs.
- **Preset name collisions.** `operator`, `developer`, `admin`, etc. are
  preset names. Don't use the same word as a caller-type noun.
- **Tool catalog ↔ family README ↔ role baseline mismatch.** The family README
  table is the canonical tool list. Confirm every required-baseline tool
  referenced by a role baseline appears in the table.
- **JSON entity exposes undefined fields.** Schedule's `registry_synced_at` is
  the canonical example — exposed in JSON but never defined in concepts.
- **Self-grant model misframed as exception.** `workspace:setup` is sometimes
  called a "write exception" but it's actually the self-grant model working
  normally. The self-grant model deserves explicit treatment in architecture.

## Authority Updates That Unblock Other Fixes

Some authoritative sections, when added, retire whole classes of drift downstream.
If any of the following are missing in the authority docs, add them first:

- `architecture.md#self-grants-and-self-serving` — closes "exception" framing.
- `architecture.md#dns-responsibilities` — closes the 4-way DNS ownership ambiguity.
- `architecture.md` Doctor flag+mode table — sanctions `verify`/`interactive` named modes.
- `architecture.md` driver concept (each role has a driver; OS support is driver capability) — closes negative platform-support framing.

## Report Shape

```markdown
## Docs Drift Audit Report

Scratchpads:
- Original findings: docs-audit-findings-claude (id N1)
- Reviewer findings: docs-audit-review-codex (id N2)
- Synthesized final: docs-audit-final (id N3)

Findings resolved:
- A-tier (count): <list>
- B-tier (count): <list>
- C-tier (count): <list>

Coherence score (Codex): N/10
Biggest blocker: <one line>

Docs changes applied:
- <path>: <why>

Code follow-ups required:
- <path or scope>: <why>

Verification:
- `composer docs-lint`: <result>
- `composer quality-check`: <result if code changed>
```
