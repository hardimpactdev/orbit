# Orbit Agent Fast Path

Use this as the first five-minute route before opening deeper docs. It is a
navigation aid, not product authority; route proportionally — load only what
the chosen lane needs. Ordinary changes do not require full `HARNESS.md`
ingestion before routing.

## Choose The Lane

| Request shape | Start here | Then verify with |
| --- | --- | --- |
| Feature, bug fix, docs correction, or command behavior change | `AGENTS.md`, `.agents/skills/implementing-features/SKILL.md` (loads `HARNESS.md` sections per state) | Focused Pest/docs-lint/Mago, then `composer quality-check` when broadly safe |
| Command UX, JSON output, stream output, or CLI option behavior | `.agents/skills/command-designer/SKILL.md` and the command docs under `apps/docs/content/domains/` | Focused CLI/gateway Pest plus `composer docs-lint`, then the `retained-incus` acceptance venue (`HARNESS.md` venue table) |
| Product docs or Librarian/docs-lint work | `.agents/skills/librarian/SKILL.md` and `apps/docs/content/` authority docs | `composer docs-lint` and focused docs Pest/Mago when PHP rules changed |
| Eval design, execution, or review | `.agents/skills/orbit-evals/SKILL.md`, then construct, execute, and evaluate in order | Structured eval-suite/case/run/trial/review artifacts |
| Quality-gate failure or timing warning | `.agents/skills/quality-gate-triage/SKILL.md` | Narrow failing lane first; do not rerun E2E |
| Release, git, or branch cleanup / LAND | `HARNESS.md` LAND and `.agents/skills/implementing-features/SKILL.md`; prefer `bin/orbit-feature-land` | `bin/orbit-feature-finalization-check` before each destructive merge/cleanup/Solo mutation |

## Implementation Route

1. Use a Solo scratchpad only when a complex roadmap or durable design needs
   one; ordinary features use the compact local loop anchor.
2. For repository implementation, create an isolated worktree with
   `bin/orbit-prepare-worktree`; it seeds `.orbit/loop.md` when missing. Do
   not edit the primary checkout directly.
3. Fill Goal and Scope in the seeded `.orbit/loop.md`, then move through
   `FRAME -> BUILD <-> PROVE -> ACCEPT -> LAND`.
4. In LAND, prefer `bin/orbit-feature-land` from primary `main` with exact
   branch, worktree, and session-owned Solo project id (`--status` /
   `--one-step` for inspect/resume). Lint the completed packet with
   `bin/orbit-feature-finalization-check --lint .orbit/loop.md`; FRAME and BUILD
   packets intentionally remain pending and are not finalization-lintable.
5. Read the authority docs first, then align docs, tests, and code in that
   order when behavior changes.
6. Run the narrowest useful verification while developing.
7. Use one general reviewer and always complete the diff-derived proof venue.
   Involve the user only when `HUMAN_JUDGMENT: required`; otherwise use the
   automated actor. Record the exact accepted feature and current main tips.
8. Before each destructive merge/cleanup/Solo mutation, run the finalization
   helper with the actual command; execute only after `FINALIZATION: PASS`.
9. After merge, stay in the accepted feature worktree and run its now-landed
   `bin/orbit-session-archive`; never run the compact archive with cwd main.
   Commit archive/index before cleanup. Archive names are tool-generated; never
   hand-write timestamps or directories.
10. Use `bin/orbit-session-archive --full` only for failure, escalation,
    security/release scope, or explicit request.

## Search Route

- Use default `rg` from the repository root, or scope searches to the owning
  path from `apps/docs/content/generated/monorepo-unit-map.json`.
- Avoid `find .`, `rg -uu`, `rg --hidden --no-ignore`, and broad glob scans
  from the root unless the task explicitly needs ignored or generated files.
- When ignored files are needed, name the owned path and exclusions explicitly,
  for example `rg --hidden --glob '!/.worktrees/**' --glob '!vendor/**'
  <pattern> <owned-path>`.
- Treat `.worktrees/`, active `.orbit/` state outside `.orbit/sessions/`,
  `vendor/`, `node_modules/`, build outputs, app storage, caches, and retained
  artifacts as search exclusions by default. Open generated artifacts or
  committed session archives only when the route calls for them, such as the
  command catalog, monorepo unit map, harness signal index, or named session
  archive.

## Verification Route

- Docs-only contract or docs-lint work: focused docs Pest/Mago when applicable,
  `composer docs-lint`, then `composer quality-check` if the diff should be
  broadly safe.
- CLI/gateway/core/sdk behavior: focused Pest in the owning app/package, Mago
  format/lint/analyze on touched PHP, then `composer quality-check`.
- Integrated topology or runtime-node behavior: focused owning tests first,
  then retained real-surface acceptance with topology id, role/node, command,
  ready Solo terminal, and evidence.
- `composer test:e2e*` lanes are human-only; agents never trigger them.
  Canonical rule: `HARNESS.md`.

## Stop Conditions

- Product docs conflict with `PRODUCT_DECISIONS.md` or a newer dated decision.
- A requested eval would expose answer keys, hidden graders, private transcripts,
  secrets, or live-node details.
- The needed verification lane requires live topology proof that cannot be
  captured inside the current slice.
