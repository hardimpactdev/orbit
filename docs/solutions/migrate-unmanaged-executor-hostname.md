---
title: "Migrate an unmanaged Executor hostname"
description: "Replace a hand-placed Caddy fragment for a Node Process, such as executor.test, with a Gateway-owned custom proxy Route."
covers:
  - apps/cli/app/Commands/Routes/CreateRouteCommand.php
  - apps/gateway/app/Domain/Routes/CaddyPublicationPlan.php
---

# Migrate an unmanaged Executor hostname

## Problem

A Node runs a service, such as a self-hosted Executor, as a Node-owned Docker Process. Its private hostname, often `executor.test`, is published by hand: a Caddy fragment and an Orbit CA certificate under `/etc/caddy/orbit-certificates/executor/`. The name does not appear in `route:list`, and Doctor does not check it.

## Cause

An app Route comes from an Instance, so a Node Process has no Route of its own. The first [Node Caddy build](/reference/caddy-configuration#node-caddy-build) on that Node moves the hand-placed fragment to `/etc/caddy/orbit-backups/` and stops serving the name. Orbit never deletes the hand-placed certificate files or moves DNS clients.

## Solution

Create a custom proxy Route before that build, and move clients to it:

```bash
orbit route:create executor.orbit --node=beast --process=executor
```

Use `--upstream=http://127.0.0.1:4788` instead of `--process` when the Process listens on a known loopback port. When `https://executor.orbit` works, delete the hand-placed files and drop `executor.test` from client configuration. [Custom proxy Routes](/reference/routes#custom-proxy-routes) describes uniqueness, DNS, Caddy, and removal.

## Limits

A name under a Cluster TLD still needs its own custom proxy Route, because the TLD wildcard sends unmatched names to the Router.

## Verification

`orbit route:show` reports kind `custom_proxy`, `getent ahostsv4 executor.orbit` returns the Node's address, and `orbit doctor --family=route` is healthy for the Node.
