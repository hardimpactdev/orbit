---
name: e2e-verification-lanes
description: Use when selecting, running, debugging, or delegating Orbit E2E verification lanes, including Docker or Incus feature tests, provider-specific provisioning, prepared topology refreshes, canary runs, and Solo agent E2E splits.
---

# E2E Verification Lanes

## Core Rule

Agents must never run:

```bash
composer test:e2e:provision
```

That command is a human-only aggregate convenience alias. It runs both provider
provision commands and is too broad for delegated agent work.

## Agent Commands

Use provider-specific commands.

```bash
# Docker artifact provision/preparation
composer test:e2e:provision:docker

# Docker feature E2E
composer test:e2e:docker

# Incus fresh provision gate
composer test:e2e:provision:incus

# Incus shared prepared topology refresh
composer e2e:prepare-topology -- --force operator_gateway_app-dev_app-prod_agent_websocket

# Incus feature E2E
composer test:e2e:incus
```

`composer test:e2e` is the prepared-feature aggregate for Docker plus Incus. Use
provider-specific feature lanes when assigning work to separate agents.

## Verification Order

Run prepared-topology feature lanes before provider provision gates. Feature
lanes exercise the current source checkout through prepared Docker/Incus
topologies and are the normal E2E development signal.

Provider provision gates are final verification for artifact preparation,
installer, `node:new`, base image, host mutation, Docker image, or runtime asset
changes. They should run after the relevant feature lane is green, not as a
precondition for feature E2E.

When production artifact behavior matters, the strongest final sequence is:

1. Run the source/prepared-topology feature lane.
2. Run the provider-specific provision gate.
3. Run the feature lane that consumes the built assets, when that artifact lane
   exists for the provider.

## Solo Worker Delegation

When a task needs both Docker and Incus E2E verification, split the work into
provider-scoped Solo agents. Use Solo's agent spawning flow, not an ad hoc
background shell and not a generic non-Solo subagent:

- Docker feature agent: `composer test:e2e:docker`.
- Incus feature agent: refresh the prepared source topology when needed with
  `composer e2e:prepare-topology -- --force operator_gateway_app-dev_app-prod_agent_websocket`,
  then run `composer test:e2e:incus`.
- Docker provision agent, only for final artifact/substrate verification:
  `composer test:e2e:provision:docker`.
- Incus provision agent, only for final fresh-provision verification:
  `composer test:e2e:provision:incus`.

If a Solo process is already actively handling one provider lane, reuse that
process for that lane instead of spawning a duplicate worker that competes for
the same provider capacity.

Do not assign `composer test:e2e` or `composer test:e2e:provision` to an agent
when the intent is provider-parallel verification. Keep each agent scoped to one
provider lane and report that provider's command results separately.

## Provider Split

Docker lane:

1. Run `composer test:e2e:docker` for Docker-eligible feature behavior.
2. Run `composer test:e2e:provision:docker` as a final gate when Docker
   runtime/support images, Docker prepared role images, or Docker host artifact
   distribution changed.

Incus lane:

1. Run `composer e2e:prepare-topology -- --force operator_gateway_app-dev_app-prod_agent_websocket`
   when Incus feature tests need refreshed source-prepared topology artifacts.
2. Run `composer test:e2e:incus` for VM-feature behavior against the prepared
   source topology.
3. Run `composer test:e2e:provision:incus` as a final gate when base image
   shape, installer, gateway provisioning, `node:new`, WireGuard, VM boot,
   systemd, package installation, or host mutation changed.

Docker and Incus provision lanes can run in parallel because they mutate
separate provider substrates.

## Lane Selection

- Use narrow Pest or in-memory tests for command contracts, validation, JSON
  envelopes, renderers, and deterministic service logic.
- Use `composer test:e2e:docker` for gateway API, CA trust, registry-backed
  behavior, command forwarding, Docker runtime backend behavior, scheduler, and
  process lifecycle.
- Use `composer test:e2e:incus` for VM-only behavior: real WireGuard, SSH,
  sudo, systemd, cloud-init, OS trust-store mutation, package installation, and
  host init.
- Use provider provision commands only when the provider substrate itself may
  have changed.

Missing prepared artifacts are not fixed by switching providers. Follow the
scoped artifact command printed by the failing lane, or run the matching
provider provision command when a full refresh is intended.
