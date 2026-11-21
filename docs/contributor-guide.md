---
title: "Contributing to Orbit"
sidebarTitle: "Contributor guide"
description: "Prepare architecture and documentation, build a feature, and submit a complete pull request."
covers:
  - "{.agents/skills/reviewing-pull-requests/references/test-*.md,.agents/swamp-upstream/**,tools/test_ci_swamp_skills.py}"
  - "{composer.json,.gitattributes,.githooks/pre-push}"
  - bin/{bootstrap,gateway-app-key,test,pest-plain,review-check,docs-merge-check,build-api,docs-navigation,docs-flows,check-flows,dependency-audit}
  - "{apps/*,packages/php-sdk}/composer.json"
  - "{apps/*,packages/php-sdk}/phpstan.neon"
  - apps/gateway/{database/migrations/2026_12_03_000000_allow_cold_recipe_image_rebuilds.php,tests/Support/TestToolchain.php}
  - apps/docs/**
  - .github/{dependabot,workflows/ci,workflows/dependency-audit}.yml
---

# Contributing to Orbit

Ship every feature as one complete pull request containing code, tests, documentation, and any architecture decisions. New contributors working manually can start with [Your first contribution](/first-contribution).

Every change follows the same order: check architecture, update documentation, implement and prove in a task copy (with its nested fleet for fleet behavior), open a complete PR, pass review and CI, and merge through the merge queue ([stage 4](/reference/command-development-pipeline#4-pr-and-ci)). For command changes, commit the spec (chart, page, and cases) first.

## Bootstrap

Install the Composer projects and repository hooks from the root:

```bash
bin/bootstrap
```

`bin/bootstrap` installs the four Composer projects serially (sharing one download cache), generates `$ORBIT_HOME/gateway.app-key` (default `~/.orbit/gateway.app-key`) when missing, configures `core.hooksPath` to `.githooks`, and runs each project's checks. Repeat runs skip unchanged installs using fingerprint files (`vendor/.orbit-bootstrap-*`, `node_modules/.orbit-bootstrap-install`, `target/.orbit-bootstrap-fetch`); delete those records to force a fresh run.

Useful flags:

- `--skip-checks`: Install dependencies and hooks without running tests or static checks.
- `--dependencies-only`: Skip checks and Gateway key generation while still installing dependencies, changed outputs, and `.githooks`.
- `--with-bun` / `--with-rust`: Also install locked Bun (`apps/web`, `apps/desktop`) or Cargo (`apps/agent`, `apps/desktop`) dependencies ([Bun and Rust projects](/reference/bun-and-rust-projects)).

The pre-push hook runs `bin/docs-impact --gate --base origin/main` and blocks pushes when covered pages are neither updated nor waived.

## 1. Check the architecture

Read the [mission](/mission), [architecture](/architecture), and [concepts](/concepts), then inspect the docs, code, and tests for the area you are changing. Use `composer docs-context -- --component=apps/cli` (or `--concept=Cluster`) for an ordered reading list.

### Decisions ship with their feature

When a change introduces a significant architectural boundary, contract, security rule, or hard-to-reverse choice, add an ADR on the same branch marked `In progress.` and naming the mission principle it serves (`Principle:`). When the feature is complete, absorb the decision into the owning documentation page, remove the ADR file, and register its redirect in `apps/docs/config/adr-retired-slugs.php` and `docs/site.json`. See the [ADR guide](https://github.com/hardimpactdev/orbit/blob/main/docs/decisions/README.md).

## 2. Write the documentation

Update affected pages under `docs/` before writing code, in present tense, describing user-visible behavior, limits, and failures. Bug fixes that restore documented behavior and behavior-preserving refactors need no doc edits. If code, tests, docs, and ADRs disagree, stop and resolve the conflict explicitly.

### Docs impact stays in the repository

Run `bin/docs-impact` against the merge base with `origin/main` to list pages covering your planned paths:

```bash
bin/docs-impact --base <start-commit> --paths <planned-path>
```

The checker maps every CLI family via `DocsImpact::CLI_FAMILIES` to `docs/cli/<family>/index.mdx`; register new families and pages in the same PR. Both `bin/task-check` and CI's Docs job run `bin/docs-impact --gate`. When a covered page genuinely needs no update, add a waiver line to the **PR body** (or the local file pointed to by `--pr-body-file`, `ORBIT_DOCS_PR_BODY_FILE`, or `<git-common-dir>/orbit/proposed-pr-bodies/<branch>.md`):

```text
Docs-unaffected: docs/reference/tasks.md: Fixes implementation to match documented behavior.
```

### One documentation corpus

Rebuild derived files and run the linter from the repository root after editing docs:

```bash
composer docs-build
composer docs-lint
```

Generated artifacts stay ignored by Git (`docs/generated/context.json`, `docs/openapi.json`, `apps/gateway/resources/mcp/tools.json`, `apps/web/src/api/schema.d.ts`, `docs/docs.json`, `docs/flows-data.js`). `composer docs-lint` enforces structure, links, and prose style (`orbit.docs_prose_style` rejects em dashes, filler phrases, and vague headings). `bin/build-api` regenerates the OpenAPI spec and MCP tool manifest; `bin/docs-merge-check` verifies navigation and generated-file merges; CI publishes the built docs to the `docs-site` branch.

### Parallel command families

Command families register in separate files so parallel PRs do not conflict on central registries:

| Surface | Registration path |
| --- | --- |
| Gateway service provider | `apps/gateway/app/Domains/<Domain>ServiceProvider.php` |
| API routes | `apps/gateway/routes/api/<family>.php` |
| Operation kinds | `apps/gateway/app/Domain/<Domain>/operation-kinds.php` |
| CLI vocabulary | `apps/cli/app/CommandVocabulary/<family>.php` |
| Docs navigation | `docs/navigation/<page-or-family>.json` |

## 3. Implement and verify

Keep project-specific implementation details in each project's `AGENTS.md` (mirrored via `CLAUDE.md`) and `.agents/skills` (symlinked at `.claude/skills`).

### Checks that need no network

All four Composer projects (`apps/gateway`, `apps/cli`, `packages/php-sdk`, `apps/docs`) run offline tests and static checks:

- **Per-project:** `composer test` and `composer check` inside the project directory.
- **Root PHP suites:** `bin/test` runs all Composer suites (use `bin/pest-plain` for clean output without ANSI formatting).
- **Task copy gate:** `bin/task-check` runs scoped Pest, Pint, PHPStan, `docs-impact`, `swamp-shape-gate`, CI policy, and API fixtures for changed paths ([The scoped task check](/reference/implementation-loop#the-scoped-task-check)).
- **Local full gate:** Root `composer check` runs `bin/review-check` ([The candidate gate](/reference/implementation-loop#the-candidate-gate)).

### Static analysis

Every PHP project runs PHPStan at level 9 plus Pint and Rector (`composer check`). Formatters run in fix mode locally ([Formatters run in fix mode](/reference/implementation-loop#formatters-run-in-fix-mode)).

- Fix findings in code rather than silencing them with baselines, `ignoreErrors`, `@phpstan-ignore`, `assert()`, or inline `@var` casts ([Findings are fixed, not silenced](/reference/implementation-loop#findings-are-fixed-not-silenced)).
- `tools/phpstan/orbit-rules.neon` disallows `strtotime()` and inline `@var` overrides (`NoInlineVarOverrideRule`). The only permitted `ignoreErrors` entry is the `count: 3` entry on `ToolCommand.php` in `apps/cli/phpstan.neon`.

### Gateway tests on macOS and Linux

Gateway tests use `Storage::fake('local')`, `WithCommittedSqliteDatabase` for committed SQLite checks, and the database safety guard ([Gateway tests and privilege](/reference/implementation-loop#gateway-tests-and-privilege)). On macOS, install the GNU tools and Caddy required by `TestToolchain`:

```bash
brew install bash coreutils gnu-sed findutils caddy
```

To run Linux-only Gateway tests from macOS, point `ORBIT_LINUX_TEST_HOST` (optional `ORBIT_LINUX_TEST_TIMEOUT`) at a Linux host. See [Gateway test time limits](/reference/implementation-loop#gateway-test-time-limits) and [Parallel processes](/reference/implementation-loop#parallel-processes) for execution limits. Docs tests (`apps/docs/phpunit.xml`) do not enforce a per-test wall-clock limit.

## Dependencies

Dependabot opens one daily multi-ecosystem update PR across Composer, Bun, and full-SHA GitHub Actions pins. Keep Vite+ and its `vite` / `vitest` overrides aligned across `apps/web/package.json`. Run `composer audit:dependencies` (`bin/dependency-audit`) locally; `.github/workflows/dependency-audit.yml` runs nightly.

## Review and merge

CI runs all affected PHP suites (`gateway-tests`, `gateway-real-clients`, `cli-tests`, `sdk-tests`, `docs`), `web` (unit, verification, browser), `agent` (Cargo check, Clippy, test), `swamp`, `proof`, and `ci-policy` ([Full suites gate merges](/reference/implementation-loop#full-suites-gate-merges)).

### Review reproduces on a topology

Contributors do not need Incus machines. Maintainers and Orbit's independent reviewer reproduce fleet changes inside a task copy's nested fleet before approving merge, recording the tested commit, commands, exit codes, and resulting state.

Once `Required checks` and review pass, queue the PR for squash merge on its exact verified head ([Merge queue](/reference/implementation-loop#merge-queue)):

```bash
gh pr merge <number> -R hardimpactdev/orbit --squash --auto --match-head-commit <SHA>
```
