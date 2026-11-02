---
title: "Native Route targets"
description: "How a target change runs: the chart's Swamp steps, whole rendered files, and the targets the Gateway writes from the result."
---

# Native Route targets

`route:target:set` follows its chart, [`docs/cli/route/target-set.mmd`](/cli/route). The Gateway renders every file a step writes: each Node's whole Caddyfile, PHP pools, `APP_URL` lines and certificate leaves. The run on Node 209 writes them with official `@swamp/cfgmgmt` behaviors and single `@swamp/ssh` commands. Production `.env` files reach the run only as vault key names. No step reads back, merges, or restores a file.

## Development target

The Gateway stores a development target at once. When the Route's sites are not published, nothing changes on a Node, so the Operation has already succeeded and no run starts. When the sites are published, the run makes and signs the certificate, then runs `route:create`'s publish step, `Install cert, publish site`, for each Node whose files change: the previous target's Node loses the site, and the new target's Node gains it. `instance:create` already gave Caddy access to the checkout, so no access commands run.

## Production target set

A production set replaces the ordered targets of a Cluster Route. The run prepares each new target before the Router selects it:

1. `Make CSRs on new Nodes` and `Sign the CSRs` make a key on each Node that needs a leaf and have the Gateway sign its request. A new target gets a temporary leaf for its staged site and its lasting leaf.
2. `Stage the new targets` prepares each new target Node: its LAN rule for the Router, certificates, PHP pool, `.env` and staged Caddyfile. The staged Caddyfile keeps the old sites.
3. `Probe each new target` checks TLS from Node 209 to each new target, with the Route domain as SNI.
4. `Switch the Router pool` writes the Router's whole Caddyfile with the new pool, validates it, and reloads Caddy.
5. `Retire detached targets` writes each other Node's desired Caddyfile, and removes LAN rules and temporary certificates that no Route needs.
6. `Move private DNS` runs only for a Node-scoped Route whose domain is outside the TLD.

The end check is the Router switch, or the publish step of a development target. On success the Gateway writes the new associations in one transaction. When a step before the switch fails, the Route keeps its old targets. When `Retire detached targets` or `Move private DNS` fails, the new pool already serves, so the Gateway writes the new associations and keeps the recorded set. The same request then runs again and retires what is left. There is no rollback.

A disposition with `remove: true` returns `route.target_cleanup_unavailable` before it reserves or changes associations, until the shared Instance removal steps run here. An already completed target proposal needs no Node work and ends at once.

| Error code | Step |
| --- | --- |
| `app-dev.certificate_request_failed` | `Make CSRs on new Nodes` |
| `app-dev.certificate_sign_failed` | `Sign the CSRs` |
| `route.publish_failed` | `Install cert, publish site` |
| `route.target_stage_failed` | `Stage the new targets` |
| `route.probe_failed` | `Probe each new target` |
| `route.router_switch_failed` | `Switch the Router pool` |
| `route.target_retire_failed` | `Retire detached targets` |
| `private_dns.publication_failed` | `Move private DNS` |
| `route.target_set_failed` | A failure outside these steps |

Instance transfer keeps its own target helpers until that parent converts.
