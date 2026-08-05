# Analytics Concepts

This document defines analytics-family vocabulary and invariants. It supports
the analytics command contracts and does not override the
[Architecture](../../architecture.md).

## Identity

These terms describe the analytics role and the routes around it.

- **Analytics role:** Private workload node role that runs Plausible CE for the
  fleet. Exactly one assignment may exist, including while deployment or
  removal is incomplete. The role binds only to WireGuard and receives traffic
  through router.
- **Plausible CE process:** Node-owned Docker service row for Plausible CE
  3.2.1. The row owns the image version, endpoint, lifecycle, logs, restart
  policy, and encrypted runtime credentials. Its published port binds directly
  to the analytics node's WireGuard address.
- **Analytics backing database:** Explicitly identified PostgreSQL process and
  a ClickHouse service process selected from active `database` role nodes. A
  single database node may back both services. The supported Plausible pairing is PostgreSQL 16 Alpine and
  ClickHouse 24.12 Alpine; both are authenticated Docker services published
  only on the database node's WireGuard address.
- **Analytics PostgreSQL selection:** Analytics role settings persist the
  selected PostgreSQL process ID. Assignment-time creation requires that stored
  identity. A one-time fleet migration may backfill the stored identity from an
  unambiguous assignment. Multiple candidates without a stored process ID fail
  with a clear ambiguity error. A residual runtime single-candidate fallback
  still chooses the lone PostgreSQL process when stored identity is absent;
  that fallback remains until removed and is not the assignment-time contract.
- **Private analytics endpoint:** `https://analytics.orbit`, the internal
  dashboard and admin endpoint served through router. Analytics role deployment
  converges its route and TLS after Plausible is healthy; removal deletes the
  route and its rendered artifacts.
- **Public instance analytics host:** Public hostname such as
  `analytics.example.com` attached to one selected concrete instance. It
  proxies Plausible script and event-ingest paths only. The default is
  `analytics.<instance-domain>`, so the selected instance must have a public
  domain before analytics can be enabled. Its serving node is the placement
  and authorization boundary even though ingress and router serve the route.
- **Instance analytics binding:** Instance-owned state whose public domain,
  tracking host, route target, and serving-node authorization reference belongs
  to one selected instance. Enabling a binding converges router and ingress
  artifacts before success; disabling or replacing hosts removes obsolete
  artifacts before clearing their intent.

## Boundaries

The analytics family owns the public `analytics:*` command vocabulary and the
operator workflow for updating the fleet Plausible CE process version.

It does not own instance bindings, proxy rows, or process lifecycle in isolation:
instance commands own per-instance binding state, proxy owns route artifacts, process owns
runtime lifecycle, and node owns role assignment settings.

Generated PostgreSQL, ClickHouse, and Plausible secrets stay in the process
row's encrypted credential field. Plain `runtime_config` contains only
container intent without secrets.
