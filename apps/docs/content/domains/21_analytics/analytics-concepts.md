# Analytics Concepts

This document defines analytics-family vocabulary and invariants. It supports
the analytics command contracts and does not override the
[Architecture](../../architecture.md).

## Identity

These terms describe the analytics role and the routes around it.

- **Analytics role:** Private workload node role that runs Plausible CE for the
  fleet. The role binds only to WireGuard and receives traffic through router.
- **Plausible CE process:** Process-owned service row for Plausible CE. The row
  owns the image version, environment, endpoint, lifecycle, logs, and restart
  policy.
- **Analytics backing database:** PostgreSQL or ClickHouse service process
  selected from an active `database` role node. A single database node may back
  both services.
- **Private analytics endpoint:** `https://analytics.orbit`, the internal
  dashboard and admin endpoint served through router.
- **Public app analytics host:** App-owned public hostname such as
  `analytics.example.com` that proxies Plausible script and event-ingest paths
  only.
- **App analytics binding:** App-owned state that records whether analytics is
  enabled for an app and which public tracking hosts should exist.

## Boundaries

The analytics family owns the public `analytics:*` command vocabulary and the
operator workflow for updating the fleet Plausible CE process version.

It does not own app bindings, proxy rows, or process lifecycle in isolation:
app commands own per-app binding state, proxy owns route artifacts, process owns
runtime lifecycle, and node owns role assignment settings.
