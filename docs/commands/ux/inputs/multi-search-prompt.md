# Multi-Search Prompt

Pick zero or more values from a large or dynamic set, with type-to-filter
search.

## Use When

Use `multisearch` in the following situations.

- The operator selects several entities from a large or dynamic set
  (multiple apps, multiple workspaces, multiple tools).
- The candidates are computed at prompt time from a query.

## Avoid When

Choose a different primitive in the following situations.

- Exactly one value is required. Use [`search`](search-prompt.md).
- The set is small and static. Use
  [`multi-select`](multi-select-prompt.md).
- Selection should drive a command action for each selected row. Use
  [`data-table-prompt`](../lists/data-table-prompt.md) and act on each
  selection separately.

## Contract

These rules govern all uses of `multisearch` in Orbit commands.

- Primitive name in input-mode docs: `multisearch`.
- The closure that resolves matches must be cancellation-safe and must not
  perform writes.
- In `--json` or non-interactive mode the prompt is not rendered.
- The command must accept the same field as a repeated or comma-separated option.

## Implementation

`Laravel\Prompts\multisearch(...)`. Wrap with
`App\Concerns\HandlesPromptCancellation` if a node command introduces this
primitive.

## Example

```php
use function Laravel\Prompts\multisearch;

$apps = multisearch(
    label: 'Apps to redeploy',
    options: fn (string $value): array => App::query()
        ->when($value !== '', fn ($query) => $query->where('name', 'like', "%{$value}%"))
        ->pluck('name', 'id')
        ->all(),
    required: true,
);
```

## Reference Implementations

No node commands use `multisearch` at plan time.

- None in the node domain at plan time. Add a row here when a node command
  adopts `multisearch`.

## Cross References

See also these related resources.

- [Laravel Prompts: multisearch](https://laravel.com/docs/13.x/prompts#multi-search)
- [Skill: terminal output](../../../../.agents/skills/command-designer/references/terminal-output.md)
