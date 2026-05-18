# Documentation

Read these files as Orbit's active documentation contract:

1. [Mission](mission.md) — why Orbit exists and who it serves.
2. [Architecture](architecture.md) — how Orbit is designed: hub-and-spoke shape, state model, drift handling, and command contract.
3. [Tech Stack](tech-stack.md) — implementation pieces and technology stack that make the architecture real.
4. [Concepts](concepts.md) — routing index for Orbit terms and owning docs.
5. [Command Contracts](domains/README.md) — stable command behavior, input modes, output renderers, and JSON contracts.

Command families may define their own concept documents, such as
[Node Concepts](domains/1_node/node-concepts.md), when domain vocabulary needs
a stable owner.

Historical specs, notes, archived plans, audits, runbooks, and working
documents remain in `../orbit-old-may/docs/superpowers/`. Use them for context,
not as authority over the active documentation contract above.
