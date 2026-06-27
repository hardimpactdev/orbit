---
name: orbit-docs-development
description: Use when working in apps/docs, apps/docs/content, Librarian docs, docs-lint, command catalog generation, product contracts, or documentation drift.
---

# Orbit Docs Development

`apps/docs` is the Laravel documentation and Librarian application. It owns the
product documentation under `apps/docs/content/`, docs linting, generated
documentation indexes, and command catalog checks.

## When To Use

- Editing product docs under `apps/docs/content/`.
- Changing Librarian configuration, generated documentation indexes, command
  catalog inputs, docs linting, or docs-local Laravel code under `apps/docs/`.
- Resolving product contract drift between docs, tests, command output, and
  `PRODUCT_DECISIONS.md`.

## Boundaries

- Product behavior contracts live in `apps/docs/content/`.
- `PRODUCT_DECISIONS.md` records dated direction changes; it anchors intent
  when docs conflict.
- Session plans, specs, and loop artifacts are not product authority.
- Gateway implementation belongs in `apps/gateway`; CLI behavior belongs in
  `apps/cli`; shared contracts belong in `packages/core`.

## Required Skills

- Read `.agents/skills/librarian/SKILL.md` before changing Librarian-managed
  docs or generated documentation structure.
- Read `.agents/skills/updating-documentation/SKILL.md` for Orbit product docs
  and command contract docs.
- Read `.agents/skills/auditing-docs-drift/SKILL.md` when the task is drift,
  contradiction, stale terminology, or broken-anchor analysis.

## Verification

From the repo root:

```bash
composer docs-lint
cd apps/docs && vendor/bin/mago format --check
composer quality-check
```

For command documentation changes, also check the command catalog guidance in
`apps/docs/content/command-catalog.md`.
