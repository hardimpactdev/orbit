---
name: vite-plus
description: Use when working with VitePlus, the vp CLI, frontend dependencies, development servers, builds, tests, linting, formatting, type checks, task execution, configuration, or toolchain troubleshooting.
license: MIT
metadata:
  source: vite-plus
---

# VitePlus

## Overview

VitePlus is the project's unified frontend toolchain. Use `vp` as the entry
point for Vite, Vitest, Oxlint, Oxfmt, package management, and task execution.

## Workflow

1. Inspect `package.json`, the lockfile, and `vite.config.ts`.
2. Run `vp help` or `vp <command> --help` before using unfamiliar options.
3. Read the minimum relevant file under `node_modules/vite-plus/docs/`.
4. Use the project's existing script or the narrowest `vp` command.
5. Run the applicable check, test, and build gates before finishing.

## Quick Reference

| Intent | Command | Documentation |
| --- | --- | --- |
| Install dependencies | `vp install` | `docs/guide/install.md` |
| Development server | `vp dev` | `docs/guide/dev.md` |
| Format, lint, and type-check | `vp check` | `docs/guide/check.md` |
| JavaScript tests | `vp test` | `docs/guide/test.md` |
| Production build | `vp build` | `docs/guide/build.md` |
| Run a package script | `vp run <script>` | `docs/guide/run.md` |
| Diagnose the environment | `vp env doctor` | `docs/guide/env.md` |

Documentation paths are relative to `node_modules/vite-plus/`. If dependencies
are not installed, use command help or the official VitePlus documentation.

## Project Rules

- Use `vp run <name>` for custom `package.json` scripts. Built-in commands such
  as `vp build`, `vp test`, and `vp check` are not script aliases.
- Preserve `defineCraftConfig()` and its Laravel, Inertia, React, Tailwind,
  Wayfinder, and Artisan-runner integration.
- Do not run `vp migrate`, `vp upgrade`, `vp update`, or `vp implode` unless the
  user explicitly requests that lifecycle change.
- Do not replace VitePlus with raw Vite, Vitest, Oxlint, Oxfmt, npm, or another
  package manager merely because their commands are more familiar.

## Verification Example

```bash
vp check
vp test
vp build
```

Run only the gates relevant to the change, plus any stronger project-level
Composer script defined in `AGENTS.md`.

## Common Mistakes

- Running `npm run` when the project uses `vp run`.
- Guessing inherited Vite or Vitest flags without checking VitePlus help.
- Editing generated configuration instead of the source `vite.config.ts`.
- Treating an environment repair or toolchain upgrade as routine validation.
