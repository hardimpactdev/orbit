# Managed-service Doctor rehydration proof

- Candidate: `a3bab1c225d22f3fae1ca3cf90a8443c8aa65598`
- Venue: retained Incus
- Environment: dev fixture
- Topology: `dev-02a7ba` (`operator_gateway_app-dev`)
- Incus host: `nckrtl@192.168.6.20` through SSH alias `beast`
- Gateway VM: `orbit-e2e-dev-02a7ba-gateway`
- Target node: `app-dev-1`
- Solo terminal: process `2378`, cwd `/home/orbit/orbit-run`
- Launcher: `/usr/local/bin/orbit` resolved to `/home/orbit/orbit-run/apps/cli/orbit`

## Exercise

1. Created `doctor-proof-postgres` as a node-owned PostgreSQL 17 Docker service.
   Stored intent used database `proofdb`, user `proofuser`, published port `5544`,
   and the `loopback` bind.
2. Recorded the credential SHA-256 as
   `1d2d1bd835312b26294860a84d26462ae1236cb67984792970c1df4808b3d6e3`.
3. Removed derived runtime fields from the disposable process row while keeping
   service, version, service options, bind intent, and encrypted credentials.
4. Ran:
   `orbit doctor --node=app-dev-1 --family=process --key=process.runtime_unit_unrenderable --json`
5. Observed one restorable `process.runtime_unit_unrenderable` issue because
   `runtime_config.image` was absent.
6. Ran:
   `orbit doctor --node=app-dev-1 --family=process --key=process.runtime_unit_unrenderable --restore --json`
7. Observed one completed Doctor action, one convergence pass, stop reason
   `converged`, no remaining issues, and a healthy final fresh observation.

## Result

After restore:

- credential SHA-256 stayed
  `1d2d1bd835312b26294860a84d26462ae1236cb67984792970c1df4808b3d6e3`;
- runtime credential hash was `1d2d1bd835312b26`;
- image was `postgres:17-alpine`;
- service options stayed `database=proofdb`, `username=proofuser`, and
  `published_port=5544`;
- bind intent stayed `["loopback"]`;
- endpoint was `127.0.0.1:5544`;
- runtime spec hash was `4185c4e1523488a2`; and
- a second exact-key verify reported zero issues.

Result: passed.
