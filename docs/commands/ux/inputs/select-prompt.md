# Select Prompt

Pick one value from a small fixed set of choices.

## Use When

Use `select` in the following situations.

- The field is a closed enum with a handful of values (`role`,
  `environment`, `php_version`).
- All choices are knowable at prompt time without a query.

## Avoid When

Choose a different primitive in the following situations.

- The set of choices is large or dynamic. Use [`search`](search-prompt.md)
  or [`data-table-prompt`](../lists/data-table-prompt.md).
- The user picks more than one value. Use
  [`multi-select`](multi-select-prompt.md).
- The decision is binary yes / no. Use [`confirm`](confirm-prompt.md).

## Contract

These rules govern all uses of `select` in Orbit commands.

- Primitive name in input-mode docs: `select`.
- The `Choices` row of the prompt mapping lists the exact admitted values.
- `Default` is either omitted (operator must choose) or one of the listed
  choices.
- In `--json` or non-interactive mode the prompt is not rendered; missing
  required input fails with `validation_failed`. Supplied option values
  outside the admitted set fail with the same code.

## Implementation

`Laravel\Prompts\select(...)`. Wrap with
`App\Concerns\HandlesPromptCancellation` if a node command introduces this
primitive (the concern does not wrap `select` today).

## Example

```php
use function Laravel\Prompts\select;

$role = select(
    label: 'Node role',
    options: ['gateway', 'app', 'control'],
    required: true,
);
```

## Reference Implementations

These commands use `select` and are good models to follow.

- `orbit node:new` — `node_new.role`, `node_new.environment` use `select`.

## Cross References

See also these related resources.

- [Laravel Prompts: select](https://laravel.com/docs/13.x/prompts#select)
- [Skill: terminal output](../../../../.agents/skills/command-designer/references/terminal-output.md)
