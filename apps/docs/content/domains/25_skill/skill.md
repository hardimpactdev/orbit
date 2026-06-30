# Skill

## Purpose

This domain owns the command contracts and product vocabulary for Orbit's
local skill installation command family. See [Skill command contracts](README.md).

## Responsibilities

The skill domain keeps local skill installation behavior consistent across
operator-facing docs, CLI contracts, and tests.

- Document the supported LLM provider slugs and their default user skill
  directories.
- Preserve the boundary that `skill:*` commands copy the bundled Orbit skill
  locally and do not install downloadable extensions.
- Keep local filesystem overwrite behavior, JSON envelopes, and failure codes
  aligned with the CLI implementation.

## Boundaries

This landing page is an index for Librarian's domain spine. It does not change
Orbit behavior; detailed contracts remain in the linked command and technical
documents.
