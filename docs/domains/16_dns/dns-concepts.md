# DNS Concepts

This document defines DNS-command-domain vocabulary and invariants. It supports
the DNS command contracts; it does not override the
[Architecture](../../architecture.md).

## Domain and scope

These terms define the scope of the DNS command domain and its relationship to the rest of Orbit.

- **DNS command domain:** Local utility command domain for the `dns:*` command
  prefix. It manages the development DNS resolver overrides on the caller machine, but does
  not create a `dns` state family.
- **Caller-local DNS administration:** Local-only DNS workflow allowed only for
  client callers. It mutates or reads the caller machine's resolver
  configuration and never writes gateway configuration or node reality.
- **Caller-local resolver override:** Resolver configuration that Orbit manages
  and that maps wildcard hostnames under a development TLD to a local
  target IP address for browser and CLI use on the caller machine.
- **Local resolver state managed by Orbit:** Resolver files, labels, or config
  blocks written by Orbit on the caller machine. DNS commands may read or
  remove only this managed state, not arbitrary operator resolver entries.
- **Local resolver backend:** Platform resolver implementation Orbit knows how
  to inspect, write, refresh, or restart on a supported client platform.
- **Supported local DNS platform:** Client platform with an Orbit-supported
  local resolver backend. Unsupported platforms fail before local resolver
  reads or writes.

## Resolver Entries

These terms describe the entries that DNS commands read and write on the caller machine.

- **Development TLD:** Single lowercase DNS label without a leading dot, such as
  `test`. In the DNS command domain it names the local wildcard override; it is
  not proof that the gateway has provisioned development TLD readiness for nodes.
- **Local DNS target:** IPv4 or IPv6 address used by a caller-local resolver
  override. `dns:resolve-tld` maps `*.{tld}` to this target through the local
  resolver backend.
- **Resolve path:** `dns:resolve-tld` path that writes or converges the
  caller-local resolver override for a development TLD and target IP address.
- **Reset path:** Destructive `dns:resolve-tld --reset` path that removes only
  the local resolver override that Orbit manages for a development TLD, after
  destructive consent.
- **Resolver refresh:** Platform-specific refresh or restart of the local resolver,
  performed only when the platform requires it for a change to take effect.
- **Local DNS entry:** Renderer DTO for an Orbit-managed local resolver
  override. It includes the TLD, target, source, resolver backend, and status
  when those facts are available.
- **Local resolver source:** Stable renderer source value `local_resolver`,
  meaning the DNS entry came from the resolver state that Orbit manages on the caller machine.
- **Local DNS entry status:** Local resolver entry state reported by DNS
  renderers. `dns:list` reports `active` or `stale`; `dns:resolve-tld` reports
  resolve/reset statuses such as `resolved`, `already_resolved`, `reset`,
  `already_absent`, or `refresh_failed`.

## Gateway and provider boundaries

These terms define what the DNS command domain must not touch.

- **Development DNS mapping owned by the gateway:** Development DNS state for the node family
  created during app-role provisioning and repaired by
  `doctor --fix --family=node --restore`. DNS commands must not create, inspect, or
  repair these mappings.
- **App-role resolver drift:** Node-family drift where app-role resolver state
  does not match the readiness expectations that the gateway owns. DNS commands must not
  create an app-role write exception or repair app-role resolver state.
- **Public DNS boundary:** Product boundary that keeps `dns:*` commands away
  from public DNS records and provider DNS/CDN state. Cloudflare provider DNS
  belongs to `cf-dns:*`, and Orbit ingress configuration belongs to app and proxy
  domains.

## Boundaries

These are the hard limits for everything in the `dns:*` command family.

- **DNS-domain boundaries:** DNS commands own caller-local resolver override
  reads, writes, resets, backend refreshes, and local DNS reporting for `dns:*`.
  They do not own a state family, create `doctor --family=dns`, mutate gateway
  configuration or node reality, create development DNS mappings that the gateway owns, create
  app domains or proxy routes, query or mutate Cloudflare/public DNS, or create
  arbitrary per-host DNS mappings.
