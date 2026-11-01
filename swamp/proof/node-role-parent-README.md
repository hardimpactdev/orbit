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

These files are proof requests, not proof receipts. The runner is B’s draft #2314 (`origin/feat/swamp-proof`), not main. Four variants pass its schema and public binding dry-run at f6aa6e9fec2db69857ce49325bbeaeb2cc3508eb; AppProd remains blocked by the owner’s missing `spare` catalog entry. C authorizes this schema/dry-run only; `up` is not live on shark, and live Task VM destruction is held on the Node-removal token phases. No executable topology proof was run. Native fixture measurements in `node-role-parent-timings.json` use executable seams and exclude real downloads, packages and network time. Their p95 values are local seam measurements. Draft fixture budgets now match the current Gateway policy (add2700seconds; unmeasured relocate1800seconds), covering the600-second shared prerequisite phase. The topology measurement must set the final F8 port budgets before merge. Queue timeout is 30 seconds.

The draft preserves the existing Node-worker route for unported contributions; it does not retire those placements. Both branches use the same original Operation and root run. Native factory steps use the `forward_` prefix to avoid collisions with the retained graph. Local native fixtures preserve the full dispatcher graph and replace retained effects with fail-if-called boundaries; they prove branch guards and job-name uniqueness, not retained effects or public API transport. The new residue child uses the canonical certificate filesystem action, PHP policy and firewall policy after native Caddy publication. Its parent treats failure as a retryable skip. F13 must prove the complete path before merge. The retained Gateway DNS callers belong to the Nodes lane: role_direct.ts for convergence and role_removal_local.ts → role_removal.ts → operation-node-role-remove-direct for standalone role removal. Neither caller is retired by this parent switch. Their existing baseline counts shrink with the completed Caddy extraction; no new row is added. The legacy certificate-removal parity case now runs unchanged in certificates/residue_native_test.py through the current native certificate children and a test adapter for the parent reload dependency. Main’s pure native_fixture.py remains the shared fixture. Parent aggregation passes only named statuses and successful contribution/cleanup receipts. Every parent step declares its 30-second queue timeout. The old retained-converge workflow is removed; the shared converge dispatcher retains its canonical add-root fallback. No source update authorizes a production load or Node effect.

## Remaining legacy phases

| Role | Add sequence still to port through the shared factory | Singleton relocation |
| --- | --- | --- |
| Gateway | prerequisites; private DNS; Caddy VPN ordering; runtime/observer; certificates/publication; firewall; CLI | target forward proof; existing publication withdrawal; native DNS grace where applicable; source runtime and credential cleanup |
| AppDev | storage and hibernation paths; role prerequisites; PHP; Composer/JavaScript managers; runtime/publication; footprint; CLI | not relocatable |
| Metrics | Metrics runtime and credentials; exporter fleet contribution; publication/firewall; footprint; CLI | target contribution, then source publication and old-owner cleanup while retaining shared exporters (#2278) |
| Analytics | managed ClickHouse/Postgres preparation; Plausible runtime; certificates/publication; firewall; footprint; CLI | not relocatable |
| CiRunner | canonical host/project preparation; prerequisites; observer/footprint; firewall; CLI | not relocatable |

Database, Ingress, WebSocket and AppProd forward contributions come from the shared Nodes factory. WebSocket is the supported singleton relocation in these fixtures. VPN and Router are protected from generic mutation. The other mutable roles retain their existing execution path in the original root; their per-Node placements remain until those contributions are ported.
