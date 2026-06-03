# Multi-Select Prompt

Pick zero or more values from a small fixed set of choices.

## Use When

Use `multiselect` in the following situations.

- The field is a closed enum and the operator may select several values
  (granting multiple capabilities at once, attaching multiple scopes).
- All choices are knowable at prompt time without a query.

## Avoid When

Choose a different primitive in the following situations.

- The set of choices is large or dynamic. Use
  [`multi-search`](multi-search-prompt.md).
- Exactly one value is required. Use [`select`](select-prompt.md).

## Contract

These rules govern all uses of `multiselect` in Orbit commands.

- Primitive name in input-mode docs: `multiselect`.
- The `Choices` row lists the exact admitted values.
- `Default` is omitted or a list of pre-selected values from the choices.
- In `--json` or non-interactive mode the prompt is not rendered.
- The command must accept the same field as a comma-separated or repeated option and validate it against the admitted set.

## Implementation

`Laravel\Prompts\multiselect(...)`. Wrap with
`App\Concerns\HandlesPromptCancellation` if a node command introduces this
primitive.

## Example

```php
use function Laravel\Prompts\multiselect;

$capabilities = multiselect(
    label: 'Capabilities to grant',
    options: ['read', 'deploy', 'restart', 'logs'],
    required: true,
);
```

## Reference Implementations

No node commands use `multiselect` at plan time.

- None in the node domain at plan time. Add a row here when a node command
  adopts `multiselect`.

## Cross References

See also these related resources.

- [Laravel Prompts: multiselect](https://laravel.com/docs/13.x/prompts#multi-select)
- [Skill: terminal output](../../../../../../.agents/skills/command-designer/references/terminal-output.md)
