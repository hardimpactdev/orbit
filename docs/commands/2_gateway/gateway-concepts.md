# Gateway Concepts

This document defines gateway-command-domain vocabulary and invariants. It
supports the gateway command contracts and the [node doctor](../1_node/node-doctor.md);
it does not override the [Architecture](../../ARCHITECTURE.md).

## Domain And Relationship

- **Gateway command domain:** The `gateway:*` command prefix. It manages the
  caller's local relationship with an Orbit gateway after gateway-owned node
  identity already exists, but it does not create a separate state family.
- **Gateway relationship:** Caller-local state that lets Orbit find the
  configured gateway, trust the gateway API certificate chain, and verify the
  caller against gateway-owned node identity and access policy when onboarding
  requires it.
- **Configured gateway endpoint:** Caller-local default gateway URL or
  WireGuard API address used by subsequent Orbit commands. First-gateway
  bootstrap or `gateway:add` establishes it; `gateway:trust` reads it but does
  not replace it.
- **Gateway WireGuard API address:** Orbit network address for the gateway's
  typed HTTPS API. `gateway:add` may accept it explicitly or derive it from the
  active Orbit WireGuard network when that is unambiguous.
- **Local gateway configuration:** Caller-local settings that store the
  configured gateway endpoint, gateway WireGuard IP, trust material path or
  fingerprint, and related gateway-client metadata.

## Trust Model

- **Gateway root CA:** Gateway-owned Orbit root certificate authority used as
  the trust anchor for the gateway API and Orbit-managed route certificates.
  The gateway owns private CA material; callers and serving nodes receive only
  the public root or route-scoped leaf material they need.
- **Gateway trust material:** Public gateway root CA certificate or trust bundle
  fetched from the gateway and stored locally by gateway onboarding or trust
  repair. It must not include gateway root private key material.
- **Bootstrap-safe trust path:** Gateway endpoint, currently `/api/ca/root`,
  that exposes public trust material over the Orbit network before the caller
  has local OS-level trust for the gateway installed.
- **Local gateway CA trust:** Caller-machine OS trust-store entry for the
  gateway root CA. It lets the caller trust the gateway API and any
  Orbit-managed route certificate that chains to the same root.
- **Local trust metadata:** Caller-local record of the trusted gateway URL,
  gateway CA fingerprint, certificate path, and trust timestamp. Doctor uses it
  to verify whether local gateway trust still matches gateway-owned trust
  material.
- **Orbit route trust:** The consequence of trusting the gateway root CA:
  caller trust for app, workspace, proxy, gateway, and tool route leaf
  certificates that chain to that root. Route certificate issuance, upload,
  renewal, cleanup, and serving-node TLS files remain owned by the route-owning
  domains and doctor families.

## Onboarding And Verification

- **Local gateway onboarding:** `gateway:add` flow for a control node that
  already has a gateway-issued WireGuard identity. It resolves the gateway,
  fetches trust material, installs or refreshes local trust, verifies the
  gateway API and local identity, and stores local gateway configuration.
- **Gateway trust repair:** `gateway:trust` flow that refreshes caller-local
  gateway CA trust for an already configured gateway endpoint. It does not
  select a new gateway, verify `/api/me`, or onboard a control-node identity.
- **Gateway API verification:** Trusted HTTPS request to `/api/me` that proves
  the configured gateway is reachable and the caller's WireGuard identity is
  known to gateway-owned access policy.
- **Gateway onboarding convergence:** Successful `gateway:add` outcome when
  local gateway configuration, local gateway CA trust, and `/api/me`
  verification already match the target gateway.

## Boundaries

- **Gateway-domain boundaries:** Gateway commands own the `gateway:*` command
  prefix, caller-local gateway configuration, local gateway CA trust repair,
  and explicit local onboarding for already issued control-node identities. They
  do not own a state family, create gateway or control node records, mint
  WireGuard peer material, grant node access, provision hosts, repair broad node
  drift, or manage route-scoped TLS leaf certificate lifecycle.
