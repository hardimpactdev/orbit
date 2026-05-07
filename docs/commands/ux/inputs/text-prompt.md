# Text Prompt

Free-text input for names, IPs, paths, hostnames, and other single-line
string fields.

## Use When

- The field accepts arbitrary string input that is not a fixed enum
  (`name`, `host`, `path`, `repository`, IP addresses).
- A regex or domain rule validates the value, with re-prompt on failure.

## Avoid When

- The field is one of a small fixed enum. Use [`select`](select-prompt.md).
- The value is a secret that should not echo. Use
  [`password`](password-prompt.md).
- A binary yes/no decision. Use [`confirm`](confirm-prompt.md).
- A list of recent or suggested values can speed up input. Use
  [`suggest`](suggest-prompt.md).

## Contract

- Primitive name in input-mode docs: `text`.
- The `Primitive` column of the `## Prompt Mapping` table names `text` and
  links to this page.
- `Validation timing`: validate when a supplied value is read or when the
  prompt is submitted. Re-prompt on invalid input until the value is valid
  or the user aborts.
- In `--json` or non-interactive mode the prompt is not rendered; missing
  required input fails with `validation_failed`.

## Implementation

`Laravel\Prompts\text(...)` invoked through
`App\Concerns\HandlesPromptCancellation::promptText(...)` so prompt aborts
exit cleanly.

## Example

```php
use function Laravel\Prompts\text;

$name = text(
    label: 'Node name',
    required: true,
    validate: fn (string $value): ?string => preg_match('/^[a-z0-9-]+$/', $value)
        ? null
        : 'Node name must be lowercase alphanumeric with hyphens.',
);
```

## Reference Implementations

- `orbit node:new` — `node_new.name`, `node_new.host`,
  `node_new.control_name`, `node_new.tld`, `node_new.ssh_user` all use
  `text`.

## Cross References

- [Laravel Prompts: text](https://laravel.com/docs/13.x/prompts#text)
- [Skill: terminal output](../../../../.agents/skills/command-designer/references/terminal-output.md)
