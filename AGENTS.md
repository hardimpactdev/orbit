# Orbit Monorepo

This file is a map. Read the `AGENTS.md` in the folder of the project you change. `bin/docs-impact --paths <path>` names the page that owns a path; read the section that covers the behaviour you change.

| Change | Folder and rules | One test |
| --- | --- | --- |
| Gateway | `apps/gateway` | `vendor/bin/pest <file>` |
| CLI | `apps/cli` | `vendor/bin/pest <file>` |
| PHP SDK | `packages/php-sdk` | `vendor/bin/pest <file>` |
| Web app | `apps/web` | `bun run test:unit <file>`, or `test:browser` for `tests/browser` |
| Node agent | `apps/agent` | `scripts/check-crates test --locked <name>` |
| Desktop app | `apps/desktop` | none: Clippy and `bun run dev`, per its `AGENTS.md` |
| Docs | `docs/`; skill `writing-documentation` | `composer docs-lint` from the root |
| Docs tooling | `apps/docs` | `vendor/bin/pest <file>` |
| Swamp workflow | `swamp/`; skill `swamp-flow` | [Swamp tests](.agents/skills/swamp-flow/SKILL.md#swamp-tests) |
| Ansible phase | `swamp/ansible`; skill `ansible-phase` | as Swamp |
| Agent instructions | `AGENTS.md`, `.agents/skills` | ask a new `claude -p` session |

Run Pest, Bun and Cargo from the project folder. A command's flow starts at its chart, `docs/cli/<family>/<command>.mmd`; skill `preparing-commands`. Before you push a change under `swamp/`, run `bin/swamp-shape-gate`. A Gateway extension follows [Add a plug-in point](docs/reference/extensions.md#add-a-plug-in-point). Check a library API you are unsure of in its installed source under `vendor/` or `node_modules/`. Keep detailed tool procedures in references, and add a page only when it has lasting value.

## Build a feature end to end first

Build and prove every feature end to end in one Orbit container, a task copy with its nested fleet when the feature touches the fleet, before any PR opens. Then ship it in one PR. See [Build a feature in one copy](docs/reference/tasks.md#build-a-feature-in-one-copy).

## Quality bar

- Fix the root cause from first principles. Add no fallback, compatibility path, or legacy branch.
- Keep one rule in one place.
- Guard against a race or crash window only when the [reviewer rule](docs/reference/tasks.md#the-reviewer-rule) and its impact bar would block on it.
- Every behaviour change has a test that fails without it. The documentation changes with the code.

This bar guides the implementer. The [reviewer rule](docs/reference/tasks.md#the-reviewer-rule) alone says what blocks a review, and it wins over this bar.

## Checks and resources

In an Orbit task, run the tests that cover the change in each changed project. Before you hand off, run the Project's task check (`bin/task-check` for Orbit) and fix what it reports. Do not run `composer check` or `bin/review-check`: the task check and CI cover them. Outside a task, follow [Work outside a task](docs/contributor-guide.md#work-outside-a-task).

Direct user instructions take precedence over repository rules. Platform and safety requirements still apply.
