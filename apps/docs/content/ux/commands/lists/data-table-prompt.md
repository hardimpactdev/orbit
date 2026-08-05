# Data Table Prompt

> Terminology: Orbit calls this component a
> [`data-list`](data-list.md). Both names refer to
> `Laravel\Prompts\datatable`; they are not separate primitives.

Interactive row-selection table. The user picks one row and the command
acts on the selection.

## Use When

Use `datatable` in the following situations.

- The command needs to select a single entity from a list and immediately
  act on it (`orbit s3:publish` selecting an s3 node when more than one is
  visible).
- The command asks for an existing Orbit registry entity such as a project,
  node, workspace, process, schedule, or tool, and the candidates are finite
  registry rows available at prompt time.
- The list may grow beyond a handful of rows, so a flat `select` would be
  awkward and a search/filter is valuable.

## Avoid When

Choose a different primitive in the following situations.

- The command is read-only and only displays results. Use
  [`table`](table.md).
- The choice is one of a small fixed set of scalar values (e.g. node role).
  Use [`select`](../inputs/select-prompt.md).
- The command creates a new identity value rather than selecting an existing
  row. Use [`text`](../inputs/text-prompt.md).
- Multiple rows must be picked. There is no Orbit-supported multi-select
  variant of `datatable` today; use [`multi-search`](../inputs/multi-search-prompt.md)
  with the same row data flattened to labels.

## Contract

These rules govern all uses of `datatable` in Orbit commands.

- Primitive name in input-mode docs: `datatable`.
- Row keys are the values returned to the caller; row columns are display
  only.
- Built-in search via `/`. Hint text should mention it.
- In `--json` or non-interactive mode the prompt is not rendered; missing
  required selection fails with `validation_failed`.

## Implementation

`Laravel\Prompts\datatable($headers, $rows, ...)` is invoked from the
interactive input-mode path. The selected row key is returned; the command
resolves it back to a domain entity.

## Example

```php
use function Laravel\Prompts\datatable;

$selected = datatable(
    headers: ['Name', 'Roles', 'Status'],
    rows: [
        's3-1' => ['s3-1', 's3', 'active'],
        's3-2' => ['s3-2', 's3', 'active'],
    ],
    label: 'Select an s3 node',
    hint: 'Press / to search',
);
```

## Reference Implementation

These commands use `datatable` and are good models to follow.

- `orbit project:list` — project selection datatable (`Select a project`).
- Other registry list commands that call `Laravel\Prompts\datatable` through
  shared helpers. Do not cite `s3:credentials` (no prompt), or unbacked
  `project:remove` / `process:update` rows as datatable references.

## Cross References

See also these related resources.

- [Laravel Prompts: datatable](https://laravel.com/docs/13.x/prompts#datatable)
- [Skill: terminal output](../../../../../../.agents/skills/command-designer/references/terminal-output.md)
- [`table`](table.md) for the read-only variant
