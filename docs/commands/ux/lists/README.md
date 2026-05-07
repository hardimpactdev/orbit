# Lists

Read-only and interactive list primitives. List commands default to a
read-only [`table`](table.md). When selecting a row triggers a follow-up
command action, use a [`data-table-prompt`](data-table-prompt.md) instead.

## Selection Rule

| If the command is... | Use |
| --- | --- |
| Read-only display of multiple rows (`node:list`, `app:list`, `tool:list`) | [`table`](table.md) |
| Selecting one row that drives a follow-up action (`profile` selecting an app) | [`data-table-prompt`](data-table-prompt.md) |

## Pages

- [`table`](table.md) — `Laravel\Prompts\table`, read-only.
- [`data-table-prompt`](data-table-prompt.md) — `Laravel\Prompts\datatable`,
  interactive row selection with search.
