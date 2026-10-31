# Fleet native operations

`fleet.recipe_pr` runs one placed GitHub maintenance DAG on the admitted Gateway
Node. It uses the existing scoped private GitHub token, pins every merge to the
observed head, and records a bounded result. Retry retains the first
authenticated candidate. See
[recipe PR](../../../../docs/fleet-recipe-pr-direct.md).

`fleet.peer_dns` uses its direct workflow on the exact admitted Node.

`fleet.recipe` runs one native convergence root on its admitted Node worker.
Fixed local system programs apply the recipe and PHP build; canonical Node
footprint and CLI functions run as steps of that root. Each effect verifies its
own result, followed by exact locked delivery verification. See
[native recipe convergence](../../../../docs/fleet-recipe-direct.md).

The former Fleet executor, polled schedule, claims, child Operations and
completion receipts are deleted. `fleet.rollout` and `fleet.cli_converge` have
separate parent owners. Fleet must not use orbit-agent action commands; the
agent is an observer.

The maintenance producer remains a native admission schedule. It reads public
Gateway plans and records each intent with a stable key. Recipe PR plans are
read every five minutes; unchanged relevant observations reuse the existing
intent. No second executor or Gateway retry timer runs beside the native DAG.
