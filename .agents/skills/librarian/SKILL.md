---
name: librarian
description: Use when creating, editing, linting, generating, or reconciling Librarian-managed documentation in Laravel projects.
---

# Librarian

Librarian keeps product intent, architecture, technology choices, domain notes,
and generated indexes aligned in Laravel projects.

## When To Use

- A Laravel project has or needs a `docs/` spine managed by Librarian.
- Documentation domains need to be added, inserted, or renumbered.
- Generated docs such as `docs/README.md` or `docs/concepts.md` are stale.
- `librarian:lint` reports structure, generated-doc, link, placeholder, or prose
  findings.

## Core Commands

```bash
php artisan librarian:init
php artisan librarian:domain billing
php artisan librarian:domains:normalize
php artisan librarian:build
php artisan librarian:lint
```

Use `librarian:build` after changing docs that affect generated indexes. Use
`librarian:lint` as the read-only CI check.

## Documentation Spine

```text
docs/
  README.md
  mission.md
  architecture.md
  tech-stack.md
  concepts.md
  domains/
```

Do not hand-edit `docs/README.md` or `docs/concepts.md`; Librarian regenerates
them. Put product intent in `mission.md`, system shape in `architecture.md`,
implementation choices in `tech-stack.md`, and domain-specific notes under
`docs/domains`.

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