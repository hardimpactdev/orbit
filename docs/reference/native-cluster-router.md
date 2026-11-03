---
title: Native Cluster Router transitions
description: "How a Router set or unset runs: the charts' steps, whole rendered files, and the Router the Gateway records from the result."
---

`cluster.router_set` and `cluster.router_unset` follow their charts, [`cluster/router-set.mmd`](/cli/cluster) and [`cluster/router-unset.mmd`](/cli/cluster). Admission captures the candidate, the old Router assignments and every Cluster Route. The Gateway renders the whole files each step writes: each Node's before, prepared and lasting Caddyfile, and the private DNS records. Every step runs on Node 209 through official `@swamp/ssh` and `@swamp/cfgmgmt` behaviors with the stored host keys. Admission reads no Node account and changes no Router.

## Set a Router

The candidate serves every Cluster Route before private DNS selects it:

1. `Prepare the Router` installs the router role's account, packages and pinned Caddy package on the candidate.
2. `Request the certificates` and `Sign the CSRs` make a key on the candidate for each Cluster Route and have the Gateway sign its request.
3. `Open LAN paths` lets members reach the candidate on 443/tcp and 53/tcp+udp, and the candidate reach each workload's Route ports.
4. `Install certs, publish` writes each Node's prepared Caddyfile, which serves the Cluster Routes from the candidate beside the old Router.
5. `Probe the workloads` checks from the candidate over the LAN that every Route's workload answers with a chain the Orbit root trusts.
6. `Select the Router in DNS` points the Cluster names at the candidate, then waits the DNS grace.
7. `Retire the old Router` writes the old Router's lasting Caddyfile and removes its Router certificates and the LAN rules only it needed.

When a step before the selection fails, `Restore the before state` writes the captured before Caddyfiles, removes the LAN rules the change added and puts the before DNS records back. The old Router keeps serving and the candidate assignment is marked failed. When `Retire the old Router` fails, the candidate already serves: it becomes active and the old assignment stays unfinished for a rerun. A new version of a certificate scope that a site still names installs only in the retire step.

## Unset a Router

`Withdraw the Router sites` writes each Router Node's Caddyfile without the Router sites and removes their certificates. `Remove the LAN rules` removes the Router tuples no Route needs. `Check the rules are gone` runs the `check` of the same behaviors on each Node and is the end check. It never removes Node baseline WireGuard rules.

When no Node holds Router state, admission ends the unset at once with no run (R1): it deletes the Router assignment and records the Operation as succeeded.

| Error code | Step |
| --- | --- |
| `cluster.router_prepare_failed` | `Prepare the Router` |
| `app-dev.certificate_request_failed` | `Request the certificates` |
| `app-dev.certificate_sign_failed` | `Sign the CSRs` |
| `route.lan_firewall_failed` | `Open LAN paths`, `Remove the LAN rules` |
| `route.publish_failed` | `Install certs, publish` |
| `route.probe_failed` | `Probe the workloads` |
| `private_dns.publication_failed` | `Select the Router in DNS` |
| `cluster.router_retire_failed` | `Retire the old Router` |
| `route.withdraw_failed` | `Withdraw the Router sites` |
| `route.removal_check_failed` | `Check the rules are gone` |
| `cluster.router_failed` | A failure outside these steps |

The Gateway reads the outcome from Swamp's run data, never from step output. It changes only the admitted Router assignment IDs and leaves unrelated roles intact.
