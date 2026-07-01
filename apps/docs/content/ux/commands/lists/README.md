# Lists

Read-only and interactive list primitives. List commands default to a
read-only [`table`](table.md). Use a read-only [`data-list`](data-list.md) when
each item needs several labeled properties and a table would be cramped. When
selecting a row triggers a follow-up command action, use a
[`data-table-prompt`](data-table-prompt.md) instead.

## Selection Rule

Use this table to choose between the read-only table, read-only data list, and
interactive data-table prompt.

| If the command is... | Use |
| --- | --- |
| Read-only display of multiple compact rows (`node:list`, `app:list`) | [`table`](table.md) |
| Read-only display of grouped items with several properties (`tool:list`) | [`data-list`](data-list.md) |
| Selecting one row that drives a follow-up action (`profile` selecting an app) | [`data-table-prompt`](data-table-prompt.md) |

## Pages

Each page documents one list primitive with selection guidance and a reference implementation.

- [`table`](table.md) — `Laravel\Prompts\table`, read-only.
- [`data-list`](data-list.md) — grouped read-only list with labeled property
  lines.
- [`data-table-prompt`](data-table-prompt.md) — `Laravel\Prompts\datatable`,
  interactive row selection with search.
