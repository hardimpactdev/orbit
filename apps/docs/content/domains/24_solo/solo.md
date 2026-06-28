# Solo

## Purpose

This domain owns Orbit's Solo extension command contracts.

## Responsibilities

These responsibilities keep the Solo extension command surface aligned across docs, registry entries, CLI commands, and gateway routes.

- Document local and gateway extension gates for `solo:*` commands.
- Document the gateway proxy boundary for Solo API calls.
- Keep Solo command permissions aligned with the registry and gateway routes.

## Boundaries

Solo commands do not create a new doctor state family. They read and mutate Solo state through the gateway proxy and hand drift checks to node, process, and tool families.