# Suggest Prompt

Free-text input with non-binding suggestions. The operator may accept a
suggestion or type any value.

## Use When

Use `suggest` in the following situations.

- The field accepts arbitrary text but the operator usually picks from a
  short list of recent or recommended values (recently used hostnames,
  default repository shorthands).
- A `select` would feel restrictive because new values are routinely
  entered.

## Avoid When

Choose a different primitive in the following situations.

- The choices are a closed enum. Use [`select`](select-prompt.md).
- The candidate set is large and the operator should pick from it
  exclusively. Use [`search`](search-prompt.md).
- No suggestions exist. Use [`text`](text-prompt.md).

## Contract

These rules govern all uses of `suggest` in Orbit commands.

- Primitive name in input-mode docs: `suggest`.
- The `Choices` row of the prompt mapping lists the suggested values; the
  operator may submit any string.
- Validation timing matches [`text`](text-prompt.md). Validation must not
  reject a typed value purely because it is not in the suggestion list.
- In `--json` or non-interactive mode the prompt is not rendered.
- The command must accept the same field as an explicit option.

## Implementation

`Laravel\Prompts\suggest(...)`. Wrap with
`App\Concerns\HandlesPromptCancellation` if a node command introduces this
primitive.

## Example

```php
use function Laravel\Prompts\suggest;

$tld = suggest(
    label: 'Development TLD',
    options: ['test', 'orbit', 'dev'],
    default: 'test',
    required: true,
);
```

## Reference Implementations

No node commands use `suggest` at plan time.

- None in the node domain at plan time. Add a row here when a node command
  adopts `suggest`.

## Cross References

See also these related resources.

- [Laravel Prompts: suggest](https://laravel.com/docs/13.x/prompts#suggest)
- [Skill: terminal output](../../../../.agents/skills/command-designer/references/terminal-output.md)
