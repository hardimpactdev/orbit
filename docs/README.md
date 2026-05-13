# Documentation

Read these files as Orbit's active documentation contract:

1. [Mission](MISSION.md) — why Orbit exists and who it serves.
2. [Architecture](ARCHITECTURE.md) — how Orbit is designed: hub-and-spoke shape, state model, drift handling, and command contract.
3. [Building Blocks](BUILDING-BLOCKS.md) — implementation pieces and technology stack that make the architecture real.
4. [Concepts](CONCEPTS.md) — routing index for Orbit terms and owning docs.
5. [Command Contracts](commands/README.md) — stable command behavior, input modes, output renderers, and JSON contracts.

Command families may define their own concept documents, such as
[Node Concepts](commands/1_node/node-concepts.md), when domain vocabulary needs
a stable owner.

Historical specs, notes, archived plans, audits, runbooks, and working
documents remain in `../orbit-old-may/docs/superpowers/`. Use them for context,
not as authority over the active documentation contract above.
