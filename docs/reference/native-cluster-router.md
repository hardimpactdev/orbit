---
title: "Native Cluster Router transitions"
description: "How a Cluster Router set or unset runs, the rendered files each step writes, and how the Gateway records the resulting Router."
---

# Native Cluster Router transitions

`cluster.router_set` and `cluster.router_unset` follow [`cluster/router-set`](/cli/cluster/router-set) and [`cluster/router-unset`](/cli/cluster/router-unset). Admission captures the candidate Node, existing Router assignments, and every Cluster Route. It also renders each Node's before, prepared, and lasting Caddyfiles plus private DNS records up front.

## Set a Router

The candidate serves every Cluster Route before private DNS points at it:

| Order | Step | What it does |
| --- | --- | --- |
| 1 | Hold Route Nodes | Locks affected Route Nodes for the transition (`route_hold.busy` on conflict). |
| 2 | Prepare the Router | Installs the `router` role account, packages, and pinned Caddy package on the candidate. |
| 3 | Request and sign CSRs | Generates a key on the candidate for each Cluster Route and signs the CSR on the Gateway. |
| 4 | Open LAN paths | Allows Cluster members to reach `443/tcp` and `53/tcp+udp`, and the candidate to reach workload Route ports. |
| 5 | Install certs and publish | Writes each Node's prepared Caddyfile so the candidate serves alongside the current Router. |
| 6 | Probe the workloads | Verifies over LAN from the candidate that each workload answers with a trusted Orbit CA chain. |
| 7 | Select the Router in DNS | Points Cluster names at the candidate and waits for the DNS grace window. |
| 8 | Retire the old Router | Writes the old Router's lasting Caddyfile, removes its Router certificates, and drops unused LAN rules. |
| 9 | Release Route Nodes | Releases the transition holds. |

If a step before DNS selection fails, Orbit restores the before Caddyfiles, added LAN rules, and prior DNS records. The current Router keeps serving. To clean up a failed candidate, run `orbit node:role:remove <node> router` ([stale Router assignment](/cli/node/role-remove#a-stale-router-assignment)). If retirement fails after DNS selection, the candidate stays active and the old assignment remains unfinished for a rerun.

## Unset a Router

When no Node holds Router state, admission deletes the assignment and finishes immediately. Otherwise Orbit withdraws Router sites and certificates from each Router Node, removes unused Router LAN rules (keeping baseline WireGuard rules), and verifies those rules are gone.

| Error code | Step |
| --- | --- |
| `route_hold.busy` | Hold Route Nodes |
| `cluster.router_prepare_failed` | Prepare the Router |
| `app-dev.certificate_request_failed` | Request the certificates |
| `app-dev.certificate_sign_failed` | Sign the CSRs |
| `route.lan_firewall_failed` | Open LAN paths, Remove the LAN rules |
| `route.publish_failed` | Install certs, publish |
| `route.probe_failed` | Probe the workloads |
| `private_dns.publication_failed` | Select the Router in DNS |
| `cluster.router_retire_failed` | Retire the old Router |
| `route.withdraw_failed` | Withdraw the Router sites |
| `route.removal_check_failed` | Check the rules are gone |
| `cluster.router_failed` | Any other failure |

The Gateway updates only the admitted Router assignment IDs from the terminal run result and leaves unrelated roles intact.
