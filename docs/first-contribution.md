---
title: "Your first contribution"
sidebarTitle: "First contribution"
description: "The steps a person follows to make a first change to Orbit, from a fresh clone to a merged pull request."
covers:
  - .github/pull_request_template.md
  - bin/project-vocabulary
---

# Your first contribution

This page is the short path from a fresh clone to a merged pull request, for a person who works by hand. The [contributor guide](/contributor-guide) holds the full rules, and each step below links to the part that explains it.

The contributor guide also describes work that Orbit's maintainers and Orbit's own agents do, such as Orbit Tasks and Incus review. You do not run that work. [What you can skip](#what-you-can-skip) lists it. Ask questions and report bugs in [GitHub issues](https://github.com/hardimpactdev/orbit/issues).

## 1. Get the code and the tools

Fork [hardimpactdev/orbit](https://github.com/hardimpactdev/orbit) on GitHub, then clone your fork:

```bash
git clone https://github.com/YOUR-ACCOUNT/orbit.git
cd orbit
git remote add upstream https://github.com/hardimpactdev/orbit.git
```

Before each change, start a branch from the latest `main` of the main repository:

```bash
git fetch upstream
git switch -c my-change upstream/main
```

Install the tools for the projects that you plan to change.

| Tool | Needed for |
| --- | --- |
| Git | Every change |
| PHP 8.5 and Composer 2 | The five Composer projects and the documentation checks |
| OpenSSL | `bin/bootstrap` |
| PCOV or Xdebug, optional | Running only the tests that your change affects |
| [Bun](https://bun.sh) | The web app, the Pi server, and the desktop app |
| [Rust](https://rustup.rs) | The Node agent and the desktop app |

[Bun and Rust projects](/reference/bun-and-rust-projects#prerequisites) lists the Bun and Rust versions. The Gateway runs only on Linux. Its tests also run on macOS with extra tools, which [Implement and verify](/contributor-guide#3-implement-and-verify) lists.

## 2. Install the dependencies

From the repository root, run:

```bash
bin/bootstrap
```

`bin/bootstrap` installs the five Composer projects, then runs their tests and quality checks. It also checks the project guidance and seeds the cache that selects affected tests. When `~/.orbit/gateway.app-key` is missing, it writes a new Gateway app key there, outside the clone.

Add `--skip-checks` to install without the checks. The default run includes the Gateway tests. On macOS, they need the extra tools that [Implement and verify](/contributor-guide#3-implement-and-verify) lists, so use `--skip-checks` until you install them. Add `--with-bun` or `--with-rust` to also install the locked dependencies of the Bun and Rust projects.

## 3. Pick a project and read its guidance

Orbit has nine projects, and the [README](https://github.com/hardimpactdev/orbit#repository) lists them. Each project has an `AGENTS.md` file with its coding and testing rules, such as [`apps/cli/AGENTS.md`](https://github.com/hardimpactdev/orbit/blob/main/apps/cli/AGENTS.md). The file is written for agents, and its rules apply to people too. Read it before you change that project.

Before you build a feature, read the [mission](/mission), the [architecture](/architecture), and the [concepts](/concepts). A feature that makes a significant architecture decision also includes an ADR, as [Check the architecture](/contributor-guide#1-check-the-architecture) explains.

## 4. Write the documentation, then the code

Update the pages under `docs/` that describe the behavior you change, before you write code. A fix that restores documented behavior, or a refactor that changes no behavior, can leave the documentation alone. [Write the documentation](/contributor-guide#2-write-the-documentation) has the details.

To list the pages that a change affects, run this from the repository root, with one `--paths` for each file that you plan to change:

```bash
bin/docs-impact --base "$(git merge-base upstream/main HEAD)" --paths apps/cli/app/Commands/GatewayCommand.php
```

The command prints a JSON report of the impacted pages. Running it is optional for you.

Then build the change and its tests. A bug fix adds a test that fails before the fix and passes after it.

## 5. Run the checks

In an Orbit task, the implementer runs only `composer test:affected` in each changed Composer project. The Gateway runs the full Project check at handoff. Outside an Orbit task, run these commands in each Composer project that you changed, such as `apps/cli`:

```bash
composer test:affected
composer check
```

For the web app, the Pi server, the Node agent, and the desktop app, run the commands in [Bun and Rust projects](/reference/bun-and-rust-projects). When you change pages under `docs/`, run these from the repository root:

```bash
composer docs-build
composer docs-lint
```

Commit `docs/generated/context.json` when `docs-build` changes it. Add a new page to the navigation in `docs/docs.json`, or `composer docs-nav` fails.

CI also runs checks from the repository root that no project check covers. They compare generated files and documentation with the code. Run the ones that match your change:

| When you change | Run from the root |
| --- | --- |
| An environment variable | `php bin/env-docs` |
| A Composer project's scripts, Pint, Rector, PHPStan, or PHPUnit setup | `php bin/tooling-baseline` |
| A Gateway API route or its data | `bin/docs-openapi --check`, then `bin/mcp-tools --check` |
| A new or moved docs page | `php bin/docs-nav` |
| Any text, for old names that `bin/project-vocabulary` lists | `php bin/project-vocabulary` |

The MCP tool manifest is generated from the API reference, so an API change can make both commands report drift. When either does, run `bin/docs-openapi` and then `bin/mcp-tools` without `--check`, and commit their output. The other checks name what to fix.

The vocabulary checker permits GitHub and [Reverb app](/reference/websocket-apps) identifiers. They belong to those integrations. Orbit uses Project and Instance identifiers for its own records.

## 6. Open a pull request

Push your branch to your fork and open a pull request against `main`. The [pull request template](https://github.com/hardimpactdev/orbit/blob/main/.github/pull_request_template.md) has three sections:

- **Change**: the problem and the resulting behavior. Link an issue when one exists.
- **Architecture and documentation**: the ADRs and the pages that the change adds or updates.
- **Verification**: the commands you ran, their results, and the limits. A bug fix says `Fixes #N` and names its regression test.

You can open a draft pull request while you work. Mark it ready for review when the change is complete.

## 7. Review and merge

After you mark the pull request ready, these steps follow:

1. CI runs the quality checks and tests of the projects that your change reaches. Fix any failure.
2. Orbit's independent reviewer checks the code, the documentation, and any ADRs. It also reproduces the change on Orbit's Incus machines and records the results.
3. You address the review findings and push the fixes. The reviewer checks them.
4. The maintainer approves the pull request, and it merges into `main`.

A merge needs passing CI, a successful independent code and Incus review, resolved findings, and the maintainer's approval. [Review and merge](/contributor-guide#review-and-merge) describes the review evidence.

## What you can skip

Orbit's maintainers and Orbit's own agents do the following work. A person who contributes by hand does not run it:

- Orbit Tasks and their docs subtasks. The rule that each Orbit group starts with a docs subtask applies only there.
- Docs impact waivers in `.git/orbit/docs-unaffected/`. A reviewer confirms them for Orbit task branches.
- The Incus review. Orbit's reviewer reproduces your change on Incus, so you do not need Incus machines.
- The delegated final review and merge through the maintainer's GitHub CLI profile.
- The agent skills in `.agents/skills`. They guide an agent through the same steps as this page.
- Root `composer check`. It runs `bin/review-check`, the gate that Orbit Tasks run at each handoff. CI does not run it. The root checks in step 5 still apply to you.

[Review reproduces on a topology](/contributor-guide#review-reproduces-on-a-topology) explains why contributors do not run Incus.
