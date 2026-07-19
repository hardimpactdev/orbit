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
  selected PostgreSQL process ID. Existing assignments with exactly one
  PostgreSQL candidate remain compatible and may be backfilled. A legacy
  assignment with multiple candidates and no stored process ID fails with a
  clear ambiguity error; candidate ordering never selects a database.
- **Private analytics endpoint:** `https://analytics.orbit`, the internal
  dashboard and admin endpoint served through router. Analytics role deployment
  converges its route and TLS after Plausible is healthy; removal deletes the
  route and its rendered artifacts.
- **Public app analytics host:** App-owned public hostname such as
  `analytics.example.com` that proxies Plausible script and event-ingest paths
  only. The default is `analytics.<app-domain>`, so the app must have a
  configured public domain before analytics can be enabled.
- **App analytics binding:** App-owned state that records whether analytics is
  enabled for an app and which public tracking hosts should exist. Enabling a
  binding converges router and ingress artifacts before success; disabling or
  replacing hosts removes obsolete artifacts before clearing their intent.

## Boundaries

The analytics family owns the public `analytics:*` command vocabulary and the
operator workflow for updating the fleet Plausible CE process version.

It does not own app bindings, proxy rows, or process lifecycle in isolation:
app commands own per-app binding state, proxy owns route artifacts, process owns
runtime lifecycle, and node owns role assignment settings.

Generated PostgreSQL, ClickHouse, and Plausible secrets stay in the process
row's encrypted credential field. Plain `runtime_config` contains non-secret
container intent only.
