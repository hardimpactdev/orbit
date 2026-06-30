# Skill Commands

Use `skill:install` to copy Orbit's bundled `.agents/skills/orbit/` directory
into a local LLM tool's user skill directory.

```bash
orbit skill:install [provider] [path] [--force] [--json]
```

Provider defaults:

| Provider | Default target |
|---|---|
| `codex` | `~/.agents/skills/orbit` |
| `claude` | `~/.claude/skills/orbit` |
| `antigravity` | `~/.gemini/config/skills/orbit` |
| `grok` | `~/.grok/skills/orbit` |

Rules:

- Pass a provider with no path to install to that provider's default.
- Pass a raw path as the first positional to install the Orbit skill there.
- Pass both provider and path to install for that provider at an explicit path.
- Pass `--force` to overwrite an existing target. Without `--force`, Orbit
  fails before changing the target.
- The command is local-only. It does not call the gateway, mutate fleet state,
  enable extensions, or download skills.

Examples:

```bash
orbit skill:install codex
orbit skill:install claude --force
orbit skill:install /tmp/orbit-skill --json
orbit skill:install grok ~/.grok/skills/orbit --force
```

