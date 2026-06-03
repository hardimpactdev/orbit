# Product Decisions Intent Ledger — Design

**Date:** 2026-06-03
**Status:** Approved (design); pending implementation plan
**Topic:** A chronological "Product Decisions" document for Orbit docs that anchors current intent and accelerates drift resolution.

## Problem

Orbit's product authority is a chain of docs — `mission.md` → `architecture.md`
→ `concepts.md` → `tech-stack.md` → `domains/*/README.md` and concepts →
technical contracts. These docs state *what is true* but carry **no timestamps
and no record of transitions**. When two docs disagree, there is no way to tell
which one reflects the *newer* intent.

This is exactly why the `auditing-docs-drift` skill is heavyweight: it surfaces
contradictions but cannot decide direction, so it asks the user to confirm
nearly every finding. Meanwhile, real direction changes (Docker Swarm
substrate, gateway-as-a-Docker-image, gateway-centralized scheduler, the host
PHP toolchain reversal) are decided during feature work and Solo todos, but are
only recorded transiently — in handoffs, scratchpads, plan docs, and memory —
never in one durable, chronological place the audit can consult.

## Goal

A single, append-only, chronological **intent ledger** that:

1. Records each *direction-change* decision as a one-line, dated entry.
2. Serves as the **tie-breaker for current intent** when drift is detected:
   the latest dated decision on a topic is the current direction.
3. Is cheap to append at decision time, so intent is captured even when the
   full authority-doc update lags (the window where drift is born).
4. Gives the drift audit a pre-filled fix direction, reducing the per-finding
   "ask the user" burden.

## Non-Goals

- Not a feature changelog. Only direction changes and reversals are logged.
- Not a restatement of the contract. Detailed behavior still lives in the
  authority docs; the ledger only points at intent.
- Not auto-pruned. Archiving is a manual human action for now.
- Not silently edited by the audit. The ledger *pre-fills* a fix direction; the
  user still walks each finding.

## Design

### 1. The document

A new top-level doc: **`apps/docs/content/product-decisions.md`**.

- **Append-only, reverse-chronological** (newest line at the top).
- Sits *above* the authority chain as the **intent anchor**. It does not
  restate contracts.
- Top-level, like `mission.md`, so `composer docs-lint` (which lints only
  `content/domains` and `content/testing`) imposes no structural rules on it —
  it stays free-form.
- Discoverable from:
  - `apps/docs/content/README.md` (index entry).
  - The **Product Authority** section of `CLAUDE.md`.
  - The authority inputs of the `auditing-docs-drift` skill (see §5).

### 2. Entry format

One line per decision, newest first:

```markdown
- 2026-06-02 — Gateway ships and runs as a Docker image, orchestrated by Docker Swarm. (solo todo #1234)
- 2026-05-28 — Scheduling is centralized on the gateway, which runs due schedules against each schedule's target nodes; no per-node scheduler. (solo todo #1201)
- 2026-05-20 — App nodes carry a host static PHP toolchain, reversing the earlier no-host-PHP direction. (solo todo #1187)
```

Format rules (stated in the file header):

- **Present tense, current direction.** Write "Gateway runs as…", not "we
  switched X to Y." The date carries the "when it became intent."
- **Topic noun in the line.** Each line must contain the topic noun
  (`gateway`, `scheduler`, `firewall`, `php`, `s3`, …). This is the lightweight
  substitute for tags: supersession is found by `grep <topic>` then
  "latest date wins."
- **Solo todo link is optional** ("when applicable"), written as
  `(solo todo #NNNN)`. It doubles as the context trail (why the decision was
  made) and a timeline anchor (surrounding todos place the decision in
  execution order). Omit it when no todo applies (e.g. an ad-hoc chat
  decision).
- **Date format** `YYYY-MM-DD`.

### 3. The bar (what clears it)

A line is warranted **only** when a decision either:

- **(a)** establishes a *new* product direction not previously documented, or
- **(b)** *changes or reverses* a previously-documented direction.

Qualifies: gateway-as-Docker-image, Docker Swarm substrate,
gateway-centralized scheduler, host PHP toolchain reversal.

Does **not** qualify (keep these out): a new command flag, a bug fix, a
refactor, a test-lane tweak, or filling a documented gap without changing
direction.

If unsure: *does this change or set a direction a future maintainer would
otherwise re-derive or get wrong?* If yes, log it. If it just adds detail
within an existing direction, don't.

### 4. Lifecycle — who appends, and when

**Rule: decision-time append, with a docs-update backstop.** Three skills gain
a small explicit step:

- **`handling-feature-requests`** — when a "Decision needed" resolves into a
  direction change, append the ledger line as part of the handoff aftermath
  (the todo and context are already in hand).
- **`implementing-features`** — when an implementation lands a direction
  change, ensure the ledger line exists, linking the Solo todo being executed.
  *(The Solo orchestration loop roles inherit this via `implementing-features`;
  the `loop-builder` role prompts are not edited in this scope — see Open
  Questions.)*
- **`updating-documentation`** — **backstop**: when landing a
  direction-changing edit to an authority doc, confirm a ledger line exists;
  if not, append it.

The append is a cheap one-liner, deliberately decoupled from the (possibly
larger, possibly lagging) full doc update — so intent is captured even in the
window where docs drift.

### 5. Drift-audit integration (the payoff)

Update the `auditing-docs-drift` skill:

- **Authority order.** Add `product-decisions.md` as the dated **intent anchor
  above the chain**. Note explicitly that it *pre-fills fix direction* and does
  not silently edit; the per-finding user walkthrough is unchanged.
- **Step 1 (read authority docs).** Read `product-decisions.md` alongside the
  authority docs.
- **Per-finding lookup.** For each contradiction, `grep` the ledger for the
  finding's topic noun:
  - If a dated decision exists, the **latest entry = current intent**. The
    recommended fix is pre-filled as "point the stale doc toward the ledger,"
    rather than left as an open question.
  - If no decision exists, fall back to current behavior (authority-order
    tiebreak + user walkthrough).
- **Reading scope.** The audit reads the whole file; the active (top) section
  is primary current intent. The `## Archive` section is still valid history
  for context.

### 6. Pruning

Manual for now. The file carries an **`## Archive`** section at the bottom. A
human moves fully-absorbed and settled lines there once the authority docs
reflect them. No skill automates this. (A future enhancement could mark or move
absorbed lines automatically, but it is explicitly out of scope here.)

### 7. Seeding

Bootstrap the ledger immediately so it is useful from day one. Reconstruct the
recent direction-changes already evidenced in `docs/superpowers/plans/*`, the
memory store, and git history. Dates come from plan filenames/git; Solo todo
links are added where recoverable and omitted where not.

Candidate seed decisions (to be finalized and trimmed during implementation):

- **Docker-first runtime** — Orbit roles run as Docker workloads
  (`2026-05-21-docker-first-orbit-runtime.md`).
- **CLI-first command surface** — the host `orbit` CLI is the primary surface
  (`2026-05-27-cli-first-command-surface.md`,
  `2026-05-24-gateway-cli-monorepo-local-executor.md`).
- **S3 role on SeaweedFS** (`2026-05-30-s3-role-seaweedfs.md`).
- **Gateway + Swarm update runner** — gateway orchestrates via Docker Swarm
  (`2026-06-01-orbit-gateway-swarm-update-runner.md`,
  `2026-06-02-production-swarm-substrate-and-process-runtime.md`).
- **Gateway/app as FrankenPHP Docker image, runtime-isolated**
  (`2026-06-02-app-prod-frankenphp-runtime-isolation.md`).
- **Host PHP toolchain reversal** — app nodes carry host static PHP +
  composer + laravel; reverses the no-host-PHP direction (memory:
  `project_host_php_toolchain_reversal.md`).
- **Gateway-centralized scheduler** — gateway runs due schedules against each
  schedule's target nodes; no per-node scheduler.

The final seeded set will be presented for user trim before commit.

## Components & boundaries

| Unit | Purpose | Depends on |
|------|---------|-----------|
| `product-decisions.md` | The ledger file + header rules + Archive section | nothing (free-form top-level doc) |
| `README.md` index entry | Discoverability | the ledger file |
| `CLAUDE.md` Product Authority note | Establishes the ledger's intent-anchor status | the ledger file |
| `auditing-docs-drift` skill edits | Consume the ledger to pre-fill fix direction | the ledger file |
| `handling-feature-requests` / `implementing-features` / `updating-documentation` skill edits | Append entries at decision time + backstop | the ledger file + entry-format rules |
| Seed pass | Make the ledger useful on day one | plan docs, memory, git |

Each unit is independently understandable: the file stands alone; each skill
edit references the file's documented rules; the audit edit reads the file.
Changing the entry format touches the file header and the skills that cite it,
nothing else.

## Testing / verification

- `composer docs-lint` must still pass (the new top-level file is out of the
  linted paths, but confirm no `domains`/`testing` rule regressions from the
  README index edit).
- Manual verification that `grep <topic> apps/docs/content/product-decisions.md`
  returns the seeded lines and orders correctly by date.
- A dry-run of the updated `auditing-docs-drift` per-finding lookup against one
  seeded topic (e.g. `scheduler`) to confirm it pre-fills the fix direction.
- No code paths change, so no Pest/PHPUnit additions are required; this is a
  docs + skills change.

## Open questions (resolved as defaults; confirm on review)

1. **Wiring scope.** Default: route capture through the three skills; Solo loop
   roles inherit via `implementing-features`; do **not** edit `loop-builder`
   role prompts in this scope. Revisit if loop roles need to append directly.
2. **Seeding depth.** Default: a moderate sweep of `docs/superpowers/plans/` +
   memory for clear direction-changes (the candidate list above), presented for
   trim before commit. Alternative: a fuller git sweep since a cutoff date.

## Risks

- **Ledger itself drifts / a wrong line cascades.** Mitigated: entries are
  dated, append-only, and todo-linked (auditable); the audit pre-fills but does
  not silently edit, so a bad line is caught in the per-finding walkthrough.
- **Capture step is forgotten.** Mitigated by the docs-update backstop in
  `updating-documentation` — any direction-changing doc edit re-checks for a
  line.
- **Bar creep (ledger becomes a changelog).** Mitigated by the explicit bar in
  §3 and non-examples; reviewers should reject lines that don't change
  direction.
