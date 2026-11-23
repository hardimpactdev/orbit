---
title: "Native Route targets"
description: "How a Route target change runs, the rendered files each step writes, and how the Gateway updates Route targets from the result."
---

# Native Route targets

[`route:target:set`](/cli/route/target-set) changes which Instances back a Route. The Gateway renders every needed file (Caddyfiles, PHP pools, `APP_URL` lines, and certificates) up front. Production `.env` contents stay in private vault inputs.

## Development target

For a development Route, the Gateway stores the new target immediately. If the Route is not published, the Operation completes with no Node work. If it is published, Orbit requests and signs the certificate and publishes updated Caddyfiles on each affected Node. The old Node loses the site and the new Node gains it.

## Production target set

For a Cluster Route, `route:target:set` replaces the ordered target pool by preparing new targets before switching the Router:

1. **Make and sign CSRs:** generates keys on each new target Node (temporary staged leaf plus lasting leaf) and signs them on the Gateway.
2. **Stage new targets:** applies LAN rules for the Router, certificates, PHP pools, `.env` updates, and a staged Caddyfile keeping existing sites intact.
3. **Probe new targets:** verifies TLS to each new target using the Route domain as SNI.
4. **Switch the Router pool:** writes and validates the Router's Caddyfile with the new pool and reloads Caddy.
5. **Retire detached targets:** writes desired Caddyfiles on detached Nodes and removes unused LAN rules and temporary certificates.
6. **Move private DNS:** updates private DNS only for a Node-scoped Route outside the Cluster TLD.

If a step before switching the Router fails, the Route keeps its old targets. If retirement or DNS update fails after the Router switch, the Gateway records the new active targets so rerunning the same request finishes cleanup without rollback. An already-applied target set completes immediately with no Node work.

| Error code | HTTP / Step | Meaning |
| --- | --- | --- |
| `route.target_cleanup_unavailable` | 503 | Disposition requested `remove: true`, which is not available on this path. |
| `route.target_set_recovery_required` | 409 | A prior target change left unfinished cleanup that must finish first. |
| `route.target_conflict` | 409 | Another target change conflicts with the requested Route targets. |
| `app-dev.certificate_request_failed` | Make CSRs on new Nodes | Generating the CSR on a target Node failed. |
| `app-dev.certificate_sign_failed` | Sign the CSRs | Signing the CSR on the Gateway failed. |
| `route.publish_failed` | Install cert, publish site | Publishing a development Route site failed. |
| `route.target_stage_failed` | Stage the new targets | Preparing a new production target Node failed. |
| `route.probe_failed` | Probe each new target | TLS probe to a staged target failed. |
| `route.router_switch_failed` | Switch the Router pool | Updating or reloading the Router's Caddyfile failed. |
| `route.target_retire_failed` | Retire detached targets | Cleaning up detached target Nodes failed. |
| `private_dns.publication_failed` | Move private DNS | Updating private DNS failed. |
| `route.target_set_failed` | Other | Any failure outside the steps above. |
