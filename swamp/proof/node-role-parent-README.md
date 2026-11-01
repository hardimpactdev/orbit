# Role parent proof inputs

Each JSON variant drives the real operator CLI through the F13 runner. The topology Gateway records the assignment, captures the public intent, supplies private execution copies and signs through its own CA. The fixtures contain partial input expectations and typed matchers for generated identities. They contain no synthetic artifact pins, private material, signer address or fleet target.

| Kind / variant | Topology | Request |
| --- | --- | --- |
| role_add / database | app-dev | Add Database to app-dev |
| role_add / ingress | app-prod | Create a Cluster, attach app-prod, then add Ingress |
| role_add / websocket | app-prod | Add WebSocket to app-prod |
| role_add / app-prod | spare | Add AppProd to roleless spare |
| role_relocate / websocket | app-prod, app-prod-2 | Prepare WebSocket on app-prod, then transfer the same assignment to app-prod-2 |

Lane 11 owns the `spare` catalog entry. A missing entry blocks that variant; the fixture does not remove an existing role or edit the Gateway database to manufacture a target. Signing uses the topology Gateway's normal CA. The relocation fixture captures the source assignment from the completed prepare Operation and requires the request to retain that identity.

Parity runs on the proof VM host. It reads the terminal Operation and role assignments through the operator CLI, then checks the target service and files through `PROOF_EXEC`. WebSocket relocation also proves that the source unit is absent and its role assignment is gone. Each fixture lists the legacy failure cases covered by the focused admission, parent, runtime and residue tests. Those tests remain required alongside the successful topology run.

These files are proof requests, not proof receipts. `bin/swamp-proof` is not installed in this checkout yet. Native fixture measurements in `node-role-parent-timings.json` use executable seams and exclude real downloads, packages and network time. Their p95 values and the 120/180-second draft budgets remain provisional; the topology measurement must set the final F8 budgets before merge. The shared prerequisites phase currently permits 600 seconds, which must also be reconciled with the parent budget. Queue timeout is 30 seconds.

The source draft is not ready: unported-role routing and the last native certificate-residue conversion remain open. Parent aggregation passes only named statuses and successful contribution/cleanup receipts. Every parent step declares its 30-second queue timeout. The old retained-converge workflow is removed; the shared converge dispatcher retains its canonical add-root fallback. No source update authorizes a production load or Node effect.

## Remaining legacy phases

| Role | Add sequence still to port through the shared factory | Singleton relocation |
| --- | --- | --- |
| Gateway | prerequisites; private DNS; Caddy VPN ordering; runtime/observer; certificates/publication; firewall; CLI | target forward proof; existing publication withdrawal; native DNS grace where applicable; source runtime and credential cleanup |
| AppDev | storage and hibernation paths; role prerequisites; PHP; Composer/JavaScript managers; runtime/publication; footprint; CLI | not relocatable |
| AppProd | canonical PHP/JavaScript/Composer prerequisites; Caddy VPN ordering; publication/firewall; runtime footprint; CLI (shared factory checkpoint included) | not relocatable |
| Metrics | Metrics runtime and credentials; exporter fleet contribution; publication/firewall; footprint; CLI | target contribution, then source publication and old-owner cleanup while retaining shared exporters (#2278) |
| Analytics | managed ClickHouse/Postgres preparation; Plausible runtime; certificates/publication; firewall; footprint; CLI | not relocatable |
| CiRunner | canonical host/project preparation; prerequisites; observer/footprint; firewall; CLI | not relocatable |

Database, Ingress, WebSocket and AppProd forward contributions come from the shared Nodes factory. WebSocket is the supported singleton relocation in these fixtures. VPN and Router are protected from generic mutation. The draft still needs to preserve the existing execution path for the other mutable roles; rejecting them before effects does not meet the merge gate.
