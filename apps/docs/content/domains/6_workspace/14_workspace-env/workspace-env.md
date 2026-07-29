# `orbit workspace:env list|set|render [name]`

[Back to Workspace commands.](../README.md)

List, set, or render non-secret env values for one concrete workspace.

## Usage

Choose one action and identify the workspace by name or run the command from
inside its registered path.

```bash
orbit workspace:env list [name] [--instance=<project.instance>] [--json]
orbit workspace:env set [name] [--instance=<project.instance>] --key=<KEY> --value=<value> [--apply] [--json]
orbit workspace:env render [name] [--instance=<project.instance>] [--json]
```

When `[name]` is omitted, Orbit resolves a registered workspace from the
caller's absolute current directory. `--instance` disambiguates workspace names that
exist under more than one project or instance.

## What Happens

Use workspace env as a workspace-owned overlay. `render` merges Orbit-derived
workspace URL and Vite fields, explicit non-secret workspace values, and
database connections attached to that workspace. Explicit values may contain
production-like URLs or environment labels; Orbit does not reject them based
on their content.

`set` stores gateway intent only. `set --apply` writes the effective map only to
the selected workspace's `.env`, clears Laravel config and generated bootstrap
cache files at the workspace path as its runtime user, and reapplies that
workspace runtime when it uses PHP. It never writes the parent instance or a
sibling workspace.

Every response identifies `scope=workspace`, `project`, `instance`, `workspace`,
the concrete `.env` `path`, and `stored`, `applied`, and
`runtime_restarted` outcomes.

## Requirements

- The CLI caller can reach the Orbit gateway.
- The caller has `workspace:read` for `list` and `render`.
- The caller has `workspace:write` for `set`.

## Technical Contract

See [`workspace:env` technical contract](technical/1_workspace-env.md).
