# Orbit Monorepo

Orbit contains ten projects. Five are separate Composer projects: CLI, Gateway, Docs, PHP SDK, and the Incus E2E harness. The web app, Pi server, Node agent, and desktop app use Bun or Cargo; [Bun and Rust projects](docs/reference/bun-and-rust-projects.md) shows how to run and check them. Read the nearest nested `AGENTS.md` before changing a project. Maintained documentation lives under root `docs/`; `apps/docs` contains its tooling.

Every command goes through the six stages of the [command development pipeline](docs/reference/command-development-pipeline.mdx): prep, proof environment, proof, PR and CI, automatic release, per-host refresh. The page and the stage charts in `docs/reference/pipeline/` are the one source of truth for the flow. A chart beats any other text. Skills link to them and say only what you must do at your own step.

Read the kind's chart first: `docs/cli/<family>/<command>.mmd`, beside its command page ([chart rules](docs/cli/README.md)); it states the intent. Any change to a command's flow starts by updating its chart to the ideal flow; the code follows the chart.

Swamp lives under `swamp/`. Read the binding [Swamp shape guide](swamp/docs/swamp-shape.md) before writing, porting, or reviewing an operation. The guide overrides older contracts and conflicting Recall atoms. Gateway and Swamp changes ship in one Orbit PR. Before you push a change under `swamp/`, run `bin/swamp-shape-gate`: it is the gate that CI runs.

Before you build on something, check [old paths](docs/reference/old-paths.md): it lists what a newer path replaces and when it goes.

## Quality bar

- Read [Mission](docs/mission.md) and the reference page or ADR that owns the behaviour before you change it.
- Fix the root cause from first principles. Add no fallback, compatibility path, or legacy branch.
- Keep one rule in one place.
- Guard against a race or crash window only when the [blocking rule](docs/reference/tasks.md#reviewer-outcomes) would block on it.
- Every behaviour change has a test that fails without it. The documentation changes with the code.

A breach of this bar blocks a review under the [blocking rule](docs/reference/tasks.md#reviewer-outcomes).

## Feature contributions

Follow the [contributor guide](docs/contributor-guide.md): **architecture → documentation → implementation → one PR → CI → gate → merge**. A command change is one PR whose first commit is the spec ([stage 1](docs/reference/pipeline/1-prep.mmd)); the PR, the gate and the merge are [stage 4](docs/reference/pipeline/4-pr-and-ci.mmd). In an Orbit task, Orbit reviews each subtask and opens the PR; the PR and merge steps do not apply to task agents.

- Check existing ADRs and propose significant architectural changes in the feature PR.
- Write documentation before coding. Keep the code, tests, documentation and ADRs consistent.
- Keep the PR focused on the feature, including any harness changes it needs.
- Follow [Add a plug-in point](docs/reference/extensions.md#add-a-plug-in-point) when adding or changing extension contributions.
- Before you open or update a PR, run one independent review in a fresh subagent with the PR diff and the plug-in point checklist. Fix its findings first. State in the PR body that this review ran.
- Merge needs green CI and the gate of stage 4.

## Skills

Choose the entry point for the work at hand. They follow the pipeline stages.

| Task | Skill |
| --- | --- |
| Shape a feature and prepare its ADRs and documentation | [grill-with-docs](.agents/skills/grill-with-docs/SKILL.md) |
| Prepare a command: chart, page and cases, the first commit of its PR (stage 1) | [preparing-commands](.agents/skills/preparing-commands/SKILL.md) |
| Define or run a command's proof on the nested fleet (stages 2 and 3) | [verifying-commands](.agents/skills/verifying-commands/SKILL.md) |
| Build a command or feature: code, PR, CI, merge (stage 4) | [developing-commands](.agents/skills/developing-commands/SKILL.md) |
| Create or change a command's Swamp flow, or review a flow PR | [swamp-flow](.agents/skills/swamp-flow/SKILL.md) |
| Write or review the Ansible part of a flow | [ansible-phase](.agents/skills/ansible-phase/SKILL.md) |
| Design or tighten a flow chart | [flow-tighten](.agents/skills/flow-tighten/SKILL.md) |
| Prepare, implement or review work tracked by Orbit Tasks | [orbit-tasks](.agents/skills/orbit-tasks/SKILL.md) |
| Review a proposal or completed PR | [reviewing-pull-requests](.agents/skills/reviewing-pull-requests/SKILL.md) |

For documentation work, use [writing-documentation](.agents/skills/writing-documentation/SKILL.md). For CLI design and audits, use [designing-cli-commands](.agents/skills/designing-cli-commands/SKILL.md). For real terminal recordings, use [verifying-cli-output](.agents/skills/verifying-cli-output/SKILL.md). For web UI layout on a phone and a desktop, use [verifying-web-ui](.agents/skills/verifying-web-ui/SKILL.md). For an Incus topology you acquire by hand, use [using-incus-topologies](.agents/skills/using-incus-topologies/SKILL.md). Project guidance supplies coding and testing conventions.

## Checks and resources

In an Orbit task, run the tests that cover the change in each changed project: stock Pest with a file path or `--filter` in a Composer project, or the test command that [Bun and Rust projects](docs/reference/bun-and-rust-projects.md) names. Do not run `composer check` or `bin/review-check`: the Gateway runs the Project check at handoff. Outside a task, run `composer test` and `composer check` in each changed project. Confirm that the tests covering the feature ran. Check a framework or library API you are not sure of against the installed version: `search_docs` for the Laravel ecosystem, otherwise the package source under `vendor/` or `node_modules/`. CI checks the five Composer projects, the web app, the Pi server, and the Rust agent; root `composer check` also runs checks across projects locally.

For dependency updates and advisories, follow [Dependencies](docs/contributor-guide.md#dependencies). Run `composer audit:dependencies` from the root.

For documentation changes, follow `writing-documentation` and run `composer docs-lint`. Generated API contracts, context, web types, and `docs/docs.json` are ignored build outputs. Never commit them. Follow the family-owned registration paths in [Parallel command families](docs/contributor-guide.md#parallel-command-families); family conversion PRs leave shared loaders and build workflows alone.

Use Incus machines allocated to the task. Preserve other work and follow resource cleanup safeguards. A known correctness failure on main holds unrelated merges until a reviewed fix is verified on main.

## Writing and knowledge

Write for human readers. Use common words, short sentences, and clear actors. Focus instructions on useful actions and required results. Keep detailed tool procedures in references.

Put architectural rationale in `docs/decisions`, stable reference material in `docs/reference`, and reusable lessons in `docs/solutions`. Add a page when it has lasting value. Keep contributor instructions in contributor guidance.

Direct user instructions take precedence over repository rules. Platform and safety requirements still apply.
