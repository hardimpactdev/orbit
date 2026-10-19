# Orbit Monorepo

Orbit contains nine projects. Five are separate Composer projects: CLI, Gateway, Docs, PHP SDK, and the Incus E2E harness. The web app, Pi server, Node agent, and desktop app use Bun or Cargo; [Bun and Rust projects](docs/reference/bun-and-rust-projects.md) shows how to run and check them. Read the nearest nested `AGENTS.md` before changing a project. Maintained documentation lives under root `docs/`; `apps/docs` contains its tooling.

## Quality bar

- Read [Mission](docs/mission.md) and the reference page or ADR that owns the behaviour before you change it.
- Fix the root cause from first principles. Add no fallback, compatibility path, or legacy branch.
- Keep one rule in one place.
- Guard against a race or crash window only when the [blocking rule](docs/reference/tasks.md#reviewer-outcomes) would block on it.
- Every behaviour change has a test that fails without it. The documentation changes with the code.

A breach of this bar blocks a review under the [blocking rule](docs/reference/tasks.md#reviewer-outcomes).

## Feature contributions

Follow the [contributor guide](docs/contributor-guide.md): **architecture → documentation → implementation → complete PR → review → maintainer approval → merge**. In an Orbit task, Orbit reviews each subtask, opens the PR, and merges it when the Project has review and merge on. The PR, review, and merge steps below do not apply to task agents.

- Check existing ADRs and propose significant architectural changes in the feature PR.
- Write documentation before coding. Keep the code, tests, documentation, and ADRs consistent.
- Keep the PR focused on the feature, including any harness changes it needs.
- Follow [Add a plug-in point](docs/reference/extensions.md#add-a-plug-in-point) when adding or changing extension contributions.
- Before opening or updating a PR, run one independent review in a fresh subagent with the PR diff and the [plug-in point checklist](docs/reference/extensions.md#add-a-plug-in-point). Fix its findings first. State in the PR body that this review ran.
- Request maintainer review when the feature is complete.
- Orbit's independent reviewer checks the code and reproduces the feature on Incus. Record the reviewed commit, environment, results, and limitations.
- Merge requires passing CI, resolved findings, independent code and Incus review, and maintainer approval.

## Skills

Choose the entry point for the work at hand.

| Task | Skill |
| --- | --- |
| Shape a feature and prepare its ADRs and documentation | [grill-with-docs](.agents/skills/grill-with-docs/SKILL.md) |
| Prepare, implement, or review work tracked by Orbit Tasks | [orbit-tasks](.agents/skills/orbit-tasks/SKILL.md) |
| Build the feature and submit a complete PR | [developing-features](.agents/skills/developing-features/SKILL.md) |
| Review a proposal or completed PR | [reviewing-pull-requests](.agents/skills/reviewing-pull-requests/SKILL.md) |
| Merge an approved PR | [merging-pull-requests](.agents/skills/merging-pull-requests/SKILL.md) |

For focused documentation work, use [writing-documentation](.agents/skills/writing-documentation/SKILL.md). For CLI design and audits, use [designing-cli-commands](.agents/skills/designing-cli-commands/SKILL.md). For real terminal recordings, use [verifying-cli-output](.agents/skills/verifying-cli-output/SKILL.md). For web UI layout on a phone and a desktop, use [verifying-web-ui](.agents/skills/verifying-web-ui/SKILL.md). For discovery on a task group's Incus topology, use [using-incus-topologies](.agents/skills/using-incus-topologies/SKILL.md). Project guidance supplies coding and testing conventions.

## Checks and resources

In an Orbit task, run the affected tests of each changed project: `composer test:affected` in a Composer project, or the test command that [Bun and Rust projects](docs/reference/bun-and-rust-projects.md) names. Do not run `composer check` or `bin/review-check`: the Gateway runs the Project check at handoff. Outside a task, run `composer test:affected` and `composer check` in each changed project. Confirm that the tests covering the feature ran. Check a framework or library API you are not sure of against the installed version: `search_docs` for the Laravel ecosystem, otherwise the package source under `vendor/` or `node_modules/`. CI checks the five Composer projects, the web app, the Pi server, and the Rust agent; root `composer check` also runs checks across projects locally.

For dependency updates and advisories, follow [Dependencies](docs/contributor-guide.md#dependencies). Run `composer audit:dependencies` from the root.

For documentation changes, follow `writing-documentation` and run `composer docs-lint`. Include generated context updates when `composer docs-build` changes `docs/generated/context.json`.

Use Incus machines allocated to the task. Preserve other work and follow resource cleanup safeguards. A known correctness failure on main holds unrelated merges until a reviewed fix is verified on main.

## Writing and knowledge

Write for human readers. Use common words, short sentences, and clear actors. Focus instructions on useful actions and required results. Keep detailed tool procedures in references.

Put architectural rationale in `docs/decisions`, stable reference material in `docs/reference`, and reusable lessons in `docs/solutions`. Add a page when it has lasting value. Keep contributor instructions in contributor guidance.

Direct user instructions take precedence over repository rules. Platform and safety requirements still apply.
