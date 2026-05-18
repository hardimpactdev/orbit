# Confirm Prompt

Yes / no decision. Used for destructive consent and for opt-in branches in
input resolution.

## Use When

Use `confirm` in the following situations.

- A destructive command needs explicit consent before side effects, and
  neither `--force` nor `--json` was supplied.
- An interactive branch in input resolution can be skipped without prompting
  for further fields when the operator answers no.

## Avoid When

Choose a different primitive in the following situations.

- The choice has more than two options. Use [`select`](select-prompt.md).
- The command is non-interactive. Do not synthesize a confirm; apply the shared
  destructive consent rules from `docs/domains/README.md`.

## Contract

These rules govern all uses of `confirm` in Orbit commands.

- Primitive name in input-mode docs: `confirm`.
- Default is `false` for destructive consent prompts. The operator must
  actively type `y`/`yes` or accept the highlighted "Yes" option.
- Destructive confirmation prompts render after target and subject resolution
  and before any side effect.
- `--force` skips the prompt and is explicit destructive consent in any mode.
- In `--json` mode the prompt is not rendered. `--json` implies destructive
  consent for the JSON one-shot caller, while ordinary validation,
  authorization, and target requirements still apply.
- Destructive commands in input mode that is neither JSON nor interactive, without `--force`, fail before
  side effects with `validation_failed`, `meta.field=force`, and
  `meta.reason=destructive_consent_required`.
- Declining or cancelling a destructive confirmation fails before side effects
  with `validation_failed`, `meta.field=force`, and `meta.reason=cancelled`.

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
- `docs/domains/README.md` "Destructive Confirmation" section
