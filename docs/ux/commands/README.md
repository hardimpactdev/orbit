# Command UX Primitives

This tree is the product authority for which renderer and prompt primitives
Orbit commands may use, and when each is appropriate. Renderer docs and
input-mode docs name a primitive from this tree and link to the matching
page. Implementation mechanics (ANSI, traits, animation patterns) live in
[`.agents/skills/command-designer/references/terminal-output.md`](../../../.agents/skills/command-designer/references/terminal-output.md).

The base prompt and renderer infrastructure is
[Laravel Prompts](https://laravel.com/docs/13.x/prompts). Custom Orbit
primitives are explicitly called out below.

## Cross-Cutting Rules

These rules apply to all renderer and input-mode docs in this tree.

- Lists default to read-only [`table`](lists/table.md). Use
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

## Index

Each family links to a selection guide and individual primitive pages.

| Family | Pages |
| --- | --- |
| [Details](details/show-detail.md) | `show-detail` |
| [Lists](lists/README.md) | `table`, `data-table-prompt` |
| [Inputs](inputs/README.md) | `text`, `password`, `confirm`, `select`, `multi-select`, `search`, `multi-search`, `suggest` |
| [Progress](progress/README.md) | `progress-tree`, `spinner` |
