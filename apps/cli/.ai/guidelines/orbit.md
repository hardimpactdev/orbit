# Orbit CLI Guidelines

## Context

- This repository is a Laravel Zero 13 client, not a web application.
- Confirm installed package versions before using version-specific APIs.
- Follow existing file structure and sibling conventions. Do not add a dependency or a new top-level directory without approval.

## Skills

- Activate the relevant skill from `.agents/skills` before work in that domain.
- Every named skill must have a readable `SKILL.md`. Treat a missing skill as an incomplete Boost bootstrap and use the Required Guidance Bootstrap recovery steps.

## Verification

- During development, run the Pest 5 tests that cover the change with a file path or `--filter`. `composer test` runs the full suite, and CI runs checks across all projects.
- Follow the root `AGENTS.md` check policy for task and non-task work. Pint, Larastan, and Rector are the configured PHP quality tools.
