# Password Prompt

Single-line input that does not echo. For secrets such as TLS material,
passphrases, and access tokens.

## Use When

Use `password` in the following situations.

- The value is sensitive and must not appear in the terminal scrollback.
- The value is captured once and forwarded to a credential store or
  immediate side effect.

## Avoid When

Choose a different primitive in the following situations.

- The value is non-sensitive free text. Use [`text`](text-prompt.md).
- The secret is being supplied via an option flag or environment variable
  in non-interactive mode. The command must accept that path too; the
  prompt is only the interactive fallback.

## Contract

These rules govern all uses of `password` in Orbit commands.

- Primitive name in input-mode docs: `password`.
- Echo is suppressed; no characters render as the operator types.
- Validation timing matches [`text`](text-prompt.md): on submit and on
  supplied-value read.
- In `--json` or non-interactive mode the prompt is not rendered; missing required input fails with `validation_failed`.
- Secrets passed by flag are read directly.
- Captured values must never be logged or echoed back as part of human
  output, JSON output, or activity logging.

## Implementation

`Laravel\Prompts\password(...)`. Wrap with
`App\Concerns\HandlesPromptCancellation` if a node command introduces this
primitive (the concern does not wrap `password` today).

## Example

```php
use function Laravel\Prompts\password;

$token = password(
    label: 'GitHub access token',
    required: true,
    validate: fn (string $value): ?string => $value !== ''
        ? null
        : 'Token is required.',
);
```

## Reference Implementations

These commands use `password` and are good models to follow.

- `orbit vpn-web-ui:change-password` — prompts for
  `vpn_web_ui_change_password.password` when the new password is omitted.

## Cross References

See also these related resources.

- [Laravel Prompts: password](https://laravel.com/docs/13.x/prompts#password)
- [Skill: terminal output](../../../../../../.agents/skills/command-designer/references/terminal-output.md)
