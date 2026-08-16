# Todo #748 retained Incus proof

- Candidate: `aae3c2abc491c616bc8dfe039fe77962dcd706b7`
- Venue: `retained-incus`
- Environment: `dev-fixture`
- Topology: `dev-fa33f8` (`operator_gateway_app-dev`)
- Provider/host: Incus on Beast
- Solo terminal: local Solo project 2, process `2415` (`todo-748-retained-incus`)
- Runtime checkout: `/home/orbit/orbit-run`
- Nodes: `operator-1`, `gateway`, `app-dev-1`

## Candidate identity

The runtime checkout and local candidate had identical SHA-256 hashes:

```text
3f7ed317d9cafb7276db751cb267055dff649941bb9df87651b64014d85b3688  apps/gateway/app/Models/App.php
e391e5fb3f7cb652838194ca3e40d456dfa6be4a5765b6a0459c2a05e92f90ce  apps/gateway/database/migrations/2026_08_16_120000_drop_app_placement_shadow_columns.php
```

The Solo terminal ran from `/home/orbit/orbit-run`. Its launcher was
`/home/orbit/.local/bin/orbit`, installed by the retained source-mounted
topology.

## Real registration and schema boundary

A minimal PHP fixture at `/home/orbit/proof-748/public/index.php` on
`app-dev-1` returned `proof-748`. From the retained operator Solo terminal:

```text
orbit instance:register proof-748 --node=app-dev-1 --path=/home/orbit/proof-748 --root=public --json
result.action=adopted
instance.name=development
instance.environment=development
instance.node=app-dev-1
instance.path=/home/orbit/proof-748
instance.root=public
instance.adopted=true

orbit instance:register proof-748 --node=app-dev-1 --path=/home/orbit/proof-748 --root=public --json
result.action=converged
warnings=[]
```

A read-only query of the retained gateway database returned these `apps`
columns:

```text
id
name
repository
php_version
created_at
updated_at
agent_ide_config
runtime
runtime_config
```

The removed placement shadows were absent: `node_id`, `environment`, `domain`,
`path`, `document_root`, and `adopted`. The joined concrete Instance row held
the placement and adoption values:

```json
{
  "app_name": "proof-748",
  "instance_name": "development",
  "driver": "orbit",
  "php_version": "8.5",
  "adopted": 1,
  "node": "app-dev-1",
  "path": "/home/orbit/proof-748",
  "document_root": "public",
  "domain": null
}
```

`orbit app:show proof-748 --json` returned a logical App payload with no
placement attributes. Its `details.instances[0]` held the placement above.
The same response named process `frankenphp-proof-748` for instance
`development` and the instance-owned route `proof-748.test`.

## Runtime and routing final hop

The prepared dev fixture needed the already-built private FrankenPHP image.
The exact image was copied from another retained dev fixture on the same Incus
host into this isolated topology. The feature checkout was not changed.

The final candidate commands and observations were:

```text
orbit doctor --node=app-dev-1 --family=instance --restore --json
doctor.healthy=true
doctor.summary.fixed=1
doctor.summary.failed=0
doctor.summary.stop_reason=converged

orbit process:start --instance=proof-748 --json
runtimes[0].runtime_unit=orbit-app-proof-748-development
runtimes[0].state=running
runtimes[0].event.type=started
```

Container inspection showed the concrete Instance path mounted directly:

```text
source=/home/orbit/proof-748 destination=/app rw=true
container=orbit-app-proof-748-development health=healthy
```

The node Caddy route targeted the same instance runtime:

```text
proof-748.test -> http://orbit-app-proof-748-development:8080
```

The final route request from the operator Solo terminal exercised TLS, the
node proxy, the instance runtime, and the concrete source mount:

```text
curl -k -sS --max-time 15 --resolve proof-748.test:443:10.6.0.4 -w '\nSTATUS=%{http_code}\n' https://proof-748.test
proof-748
STATUS=200
```

Result: **passed**.
