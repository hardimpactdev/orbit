# Orbit Agent Fast Path

Use this as the first five-minute route before opening deeper docs. It is a
navigation aid, not product authority.

## Choose The Lane

| Request shape | Start here | Then verify with |
| --- | --- | --- |
| Feature, bug fix, docs correction, or command behavior change | `AGENTS.md`, `HARNESS.md`, `.agents/skills/implementing-features/SKILL.md` | Focused Pest/docs-lint/Mago, then `composer quality-check` when broadly safe |
| Command UX, JSON output, stream output, or CLI option behavior | `.agents/skills/command-designer/SKILL.md` and the command docs under `apps/docs/content/domains/` | Focused CLI/gateway Pest plus `composer docs-lint` |
| Product docs or Librarian/docs-lint work | `.agents/skills/librarian/SKILL.md` and `apps/docs/content/` authority docs | `composer docs-lint` and focused docs Pest/Mago when PHP rules changed |
| Eval design, execution, or review | `.agents/skills/orbit-evals/SKILL.md`, then construct, execute, and evaluate in order | Structured eval-suite/case/run/trial/review artifacts |
| Quality-gate failure or timing warning | `.agents/skills/quality-gate-triage/SKILL.md` | Narrow failing lane first; do not rerun E2E |
| Release, git, or branch cleanup | `HARNESS.md` merge boundary and `.agents/skills/spatie-version-control/SKILL.md` | `bin/orbit-feature-finalization-check` before merge or cleanup |

## Implementation Route

1. Use Solo scratchpads for multi-slice roadmaps and eval artifacts.
2. For repository implementation, create an isolated worktree with
   `bin/orbit-prepare-worktree`; do not edit the primary checkout directly.
3. Copy `LOOP.md.example` to `.orbit/loop.md` for non-trivial work and keep it
   current.
4. Make `bin/orbit-feature-finalization-check --lint .orbit/loop.md` a
   first-checkpoint habit: lint the packet shape early and after edits, not
   only at the merge boundary.
5. Read the authority docs first, then align docs, tests, and code in that
   order when behavior changes.
6. Run the narrowest useful verification while developing.
7. Before merge or cleanup, run the finalization helper with the actual git
   command and archive `.orbit/` to the primary checkout.
8. Archive names are tool-generated: run `bin/orbit-session-archive` instead of
   hand-writing archive timestamps or directories.
9. For disposable Solo agents, capture needed output, verify the artifact, then
   stop or delete the process in a separate command.

## Search Route

- Use default `rg` from the repository root, or scope searches to the owning
  path from `apps/docs/content/generated/monorepo-unit-map.json`.
- Avoid `find .`, `rg -uu`, `rg --hidden --no-ignore`, and broad glob scans
  from the root unless the task explicitly needs ignored or generated files.
- When ignored files are needed, name the owned path and exclusions explicitly,
  for example `rg --hidden --glob '!/.worktrees/**' --glob '!vendor/**'
  <pattern> <owned-path>`.
- Treat `.worktrees/`, `.orbit/`, `vendor/`, `node_modules/`, build outputs,
  app storage, caches, and retained artifacts as search exclusions by default.
  Open generated artifacts only when the route calls for them, such as the
  command catalog, monorepo unit map, or harness signal index.

## Verification Route

- Docs-only contract or docs-lint work: focused docs Pest/Mago when applicable,
  `composer docs-lint`, then `composer quality-check` if the diff should be
  broadly safe.
- CLI/gateway/core/sdk behavior: focused Pest in the owning app/package, Mago
  format/lint/analyze on touched PHP, then `composer quality-check`.
- Integrated topology or runtime-node behavior: focused Pest first, then
  retained topology proof with topology id, role/node, command, and evidence.
- E2E Composer commands are manual-only. Agents do not run `composer test:e2e*`
  unless the user explicitly invokes that command from a shell.

## Stop Conditions

- Product docs conflict with `PRODUCT_DECISIONS.md` or a newer dated decision.
- A requested eval would expose answer keys, hidden graders, private transcripts,
  secrets, or live-node details.
- The needed verification lane requires live topology proof that cannot be
  captured inside the current slice.
