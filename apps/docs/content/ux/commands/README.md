# Command UX Primitives

This tree is the product authority for which renderer and prompt primitives
Orbit commands may use, and when each is appropriate. Renderer docs and
input-mode docs name a primitive from this tree and link to the matching
page. Implementation mechanics (ANSI, traits, animation patterns) live in
[`.agents/skills/command-designer/references/terminal-output.md`](../../../../../.agents/skills/command-designer/references/terminal-output.md).

The base prompt and renderer infrastructure is
[Laravel Prompts](https://laravel.com/docs/13.x/prompts). Custom Orbit
primitives are explicitly called out below.

## Cross-Cutting Rules

These rules apply to all renderer and input-mode docs in this tree.

- Lists default to read-only [`table`](lists/table.md). Use
  [`data-list`](lists/data-list.md) when each item needs a compact grouped
  property view instead of a columnar row, and use
  [`datatable`](lists/data-table-prompt.md) whenever the operator must choose an
  existing Orbit entity from finite registry state before a command action, such
  as an app, node, workspace, process, schedule, or tool target.
- Show commands use [`show-detail`](details/show-detail.md): a single
  tree-shaped detail view with a title and aligned property rows.
- Open `text` and `number` prompts are only for values that are not knowable as
  a finite option set at prompt time, such as new names, paths, hosts, domains,
  commands, or counts.
- Closed scalar enums use [`select`](inputs/select-prompt.md), not `datatable`.
- Inputs use Laravel Prompts primitives. Symfony Console
  `$this->ask`, `$this->confirm`, `$this->choice`, `$this->secret`, and
  `$this->table` are banned in renderer and input-mode docs.
- Long-running commands render the custom Orbit
  [progress tree](progress/progress-tree.md). Single sub-second waits use a
  [spinner](progress/spinner.md).
- `--json` always selects the JSON renderer and forces non-interactive input
  mode. No primitive prompts in JSON mode; missing required input fails with
  `validation_failed`.
- Progress state is monotonic. An area may move from `Queued` to `Running` to a
  terminal result, but it never returns from `Running` to `Queued` while later
  work is pending. Long operations keep communicating liveness instead of
  appearing frozen.

## Feedback-Derived Protections

Reusable command feedback becomes product authority here and must gain the
strongest practical deterministic protection. Every promoted expectation keeps
one rejected and one accepted example; semantic similarity alone never gates a
command.

The monotonic progress rule is the reference pair:

- rejected: a captured area frame sequence contains `Running -> Queued`;
- accepted: the same area remains `Running` until it reaches its terminal
  result;
- protection: `bin/quality-check-progress-frame-check`, exercised by the
  verification-script test fixtures.

Only run a feedback protection for the command surface it covers. Do not turn
these outcome rules into implementation-style policing.

## Index

Each family links to a selection guide and individual primitive pages.

| Family | Pages |
| --- | --- |
| [Details](details/show-detail.md) | `show-detail` |
| [Lists](lists/README.md) | `table`, `data-table-prompt` |
| [Inputs](inputs/README.md) | `text`, `password`, `confirm`, `select`, `multi-select`, `search`, `multi-search`, `suggest` |
| [Progress](progress/README.md) | `progress-tree`, `spinner` |
