# Solo

## Purpose

This domain owns Orbit's Solo extension command contracts.

## Responsibilities

These responsibilities keep the Solo extension command surface aligned across docs, registry entries, CLI commands, and gateway routes.

- Document local and gateway extension gates for `solo:*` commands.
- Document the gateway proxy boundary for Solo API calls, including
  `--node=<node>` target selection.
- Keep Solo command permissions aligned with the registry and gateway routes.

## Boundaries

Solo commands do not create a new doctor state family. They read and mutate
Solo state through the gateway proxy and hand drift checks to node, process, and
tool families.

Solo may be installed on specific Orbit nodes. Commands target the gateway node
by default and accept `--node=<node>` when the Solo API lives on another active
node. The gateway resolves and authorizes the target node, then reaches that
node's configured node-local Solo API through Orbit execution; Solo loopback
ports are not exposed directly to WireGuard.
