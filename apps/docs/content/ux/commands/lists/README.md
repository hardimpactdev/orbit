# Lists

Read-only and interactive list primitives. List commands default to a
read-only [`table`](table.md). A **data list** always means
`Laravel\Prompts\datatable`: an interactive columnar list whose selected row
opens or drives a follow-up action. Use [`property-list`](property-list.md) for
read-only grouped `Label: value` properties.

## Selection Rule

Use this table to choose between the read-only table, interactive data list,
and read-only property list.

| If the command is... | Use |
| --- | --- |
| Read-only display of multiple compact rows (`node:list`) | [`table`](table.md) |
| Selecting one columnar row that drives a follow-up (`project:list`) | [`data-list`](data-list.md) |
| Read-only display of grouped items with several properties (`tool:list`) | [`property-list`](property-list.md) |

## Pages

Each page documents one list primitive with selection guidance and a reference implementation.

- [`table`](table.md) — `Laravel\Prompts\table`, read-only.
- [`data-list`](data-list.md) — `Laravel\Prompts\datatable`, interactive row
  selection with columns and search.
- [`property-list`](property-list.md) — grouped read-only labeled properties.
- [`data-table-prompt`](data-table-prompt.md) — compatibility documentation for
  the same Laravel Prompts `datatable` primitive.
