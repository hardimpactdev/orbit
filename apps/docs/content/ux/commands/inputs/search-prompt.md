# Search Prompt

Pick one value from a large or dynamic set, with type-to-filter search.

## Use When

Use `search` in the following situations.

- The candidate set is too large to render as a flat `select` (apps,
  workspaces, tools across nodes).
- The candidates are computed at prompt time from a query (`gh` repository
  search, gateway-driven app discovery).

## Avoid When

Choose a different primitive in the following situations.

- The set is small and static. Use [`select`](select-prompt.md).
- Each candidate has multiple display columns the operator needs to compare.
  Use [`data-table-prompt`](../lists/data-table-prompt.md).
- The operator may pick more than one. Use
  [`multi-search`](multi-search-prompt.md).
- The operator should be able to type a free value not in the suggestion
  list. Use [`suggest`](suggest-prompt.md).

## Contract

These rules govern all uses of `search` in Orbit commands.

- Primitive name in input-mode docs: `search`.
- The `Source` row of the prompt mapping names the data source (gateway
  query, local model, external CLI such as `gh`).
- The closure that resolves matches must be cancellation-safe: it runs on
  every keystroke and must not perform writes or destructive lookups.
- In `--json` or non-interactive mode the prompt is not rendered; the
  command must accept the same field as an explicit option.

## Implementation

`Laravel\Prompts\search(...)`. Wrap with
`App\Concerns\HandlesPromptCancellation` if a node command introduces this
primitive.

## Example

```php
use function Laravel\Prompts\search;

$repository = search(
    label: 'GitHub repository',
    options: fn (string $value): array => $value === ''
        ? []
        : GitHubRepositorySearch::matches($value),
    placeholder: 'orbit/example',
    required: true,
);
```

## Reference Implementations

These commands use `search` and are good models to follow.

- Use a real `Laravel\Prompts\search` caller for new work. `cf-cache:flush`
  currently uses plain `ask` for the zone, so it is not a search reference.

## Cross References

See also these related resources.

- [Laravel Prompts: search](https://laravel.com/docs/13.x/prompts#search)
- [Skill: terminal output](../../../../../../.agents/skills/command-designer/references/terminal-output.md)
