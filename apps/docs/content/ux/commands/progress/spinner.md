# Spinner

A progress indicator that shows a single animated line with no determinate progress. Use it for sub-second waits or
for the inner step of a tree where a separate tree would be excessive.

## Use When

Use the spinner in the following situations.

- A single short async wait must show motion to the operator (waiting for a
  remote response that usually returns in well under a second).
- The command renders one step that may briefly block, and the surrounding
  context already provides the headline (no need for a full tree).

## Avoid When

Choose the progress tree or render directly in the following situations.

- The command has multiple steps. Use the
  [progress tree](progress-tree.md).
- The command may take seconds and the operator benefits from named steps.
  Use the [progress tree](progress-tree.md).
- The command is a fast read-only path. Render the result directly.

## Contract

These rules govern all uses of the spinner in Orbit commands.

- Primitive name in renderer docs: `spinner`.
- Renderer doc's `## Progress Tree` section explicitly states a spinner is
  used (not a tree) and explains why.
- The spinner uses the canonical Orbit frames from
  `WithSpinner::$spinnerFrames`. Hand-rolling frame strings is banned.
- In `--json` mode no spinner is rendered.

## Implementation

`App\Concerns\WithSpinner` provides the frame set and runner helpers
(`runWithSpinner`, `runAllWithSpinners`). See
[`.agents/skills/command-designer/references/terminal-output.md`](../../../../../../.agents/skills/command-designer/references/terminal-output.md)
for the trait API and parallel-checks pattern.

## Reference Implementations

These commands use the spinner and are good models to follow.

- `LinkCommand` — sequential spinner runner.
- `DoctorCommand` — parallel spinners via `runAllWithSpinners`.

## Cross References

See also these related resources.

- [Skill: terminal output](../../../../../../.agents/skills/command-designer/references/terminal-output.md)
- [`progress-tree`](progress-tree.md) for the multi-step variant.
