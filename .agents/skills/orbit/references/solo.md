# Solo Extension Commands

Solo is an optional built-in Orbit extension. Use `solo:*` commands when you
need to inspect or operate Solo projects, agent tools, processes, scratchpads,
todos, timers, locks, and related coordination state through Orbit.

## Discovery

- Current node-visible commands: `orbit list --format=json`.
- Full repository command catalog, including extension-gated commands:
  `apps/docs/content/generated/command-catalog.json`.
- Exact command help: `orbit <command> --help`.

Do not copy the whole Solo command set into prompts or plans. The Solo surface
is generated and extension-gated; use the catalog or `--help` for exact current
arguments and options.

## Enablement

Solo has two extension gates:

- Local CLI node: `orbit extension:enable solo`.
- Gateway routes: `orbit extension:enable solo --gateway` or
  `orbit extension:enable solo --node=gateway`.

Disabled local state hides `solo:*` from normal command discovery and direct
invocation returns `extension_disabled` with `meta.scope=local`. Disabled
gateway state blocks `/api/solo/**` after identity and grant checks.

## Boundary

Orbit proxies Solo through gateway API routes. Solo upstream traffic targets the
gateway node's configured loopback Solo API URL; Orbit does not expose Solo
localhost ports directly to WireGuard.

Solo does not own a doctor state family. Drift belongs to existing families:
`node` for identity/config reachability, `process` for process state, and
`tool` for installed tool state.

## Common Areas

| Area | Commands |
|---|---|
| Tools | `solo:tools`, `solo:agent-tool:list` |
| Projects | `solo:project:*` |
| Processes and agents | `solo:process:*`, including `solo:process:spawn` |
| Scratchpads | `solo:scratchpad:*` |
| Todos | `solo:todo:*` |
| Coordination | `solo:timer:*`, `solo:lock:*`, `solo:service:list` |

All Solo commands that return data support `--json` through Orbit's standard
JSON envelope. Mutating commands may require operation-specific Solo
permissions such as `solo:scratchpad:write`, `solo:todo:delete`, or
`solo:timer:*`.
