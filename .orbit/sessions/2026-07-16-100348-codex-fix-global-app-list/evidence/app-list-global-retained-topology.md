# Global app list retained-topology proof

- Date: 2026-07-16
- Topology id: `dev-eb4624`
- Provider/kind: `incus` / `operator_gateway`
- Host: `beast`
- Instances:
  - operator: `orbit-e2e-dev-eb4624-operator`
  - gateway: `orbit-e2e-dev-eb4624-gateway`
- Checkout roles: `operator,gateway`
- Runtime checkout: `/home/orbit/orbit-run`

The initially selected
`operator_gateway_app-dev_app-prod_ingress` prepared topology could not be
acquired. Its pre-feature substrate failed because the dev executor was not
listening on `10.6.0.4:9477` and the prod retarget lacked required ingress
metadata. Acceptance therefore used a healthy operator+gateway source-mounted
topology and seeded two app-role node records in the retained gateway database.

## Exact-source proof

The local and retained-topology hashes matched:

```text
20a70dec5ed3762d3b10d55cd3410a1b0f39960a70a94c9e8d90ba558773ac6f  apps/cli/app/Commands/App/AppListCommand.php
610f7f6a1c94c80485f8909f72be271a7011f3017429725a12664ff619044032  apps/gateway/app/Http/Controllers/Api/AppListController.php
```

## Registry fixture

The retained gateway registry contained:

- `multi-node-app`, whose default metadata pointed at `proof-app-b`, with
  concrete instances on both `proof-app-a` and `proof-app-b`.
- `default-elsewhere`, whose default metadata pointed at `proof-app-b`, with
  its concrete instance on `proof-app-a`.
- `hidden-default-local`, whose default metadata pointed at `proof-app-a`, with
  its concrete instance on `proof-app-b`.
- Two `multi-node-app` workspaces placed on different instances, producing
  `.proof-a` and `.proof-b` URLs.

## Command proof

From the retained operator:

```bash
cd /home/orbit/orbit-run
php apps/cli/orbit app:list --json
php apps/cli/orbit app:list
php apps/cli/orbit app:list --node=proof-app-a
```

Results:

- JSON assertions passed.
- The sorted app names were `default-elsewhere`, `hidden-default-local`, and
  `multi-node-app`.
- `multi-node-app` appeared exactly once despite having two instances.
- Its two workspace URLs retained their distinct `.proof-a` and `.proof-b`
  instance placement.
- Human output rendered one table with no node grouping.
- `--node` was rejected with `The "--node" option does not exist.`

Result: **PASS**
