# DNS Concepts

This document defines DNS-command-domain vocabulary and invariants. It supports
the DNS command contracts; it does not override the
[Blueprint](../../BLUEPRINT.md).

## Domain And Scope

- **DNS command domain:** Local utility command domain for the `dns:*` command
  prefix. It manages caller-local development DNS resolver overrides, but does
  not create a `dns` state family.
- **Caller-local DNS administration:** Local-only DNS workflow allowed only for
  control-node callers. It mutates or reads the caller machine's resolver
  configuration and never writes gateway intent or node reality.
- **Caller-local resolver override:** Orbit-managed local resolver
  configuration that maps wildcard hostnames under a development TLD to a local
  target IP address for browser and CLI use on the caller machine.
- **Orbit-managed local resolver state:** Resolver files, labels, or config
  blocks written by Orbit on the caller machine. DNS commands may read or
  remove only this managed state, not arbitrary operator resolver entries.
- **Local resolver backend:** Platform resolver implementation Orbit knows how
  to inspect, write, refresh, or restart on a supported control-node platform.
- **Supported local DNS platform:** Control-node platform with an Orbit-supported
  local resolver backend. Unsupported platforms fail before local resolver
  reads or writes.

## Resolver Entries

- **Development TLD:** Single lowercase DNS label without a leading dot, such as
  `test`. In the DNS command domain it names the local wildcard override; it is
  not proof of gateway-owned app-node development TLD readiness.
- **Local DNS target:** IPv4 or IPv6 address used by a caller-local resolver
  override. `dns:resolve-tld` maps `*.{tld}` to this target through the local
  resolver backend.
- **Resolve path:** `dns:resolve-tld` path that writes or converges the
  caller-local resolver override for a development TLD and target IP address.
- **Reset path:** Destructive `dns:resolve-tld --reset` path that removes only
  the Orbit-managed local resolver override for a development TLD after
  destructive consent.
- **Resolver refresh:** Platform-specific local resolver refresh or restart
  performed only when required for a local resolver change to take effect.
- **Local DNS entry:** Renderer DTO for an Orbit-managed local resolver
  override. It includes the TLD, target, source, resolver backend, and status
  when those facts are available.
- **Local resolver source:** Stable renderer source value `local_resolver`,
  meaning the DNS entry came from caller-local Orbit-managed resolver state.
- **Local DNS entry status:** Local resolver entry state reported by DNS
  renderers. `dns:list` reports `active` or `stale`; `dns:resolve-tld` reports
  resolve/reset statuses such as `resolved`, `already_resolved`, `reset`,
  `already_absent`, or `refresh_failed`.

## Gateway And Provider Boundaries

- **Gateway-owned development DNS mapping:** Node-family development DNS state
  created during app-node provisioning and repaired by
  `doctor --fix --family=node --restore`. DNS commands must not create, inspect, or
  repair these mappings.
- **App-node resolver drift:** Node-family drift where app-node resolver state
  does not match gateway-owned readiness expectations. DNS commands must not
  create an app-node write exception or repair app-node resolver state.
- **Public DNS boundary:** Product boundary that keeps `dns:*` commands away
  from public DNS records and provider DNS/CDN state. Cloudflare provider DNS
  belongs to `cf-dns:*`, and Orbit ingress intent belongs to app and proxy
  domains.

## Boundaries

- **DNS-domain boundaries:** DNS commands own caller-local resolver override
  reads, writes, resets, backend refreshes, and local DNS reporting for `dns:*`.
  They do not own a state family, create `doctor --family=dns`, mutate gateway
  intent or node reality, create gateway-owned development DNS mappings, create
  app domains or proxy routes, query or mutate Cloudflare/public DNS, or create
  arbitrary per-host DNS mappings.
