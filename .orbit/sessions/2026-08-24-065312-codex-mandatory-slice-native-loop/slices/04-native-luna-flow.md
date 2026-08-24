# Orbit Feature Slice

- Slice: 04-native-luna-flow
- Depends on: 03-finalization-archive

## Outcome

The active feature instructions route each ready slice from Sol to one fresh
native low-effort Luna worker, then retain one feature-level proof and one Claude
review through finalization.

## Scope

- Included:
  - Define the active Sol owner, native `gpt-5.6-luna` low-effort slice worker,
    and Claude feature-review contract.
  - Update `AGENTS.md`, `AGENT_FAST_PATH.md`, and `HARNESS.md` for the slice flow.
  - Update the implementing-features skill and its metadata.
  - Align reviewer personas, the workflow graph, and every remaining active agent
    and skill description with the native Luna BUILD contract.
  - Add the remaining architecture and quality tests, including the 35,600-byte
    instruction-total guard.
- Excluded:
  - Changes to the FRAME artifact schema, parser, phase gates, or archive schema.
  - Multi-venue acceptance, external tickets, semantic graders, or a new
    lifecycle command.
  - Removal of dormant Grok tooling or changes to the global coder role.

## Authority

- Decisions: Each dependency-ready slice gets one fresh native Luna worker at
  low reasoning effort; Sol remains the sole writer and Claude reviews the
  completed feature once.
- Product docs: Agent workflow, lifecycle, and architecture documentation define
  active roles, proof ownership, review ownership, and preserved dormant tools.

## Proof

- Focused: Architecture, agent-description, skill-metadata, workflow-graph, and
  quality tests prove the active Sol/Luna/Claude contract and instruction limit.
