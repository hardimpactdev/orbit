# Confirm Prompt

Yes / no decision. Used for destructive consent and for opt-in branches in
input resolution.

## Use When

Use `confirm` in the following situations.

- A destructive command needs explicit consent before side effects, and
  `--force` was not supplied.
- An interactive branch in input resolution can be skipped without prompting
  for further fields when the operator answers no.

## Avoid When

Choose a different primitive in the following situations.

- The choice has more than two options. Use [`select`](select-prompt.md).
- The command is non-interactive. Destructive commands require `--force` in
  non-interactive mode; do not synthesize a confirm.

## Contract

These rules govern all uses of `confirm` in Orbit commands.

- Primitive name in input-mode docs: `confirm`.
- Default is `false` for destructive consent prompts. The operator must
  actively type `y`/`yes` or accept the highlighted "Yes" option.
- `--force` skips the prompt and is required in non-interactive mode for
  destructive commands.
- In `--json` mode the prompt is not rendered. Destructive commands without
  `--force` fail with `validation_failed` and a meta hint about the missing
  consent flag.

## Implementation

`Laravel\Prompts\confirm(...)` invoked through
`App\Concerns\HandlesPromptCancellation::promptConfirm(...)`.

## Example

```php
use function Laravel\Prompts\confirm;

$proceed = confirm(
    label: "Remove node 'app-1' from the gateway registry?",
    default: false,
    yes: 'Remove',
    no: 'Cancel',
);
```

## Reference Implementations

These commands use `confirm` and are good models to follow.

- `orbit node:remove` — destructive consent before removing a node.
- `orbit node:revoke` — destructive consent before revoking trust material.

## Cross References

See also these related resources.

- [Laravel Prompts: confirm](https://laravel.com/docs/13.x/prompts#confirm)
- [Skill: terminal output](../../../../.agents/skills/command-designer/references/terminal-output.md)
- `docs/commands/README.md` "Destructive Confirmation" section
