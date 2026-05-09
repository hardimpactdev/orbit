# Data Table Prompt

Interactive row-selection table. The user picks one row and the command
acts on the selection.

## Use When

- The command needs to select a single entity from a list and immediately
  act on it (`orbit profile` selecting an app to profile).
- The list may grow beyond a handful of rows, so a flat `select` would be
  awkward and a search/filter is valuable.

## Avoid When

- The command is read-only and only displays results. Use
  [`table`](table.md).
- The choice is one of a small fixed set of scalar values (e.g. node role).
  Use [`select`](../inputs/select-prompt.md).
- Multiple rows must be picked. There is no Orbit-supported multi-select
  variant of `datatable` today; use [`multi-search`](../inputs/multi-search-prompt.md)
  with the same row data flattened to labels.

## Contract

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
    headers: ['Host', 'Node', 'Repository'],
    rows: [
        'app-1' => ['app-1.test', 'app-1', 'orbit/example-1'],
        'app-2' => ['app-2.example.com', 'app-2', 'orbit/example-2'],
    ],
    label: 'Select an app to profile',
    hint: 'Press / to search',
);
```

## Reference Implementation

- `orbit profile` — selects the target app when no positional argument is
  given. See `app/Console/Commands/ProfileCommand.php` and
  `docs/commands/11_operation/5_profile/technical/5.1_profile_input-mode_interactive.md`.

## Cross References

- [Laravel Prompts: datatable](https://laravel.com/docs/13.x/prompts#datatable)
- [Skill: terminal output](../../../../.agents/skills/command-designer/references/terminal-output.md)
- [`table`](table.md) for the read-only variant
