# `orbit skill:install`

Install Orbit's bundled agent skill into a supported LLM tool's user skill
directory, or into an explicit target path.

```bash
orbit skill:install [provider] [path] [--force] [--json]
```

## Arguments and options

- `[provider]`: Optional provider slug. Supported slugs are `codex`, `claude`,
  `antigravity`, and `grok`. If this value is not a supported provider, Orbit
  treats it as an explicit target path.
- `[path]`: Optional explicit target path. Use this only when `[provider]` is a
  supported provider slug.
- `--force`: Replace an existing target directory, file, or symlink without
  prompting.
- `--json`: Emit the canonical JSON envelope.

## Behavior

The command copies the repository-owned `.agents/skills/orbit/` directory from
the Orbit install root. With a provider and no path, it installs to that
provider's default user skill path:

| Provider | Default target |
| --- | --- |
| `codex` | `~/.agents/skills/orbit` |
| `claude` | `~/.claude/skills/orbit` |
| `antigravity` | `~/.gemini/config/skills/orbit` |
| `grok` | `~/.grok/skills/orbit` |

Provider default installs require `HOME` to resolve the `~` target. If `HOME`
is unavailable, the command fails with `validation_failed` instead of guessing a
location.

When the first positional value is not one of those slugs, Orbit treats it as
the target path and copies the raw Orbit skill directory there.

Installing into an absent target is a normal write and needs no destructive
consent. Replacing an existing target is destructive: interactive mode asks for
confirmation unless `--force` is present, while non-interactive mode requires
`--force`. The command resolves and validates the source and target before it
removes the existing target and copies the current Orbit skill in its place.

`skill:install` is local-only. It does not call the gateway, enable extension
state, mutate fleet state, or download third-party skills.

## Examples

```bash
orbit skill:install codex
orbit skill:install claude --force
orbit skill:install antigravity --json
orbit skill:install /tmp/orbit-skill
orbit skill:install grok /tmp/grok-orbit-skill --force
```

## Output

Human output prints the installed action, provider, target, and source. JSON
output follows the JSON renderer contract.

See [`skill:install` technical contract](technical/1_skill-install.md).
