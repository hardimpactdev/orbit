# Compound Engineering (Monorepo)

This repo follows the compound engineering loop:

Plan -> Work -> Review -> Compound -> repeat

The goal is to make each unit of work make the next unit easier.

## Plan

- Capture scope + verification in issue/task notes.
- Keep scope to one package or one cross-package change.
- Record verification steps per package.

## Work

- Work directly on main.
- Prefer small, reviewable changes that keep packages aligned.
- Use package-specific commands:

### packages/app (orbit-ui)

- Install: `bun install`
- Build: `bun run build`

### packages/desktop (orbit-desktop)

- Install: `npm install --ignore-scripts`
- Build: `npm run build`

### packages/core (orbit-core)

- Backend only. Skip frontend build unless `resources/js/` exists.

## Review

Run quality gates appropriate to the package you touched:

- PHP: `composer test`, `composer analyse`, `composer format`
- Frontend: `bun run build` (or `npm run build` for desktop)

Keep reviews scoped to the packages you changed.

## Compound

- Capture new learnings in `docs/solutions/`.
- Focus on: symptoms, root cause, fix, prevention.

## Monorepo Notes

- Use bun for frontend builds by default.
- Desktop is the only package using npm for builds.
- Keep plan notes in the issue/task to keep the tree clean.
