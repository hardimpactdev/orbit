# Table

Read-only tabular display for list commands.

## Use When

Use `table` in the following situations.

- Rendering multiple rows of structured data (`node:list`, `proxy:list`,
  `firewall:list`, `process:list`,
  `schedule:list`, `workspace:list`, `activity:list`).
- The command is a list/read command and does not select a row.

## Avoid When

Choose a different primitive in the following situations.

- The user must pick a row to act on. Use
  [`data-table-prompt`](data-table-prompt.md).
- A single entity is being shown. Use
  [`show-detail`](../details/show-detail.md) instead.
- Rendering progress for a long-running command. Use the
  [progress tree](../progress/progress-tree.md).

## Contract

These rules govern all uses of `table` in Orbit commands.

- Primitive name in renderer docs: `table`.
- The `## Primitive` section of the renderer doc links to this page.
- In `--json` mode the table is not rendered; the JSON envelope payload is
  emitted instead.
- Headers are uppercase short labels (`ROLE`, `NAME`). Empty cells render as
  `—` (em dash) for human display; JSON keeps `null`.
- Symfony's `$this->table(...)` is banned. It produces `+---+---+` borders
  and ignores the Prompts theme.

## Implementation

`Laravel\Prompts\table($headers, $rows)` is invoked directly from the human
renderer path of the command. No Orbit wrapper trait is required for the
basic case.

## Example

```php
use function Laravel\Prompts\table;

table(
    headers: ['ROLES', 'NAME', 'PLATFORM', 'STATUS'],
    rows: [
        ['app-dev', 'app-1', 'ubuntu_24-04', 'active'],
        ['app-prod, database', 'app-2', 'ubuntu_24-04', 'active'],
        ['gateway', 'gateway-1', 'ubuntu_24-04', 'active'],
        ['—', 'laptop', 'macos_15-4', 'active'],
    ],
);
```

## Reference Implementation

This command is the canonical model for the table primitive.

- `orbit node:list` — primary reference for the table primitive.

## Cross References

See also these related resources.

- [Laravel Prompts: tables](https://laravel.com/docs/13.x/prompts#tables)
- [Skill: terminal output](../../../../../../.agents/skills/command-designer/references/terminal-output.md)
- [`data-table-prompt`](data-table-prompt.md) for the interactive variant
