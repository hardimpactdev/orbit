# Concepts

This file is a routing index for Orbit concepts. It helps humans and LLM agents
find the owning document for a term. Keep entries short; definitions and
behavior contracts live in the linked source documents.

Marked `concept-index` blocks are checked by `composer docs-lint` against the
owning family concept document.

## Global Concepts

- **Gateway intent** — desired durable state stored on the gateway. See
  [Blueprint: State Model](BLUEPRINT.md#state-model).
- **Node reality** — observed runtime state on a node. See
  [Blueprint: State Model](BLUEPRINT.md#state-model).
- **State family** — product area with gateway intent, node reality probes, and
  doctor behavior. See [Blueprint: State Families](BLUEPRINT.md#state-families).
- **Drift** — a difference between gateway intent and node reality. See
  [Blueprint: Drift And Doctor](BLUEPRINT.md#drift-and-doctor).
- **Fix** — doctor mode that reapplies gateway intent to node reality. See
  [Blueprint: Drift And Doctor](BLUEPRINT.md#drift-and-doctor).
- **Adopt** — doctor mode that records compatible observed node reality into
  gateway intent. See [Blueprint: Drift And Doctor](BLUEPRINT.md#drift-and-doctor).
- **RemoteShell** — gateway-to-app-node execution primitive. See
  [Building Blocks: Transport](BUILDING-BLOCKS.md#transport).
- **CLI caller** — an Orbit CLI invocation from a control node, app node, or the
  gateway host. See [Building Blocks: Transport](BUILDING-BLOCKS.md#transport).
- **Gateway API** — typed HTTPS API served on the gateway WireGuard address. See
  [Building Blocks: Gateway API Exposure](BUILDING-BLOCKS.md#gateway-api-exposure).
- **Command contract** — user-visible command behavior, input, output, and
  failure contract. See [Command Contracts](commands/README.md).

## Product Families

Permanent state-family keys are singular product names:

- `node`
- `app`
- `workspace`
- `process`
- `proxy_route`
- `schedule`
- `tool`
- `firewall_rule`

See [Blueprint: State Families](BLUEPRINT.md#state-families).

## Node Concepts

Source: [Node Concepts](commands/1_node/node-concepts.md).

<!-- concept-index:commands/1_node/node-concepts.md -->
- **Node**
- **Gateway**
- **Control node**
- **App node**
- **Local caller role**
- **Node identity**
- **First-gateway bootstrap**
- **Control-node enrollment**
- **Compatible existing node**
- **CLI-to-gateway edge**
- **Gateway-to-app-node edge**
- **App-node event ingestion**
- **Node reality**
- **Consuming node**
- **Serving node**
<!-- /concept-index -->

## Future Family Concepts

Add family concept links here only after a family has an owning concept
document. Until then, use the family `README.md`, family doctor document, and
individual command contracts as the source of truth.
