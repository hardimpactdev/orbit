# Data List

Read-only grouped list for commands whose items need several labeled
properties.

## Use When

Use `data-list` in the following situations.

- Rendering a small to medium list where each item has multiple properties that
  are easier to scan vertically than in table columns.
- Grouping items by an owning entity, such as node-scoped tool state.
- Empty or unknown values need clear labels rather than empty table cells.

## Avoid When

Choose a different primitive in the following situations.

- The data is naturally columnar and each row has only a few short fields. Use
  [`table`](table.md).
- The user must pick a row to act on. Use
  [`data-table-prompt`](data-table-prompt.md).
- A single entity is being shown. Use
  [`show-detail`](../details/show-detail.md) instead.
- Rendering progress for a long-running command. Use the
  [progress tree](../progress/progress-tree.md).

## Contract

These rules govern all uses of `data-list` in Orbit commands.

- Primitive name in renderer docs: `data-list`.
- The `## Primitive` section of the renderer doc links to this page.
- In `--json` mode the data list is not rendered; the JSON envelope payload is
  emitted instead.
- Each group starts with a short heading such as `Node: app-1`.
- Each item starts with its primary label, followed by indented `Label: value`
  property lines.
- Empty values render as `—` for human display; JSON keeps `null`.

## Implementation

Render with command output lines from the human renderer path. No Orbit wrapper
trait is required for the basic case.

## Example

```php
$this->line('Node: app-1');
$this->line('  composer');
$this->line('    Expected: installed');
$this->line('    Managed: yes');
$this->line('    Version: —');
```

## Reference Implementation

This command is the canonical model for the data-list primitive.

- `orbit tool:list` — primary reference for grouped tool state.

## Cross References

See also these related resources.

- [`table`](table.md) for compact columnar read-only lists
- [`data-table-prompt`](data-table-prompt.md) for the interactive variant
