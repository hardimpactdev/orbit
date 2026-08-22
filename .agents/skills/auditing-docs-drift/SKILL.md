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

**User-facing output contract:** Always present the complete final finding list.
Every item must have a plain-language **Drift** explanation and a plain-language
**Fix**. Counts, severity tallies, file links, and fix-only bullets are
supporting context; they are not the finding list. This applies to the first
audit result, the post-review synthesis, the approval handoff, and the final
completion report.

## Authority Order

`PRODUCT_DECISIONS.md` is the dated **intent ledger** and sits above this chain
as the anchor for *current intent*. The authority docs below state the contract;
the ledger states which direction is current when they disagree. A dated
decision overrides an undated authority doc for determining intent — the
contradicting doc is the stale side unless a *later* decision reaffirms it. The
ledger pre-fills a fix direction; it does not authorize silent edits — every
finding is still walked with the user.

These docs are authoritative in this exact order. A downstream doc that
contradicts an upstream doc is drift, regardless of how recently it was edited.

1. `apps/docs/content/mission.md`
2. `apps/docs/content/architecture.md`
3. `apps/docs/content/concepts.md` (concept routing index)
4. `apps/docs/content/tech-stack.md`
5. `apps/docs/content/domains/*/README.md` and `apps/docs/content/domains/*/*-concepts.md`
6. `apps/docs/content/domains/*/**/technical/**.md`

`docs/superpowers/**` and `apps/docs/content/porting/**` are out of scope —
they are historical or process docs, not product authority.

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

Read `PRODUCT_DECISIONS.md` first — the dated intent ledger tells you which
direction is current before you compare what the docs say. Then read
`apps/docs/content/mission.md`, `apps/docs/content/architecture.md`,
`apps/docs/content/concepts.md`, `apps/docs/content/tech-stack.md`,
`apps/docs/content/domains/README.md` in full. Build a mental model of what
the docs *claim* before checking what they *say*.

Key things to extract:

- The set of node roles and what makes each category distinct.
- The state families list (count + names).
- The authorization model (grants? roles? both?).
- The doctor flag/mode taxonomy.
- The transport edges (CLI→gateway, gateway→node) and their primitives.
- Anchor headings exist in `architecture.md` and `tech-stack.md` — note them for later cross-link checks.

### 2. Read every domain README and concepts file

For each `apps/docs/content/domains/N_*/`:

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
grep -rn "architecture\.md#\|tech-stack\.md#" apps/docs/content/ --include="*.md" \
  | grep -v "porting"

# Then for each anchor target, confirm the heading exists:
grep -n "^#" apps/docs/content/architecture.md apps/docs/content/tech-stack.md

# Legacy terminology still in current docs
grep -rn "hosted node\|hosted host\|hosted role\|joined client\|control node" \
  apps/docs/content/ --include="*.md" | grep -v "porting\|Legacy\|legacy"

# Caller-role leakage
grep -rn "caller_role_not_allowed\|Caller Role Rule\|control.*callers\|app callers\|unknown callers" \
  apps/docs/content/domains/ --include="*.md"
# Compare every match with Architecture's named authorization classes before
# reporting it as drift.

# Per-target scheduler model leakage
grep -rn "target node.*scheduler\|scheduler on the target\|node.*scheduler" \
  apps/docs/content/domains/9_schedule/ --include="*.md"

# Node-level vs role-assignment TLD
grep -rn "node\.tld\|<node-tld>\|node\.tld_in_use" \
  apps/docs/content/ --include="*.md" | grep -v "porting"
```

Add domain-specific sweeps as you spot patterns.

### 4. Cross-check doctor families

The architecture's state-families list is the source of truth. Confirm:

- Every family is enumerated in `apps/docs/content/domains/11_operation/README.md` doctor-routing list.
- Every family is enumerated in `apps/docs/content/domains/16_activity/README.md` doctor-handoff bullets.
- Each `<family>-concepts.md` matches the architecture's family description.
- Each `<family>-doctor.md` technical contract returns the correct singular family key (`node`, not `nodes`).

### 5. Write findings to a file under `~/shared-knowledge/projects/orbit/docs-audit/` (`docs-audit-findings-claude`)

For each finding, use this exact shape:

```markdown
### <id> — <one-line title>

**Severity:** A | B | C
**Files:** path:line-range, path:line-range, ...

**Drift:**
In 1–3 short sentences, say what the authority docs say, what the other docs
say, and why both statements cannot be true. Use simple writing before giving
line references or internal terminology.

**Why it matters:**
What a reader does wrong, what a maintainer has to relitigate, or what risk
the silent inconsistency creates. One paragraph.

**Fix:**
First `grep PRODUCT_DECISIONS.md` for the finding's topic noun. If a dated
decision exists, the latest entry is current intent: the fix is to align the
contradicting (stale) doc to that decision — state it directly rather than as an
open choice. Then give concrete numbered edits. Quote the exact replacement
prose for any rewrites. Name files affected by absolute path. If no ledger
decision covers the topic and a product decision is required, present 2–3
options with pros/cons and a recommendation.
```

Use IDs `A1, A2, ...`, `B1, B2, ...`, `C1, C2, ...` per severity.

Tag the file with `["docs-audit", "review-input"]`.

### 6. Spawn an independent reviewer (Codex)

Spawn a second worker window with `bin/orbit-worker-spawn` using this exact prompt template:

```
You are reviewing a docs consistency audit I performed across /Users/nckrtl/orbit/apps/docs/content/
(excluding the porting subdir, and /Users/nckrtl/orbit/docs/superpowers/).

My findings are in `~/shared-knowledge/projects/orbit/docs-audit/docs-audit-findings-claude`.
Read that file first.

Authority order used: mission > architecture > concepts > tech-stack > domain READMEs > technical contracts.

Your job:
1. Read my findings.
2. Independently verify each finding by reading the cited docs at /Users/nckrtl/orbit/apps/docs/content/.
3. Tell me: which of my findings are correct, which are wrong/overstated, and what I MISSED.
4. Write your review to a new file under `~/shared-knowledge/projects/orbit/docs-audit/` named `docs-audit-review-codex` so I can read it back.

Be direct. Flag overstatements. Flag missed contradictions. Don't repeat my finding text — just state agreement/disagreement plus any additions.

Output your review in this shape:
- Per-finding verdict (Agree / Partially agree / Disagree, with one-line reason)
- New findings I missed (numbered, with file paths and line ranges)
- Overall coherence score: how close are the docs to forming one coherent piece (1-10), and the biggest remaining blocker.
```

Wait for the reviewer worker handoff. Codex typically takes 3–6 minutes for a
full repo audit.

### 7. Synthesize Codex review with original findings

Read `docs-audit-review-codex` from `~/shared-knowledge/projects/orbit/docs-audit/`. Build a final synthesis file
named `docs-audit-final` with tag `["docs-audit", "final"]`. The synthesis must:

- Apply every Codex downgrade or correction (e.g. A→B, mislabeled file path).
- Keep every Codex addition as a numbered new finding under the right severity.
- Keep a plain-language `Drift` and `Fix` for every final finding. These fields
  are the source for the complete user-facing list.
- Group findings by severity (A, B, C).
- Include a "Codex downgrades / additions applied" section so the diff between
  the two reviews is visible.
- End with a recommended execution order naming the 3–4 highest-leverage fixes.

### 8. Present the full list, then walk through each finding

Before asking for any approval, present every final finding together using this
compact shape:

```markdown
## Full drift list

### <id> — <short title>

**Drift:** <1–3 short sentences explaining the conflicting statements and why they disagree.>

**Fix:** <1–3 short sentences stating the recommended change.>
```

Repeat the item for every A, B, and C finding in order. A request to “keep it
short” means making each `Drift` and `Fix` concise; it does not remove items or
either field. If the complete list cannot fit in one response, split it into
clearly numbered parts and continue until every item has been shown.

Use simple writing:

- Put the current problem in `Drift` and the action to take in `Fix`.
- Name the two conflicting claims directly instead of relying on internal
  shorthand.
- Use short sentences and explain uncommon terms once.
- Keep evidence, file paths, severity, and `Why it matters` after the plain
  explanation so the reader understands the issue first.

After the complete list is visible, walk through findings one at a time for
approval using this detailed shape:

```markdown
## <id> — <title>

**Drift:**
<authority quote with line refs> ... <downstream quote with line refs> ... <where they diverge>

**Why it matters:**
<one paragraph; reader impact, maintainer cost, or future risk>

**Files affected:**
- <path:line-range>

**Fix:**
1. <concrete edit>
2. <concrete edit>
...
```

After each finding ask: *"Approve <id>, want changes, or move on to <next>?"*

When the user pushes back, revise the fix in the same conversation — do not
defer. The fix shape can change radically (e.g. C3 inverted from "add row" to
"remove the entire concept"). Re-present after revision and re-ask.

Skill discipline:

- Start the handoff with the complete plain-language list, then start each
  detailed finding with `Drift` and `Why it matters`.
- When the user proposes a different fix direction, restate the drift in their
  preferred frame, then present the revised fix. Don't argue the original.
- Track approvals in a task list so the walkthrough state is recoverable.

### 9. Route approved fixes through an implementation worktree

The audit itself is read-only. After every finding is resolved, route the
approved fixes through the `implementing-features` skill in a
`bin/orbit-prepare-worktree` implementation worktree — do not edit docs
in place from the audit session:

1. Hand the approved findings (final synthesis file plus per-finding
   approvals) to the implementation worktree. Apply docs changes in the order
   the user prefers (default: A-tier first because they tend to unblock other
   edits).
2. Findings flagged with a "code follow-up" note (e.g. retire a tool row,
   change a column name, rewire a renderer) go through the same
   `implementing-features` flow, as their own slice when independent.
3. Run `composer docs-lint` in the worktree after the docs pass.
4. Run `composer quality-check` in the worktree after any code follow-up.

## Common Contradiction Patterns

These keep showing up. Look for them first.

- **Caller-role leakage.** Stored grants are the default authorization gate.
  Reject built-in `control` / `gateway` / `app` / `unknown` caller-role gates
  unless the surface uses one of Architecture's named gateway-implicit-authority,
  pre-grants-bootstrap, local-only, or identity-gated-self-management classes.
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
- **Legacy terminology in current prose.** `hosted role`, `joined client`, and
  `control node` are legacy. `operator node` is current identity wording; use
  `roleless operator node` when the absence of workload roles matters.
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

Some authoritative sections, when added, retire whole classes of drift
downstream. When findings cluster around one missing authoritative concept,
propose adding the authority section first, then align the downstream docs to
it. Verify against the current `apps/docs/content/architecture.md` before
queueing a candidate — earlier queue entries here (the self-grants and
self-serving section, the DNS responsibilities section, the doctor flag+mode
table, and the role driver concept) have all since landed.

## Report Shape

```markdown
## Docs Drift Audit Report

Audit files (`~/shared-knowledge/projects/orbit/docs-audit/`):
- Original findings: docs-audit-findings-claude
- Reviewer findings: docs-audit-review-codex
- Synthesized final: docs-audit-final

Full findings (<total count>):

### <id> — <short title>
**Drift:** <plain explanation of what conflicts>
**Fix:** <plain explanation of what should change>

<repeat for every final finding in A, B, C order>

Severity tally:
- A-tier: <count>
- B-tier: <count>
- C-tier: <count>

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
