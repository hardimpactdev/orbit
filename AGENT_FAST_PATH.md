# Orbit Agent Fast Path

Use this as the first five-minute route. It is a navigation aid, not product
authority; route proportionally — load only what the chosen lane needs.
Ordinary changes do not require full `HARNESS.md` ingestion before routing.

## Choose The Lane

| Request shape | Start here | Then verify with |
| --- | --- | --- |
| Feature, bug fix, docs correction, or command behavior change | `AGENTS.md`, `.agents/skills/implementing-features/SKILL.md` (loads `HARNESS.md` sections per state) | Focused Pest/docs-lint/Mago, then `composer quality-check` when broadly safe |
| Command UX, JSON output, stream output, or CLI option behavior | `.agents/skills/command-designer/SKILL.md` and the command docs under `apps/docs/content/domains/` | Focused CLI/gateway Pest plus `composer docs-lint`, then the `retained-incus` acceptance venue (`HARNESS.md` venue table) |
| Product docs or Librarian/docs-lint work | `.agents/skills/librarian/SKILL.md` and `apps/docs/content/` authority docs | `composer docs-lint` and focused docs Pest/Mago when PHP rules changed |
| Eval design, execution, or review | `.agents/skills/orbit-evals/SKILL.md`, then construct, execute, evaluate | Structured eval suite/case/run/trial/review artifacts |
| Quality-gate failure or timing warning | `.agents/skills/quality-gate-triage/SKILL.md` | Narrow failing lane first; do not rerun E2E |
| Release, git, or branch cleanup / LAND | `HARNESS.md` LAND; prefer `bin/orbit-feature-land` | `bin/orbit-feature-finalization-check` before each destructive merge/cleanup/tmux-session mutation |

## Implementation Route

1. Use `~/shared-knowledge/projects/orbit/` only when a complex roadmap or durable design needs
   one; ordinary features use the compact local loop anchor.
2. For repository implementation, create an isolated worktree with
   `bin/orbit-prepare-worktree`; it seeds `.orbit/loop.md` when missing. Do
   not edit the primary checkout directly.
3. Fill Goal and Scope in the seeded `.orbit/loop.md`, then follow
   `.agents/skills/implementing-features/SKILL.md` through
   `FRAME -> BUILD <-> PROVE -> ACCEPT -> LAND`; `HARNESS.md` holds the
   canonical detail per state.
4. Run the narrowest useful verification while developing. In LAND, prefer
   `bin/orbit-feature-land`; validate every destructive mutation with
   `bin/orbit-feature-finalization-check`, execute only after
   `FINALIZATION: PASS`, then archive with the now-landed
   `bin/orbit-session-archive` from the feature worktree (never cwd main) and
   commit archive/index before cleanup.

## Search Route

- Use default `rg` from the root, or scope to the owning path from
  `apps/docs/content/generated/monorepo-unit-map.json`.
- Avoid `find .`, `rg -uu`, `rg --hidden --no-ignore`, and broad glob scans
  from the root unless the task explicitly needs ignored or generated files.
- When ignored files are needed, name the owned path and exclusions explicitly
  (for example `rg --hidden --glob '!/.worktrees/**' --glob '!vendor/**'
  <pattern> <owned-path>`).
- Treat `.worktrees/`, active `.orbit/` state outside `.orbit/sessions/`,
  `vendor/`, `node_modules/`, build outputs, storage, caches, and retained
  artifacts as search exclusions by default; open generated artifacts or
  committed session archives only when the route calls for them.

## Verification Route

- Docs-only: focused docs checks, then `composer docs-lint`. Non-docs: focused
  owning Pest/Mago, then `composer quality-check`. Integrated topology:
  retained real-surface proof per `HARNESS.md` Acceptance Venues.
- `composer test:e2e*` lanes are human-only; agents never trigger them.
  Canonical rule: `HARNESS.md`.

## Stop Conditions

- Product docs conflict with `PRODUCT_DECISIONS.md` or a newer dated decision.
- A requested eval would expose answer keys, hidden graders, private transcripts,
  secrets, or live-node details.
- The needed verification lane requires live topology proof that cannot be
  captured inside the current slice.
