---
name: librarian
description: Use when creating, editing, linting, generating, or reconciling Librarian-managed documentation in Laravel projects.
---

# Librarian

Librarian keeps product intent, architecture, technology choices, domain notes,
and generated indexes aligned in Laravel projects.

## When To Use

- A Laravel project has or needs a Librarian-managed docs spine; in Orbit this
  is `apps/docs/content/`.
- Documentation domains need to be added, inserted, or renumbered.
- Generated docs such as `apps/docs/content/README.md` or
  `apps/docs/content/concepts.md` are stale.
- `librarian:lint` reports structure, generated-doc, link, placeholder, or prose
  findings.

## Core Commands

In this monorepo there is no root `artisan`; Librarian lives in the docs app.
Run Librarian commands through `bin/orbit-docs-artisan` from the repo root (or
`php artisan` from `apps/docs/`):

```bash
bin/orbit-docs-artisan librarian:init
bin/orbit-docs-artisan librarian:domain billing
bin/orbit-docs-artisan librarian:domains:normalize
bin/orbit-docs-artisan librarian:build
bin/orbit-docs-artisan librarian:lint
```

Use `librarian:build` after changing docs that affect generated indexes. Use
`librarian:lint` as the read-only CI check.

## Documentation Spine

Orbit's Librarian-managed docs spine lives at `apps/docs/content/`
(`config/librarian.php` points `path` at `base_path('content')`):

```text
apps/docs/content/
  README.md
  mission.md
  architecture.md
  tech-stack.md
  concepts.md
  domains/
```

Do not hand-edit `apps/docs/content/README.md` or
`apps/docs/content/concepts.md`; Librarian regenerates them. Put product intent
in `mission.md`, system shape in `architecture.md`, implementation choices in
`tech-stack.md`, and domain-specific notes under `apps/docs/content/domains`.
In other Laravel projects the spine defaults to `docs/`.

## Domain Workflow

- Use `librarian:domain <slug>` to add a domain.
- Use `--before=<slug>` or `--after=<slug>` when order matters.
- Use lowercase kebab-case slugs.
- After moving domains manually, run `librarian:domains:normalize`.

## Fixing Lint Findings

- Treat generated-doc findings as a signal to run `librarian:build`.
- Fix missing required sections by adding real project-specific prose.
- Remove scaffold prompt text instead of rewording it into vague placeholders.
- Prefer local markdown links that point at existing files or headings.
