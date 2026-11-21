---
title: "Migrate an unmanaged Executor hostname"
description: "Replace a hand-placed Caddy fragment for a Node Process with a Gateway-owned custom proxy Route."
covers:
  - apps/cli/app/Commands/Routes/CreateRouteCommand.php
  - apps/gateway/app/Domain/Routes/CaddyPublicationPlan.php
---

# Migrate an unmanaged Executor hostname

## Problem

A Node runs a service (such as a self-hosted Executor) as a Node-owned Docker Process published via a hand-placed Caddy fragment and certificate (for example `executor.test` under `/etc/caddy/orbit-certificates/executor/`). That hostname does not appear in `orbit route:list`.

## Cause

The first [Node Caddy build](/reference/caddy-configuration#node-caddy-build) on that Node moves unmanaged Caddy fragments to `/etc/caddy/orbit-backups/` and stops serving the hostname. Orbit does not delete hand-placed certificate files or update client configs.

## Solution

Create a Gateway-owned custom proxy Route before the Caddy build and move clients to it:

```bash
orbit route:create executor.orbit --node=beast --process=executor
```

Use `--upstream=http://127.0.0.1:4788` instead of `--process` when the Process listens on a fixed loopback port. Once `https://executor.orbit` responds, delete the hand-placed files and remove `executor.test` from clients. See [Custom proxy Routes](/reference/routes#custom-proxy-routes) for uniqueness, DNS, and removal rules.

## Limits

A hostname under a Cluster TLD still requires its own custom proxy Route because the TLD wildcard sends unmatched names to the Router.

## Verification

Confirm the Route, private DNS resolution, and HTTPS response:

```bash
orbit route:show <route-id>
getent ahostsv4 executor.orbit
curl --fail https://executor.orbit/
```

`orbit route:show <route-id>` reports kind `custom_proxy`, `getent ahostsv4` returns the Node's address, and HTTPS answers cleanly.
