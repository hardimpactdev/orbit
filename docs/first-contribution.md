---
title: "Your first contribution"
sidebarTitle: "First contribution"
description: "The steps a person follows to make a first change to Orbit, from a fresh clone to a merged pull request."
covers:
  - .github/pull_request_template.md
---

# Your first contribution

This guide walks through a manual contribution from a fresh clone to a merged pull request. The [contributor guide](/contributor-guide) holds the full rules, and [What you can skip](#what-you-can-skip) lists maintainer and agent workflows you do not need. Ask questions and report bugs in [GitHub issues](https://github.com/hardimpactdev/orbit/issues).

## 1. Get the code and tools

Fork [hardimpactdev/orbit](https://github.com/hardimpactdev/orbit) on GitHub, then clone your fork and add `upstream`:

```bash
git clone https://github.com/YOUR-ACCOUNT/orbit.git
cd orbit
git remote add upstream https://github.com/hardimpactdev/orbit.git
```

Start each branch from the latest `upstream/main`:

```bash
git fetch upstream
git switch -c my-change upstream/main
```

Install the tools needed for the projects you plan to touch:

| Tool | Needed for |
| --- | --- |
| Git | Every change |
| PHP 8.5 and Composer 2 | PHP projects and documentation checks |
| OpenSSL | `bin/bootstrap` |
| [Bun](https://bun.sh) | Web app and desktop app |
| [Rust](https://rustup.rs) | Node agent and desktop app |

See [Bun and Rust projects](/reference/bun-and-rust-projects#prerequisites) for exact Bun and Rust versions. The Gateway runs on Linux; running its tests on macOS requires extra tools listed in [Implement and verify](/contributor-guide#3-implement-and-verify).

## 2. Install dependencies

Run `bin/bootstrap` from the repository root:

```bash
bin/bootstrap
```

`bin/bootstrap` installs the four Composer projects, generates `~/.orbit/gateway.app-key` when absent, and runs their tests and quality checks. Pass `--skip-checks` to install without running checks (useful on macOS before installing the extra Gateway test tools). Pass `--with-bun` or `--with-rust` to install locked Bun or Cargo dependencies.

## 3. Pick a project and read its rules

Read the [README](https://github.com/hardimpactdev/orbit#repository) to locate the project you are changing, then read that project's `AGENTS.md` (for example, [`apps/cli/AGENTS.md`](https://github.com/hardimpactdev/orbit/blob/main/apps/cli/AGENTS.md)). Those coding and testing rules apply to people and agents alike.

Before building a feature, read the [mission](/mission), [architecture](/architecture), and [concepts](/concepts). Significant architectural decisions also ship with an ADR ([Check the architecture](/contributor-guide#1-check-the-architecture)).

## 4. Update docs, then write code

Update the affected pages under `docs/` before changing behavior in code. Bug fixes that restore documented behavior and behavior-preserving refactors can leave docs unchanged ([Write the documentation](/contributor-guide#2-write-the-documentation)).

You can preview which pages your planned paths cover:

```bash
bin/docs-impact --base "$(git merge-base upstream/main HEAD)" --paths apps/cli/app/Commands/GatewayCommand.php
```

Implement the change and add tests. Every bug fix includes a regression test that fails before the fix and passes after it.

## 5. Run checks

Run tests and quality checks in every Composer project you changed (such as `apps/cli`):

```bash
composer test
composer check
```

For `apps/web`, `apps/agent`, `apps/desktop`, or `swamp`, follow [Bun and Rust projects](/reference/bun-and-rust-projects). When you edit `docs/`, run the docs build and linter from the repository root:

```bash
composer docs-build
composer docs-lint
```

Keep generated outputs out of Git. Register any new doc page in its own `docs/navigation/` fragment ([Parallel command families](/contributor-guide#parallel-command-families)) so `composer docs-lint` passes.

When you change a Gateway API route or payload, regenerate and verify the API contract from the root:

```bash
bin/build-api
composer api-fixtures
```

## 6. Open a pull request

Push your branch to your fork and open a pull request against `main`. Fill in the three sections of the [pull request template](https://github.com/hardimpactdev/orbit/blob/main/.github/pull_request_template.md):

- **Change:** The problem and resulting behavior, plus any linked issue.
- **Architecture and documentation:** Added or updated ADRs and documentation pages.
- **Verification:** Commands run, results, and limits. Bug fixes include `Fixes #N` and name the regression test.

## 7. Review and merge

Once you mark the pull request ready for review, these steps run:

1. CI runs static analysis, builds, and tests for the projects your diff touches.
2. An independent reviewer checks code, documentation, and ADRs, and reproduces fleet behavior in a nested fleet.
3. You fix any review findings and push updates.
4. Once `Required checks` and review pass, the PR enters the GitHub merge queue and squash-merges into `main` ([stage 4](/reference/command-development-pipeline#4-pr-and-ci)).

Every PR must satisfy the [docs-impact gate](/contributor-guide#2-write-the-documentation). If a covered page needs no edit, add a `Docs-unaffected: <page>: <reason>` line to the PR body.

## What you can skip

Manual contributors do not need to run maintainer or agent workflows:

- Orbit Tasks briefs, subtasks, deliverables, and `bin/task-check`.
- Incus or nested-fleet review runs (handled during review; see [Review reproduces on a topology](/contributor-guide#review-reproduces-on-a-topology)).
- Agent skills in `.agents/skills`.
- Root `composer check` (`bin/review-check`); run the per-project, docs, and API checks in step 5 instead.
