# Testing Infrastructure Porting Notes

Orbit's clean rebuild uses contract-first Pest coverage for ordinary command,
service, renderer, and gateway API behavior. Host mutation belongs to ephemeral
E2E lanes only.

## Current Verification Lanes

Use the in-memory Pest lane for default development:

```bash
composer test
```

Use prepared-topology E2E for gateway/API/runtime behavior that needs a cloned
Docker-first topology but not fresh host provisioning:

```bash
composer test:e2e
composer test:e2e:docker
composer test:e2e:incus
composer test:e2e:topology-contract
```

Use provisioning E2E when installer, topology preparation, `node:new`, real
WireGuard provisioning, SSH, systemd, package installation, or other host setup
behavior changes:

```bash
composer test:e2e:provision
```

Use the preparation and cleanup helpers when refreshing disposable topology
state:

```bash
composer e2e:preflight
composer e2e:prepare-topology
composer e2e:prepare-docker-runtime
composer e2e:prepare-docker-topology -- --force operator_gateway_app-dev_app-prod
composer e2e:prepare-docker-hosts -- --force operator_gateway_app-dev_app-prod
composer e2e:prepare-base-image
composer e2e:reap-incus
composer e2e:reap-docker
```

## Porting Rule

Do not reintroduce standing live-node smoke tests as a default verification
lane. Persistent nodes are operational infrastructure, not a test fixture.

When porting an old behavior, start with in-memory Pest coverage. Move the test
to Docker-backed feature E2E only when the prepared topology is part of the
contract. Move it to Incus/provisioning E2E only when the behavior depends on
real VM, network, OS, or installer semantics.

## Docker-First Runtime Expectations

Prepared Docker topologies model the target runtime contract:

- topology host images use an Ubuntu apt substrate so host prerequisite
  packages mirror the installer path used on managed Ubuntu nodes;
- topology host images do not bake an Orbit source tree. Docker topology
  preparation streams the current checkout into `/home/orbit/orbit` on the
  operator node and `/home/orbit/orbit` on gateway, app, ingress, and agent
  nodes, then points `/usr/local/bin/orbit` directly at the source CLI entry
  point, `~/orbit/apps/cli/orbit`;
- Docker topology preparation installs Composer dependencies per role: gateway
  nodes use `apps/gateway` when that app exists and otherwise the current root
  app until the gateway relocation lands; non-gateway operator, workload,
  ingress, and agent nodes install `apps/cli`;
- after the gateway API is reachable, topology preparation writes the prepared
  gateway HTTP endpoint (`http://gateway` in DNS-alias mode, otherwise
  `http://10.6.0.2`) into `~/.config/orbit/config.json` on non-gateway nodes
  (the `OrbitConfigStore` JSON layer); the source CLI entry point reads gateway
  config from that path, with process `env` overlaid on top; it does not read a
  source-tree `.env`;
- host `orbit` command -> node-local Orbit CLI entry point (every node role) ->
  gateway `orbit-caddy` -> gateway `orbit-runtime`; gateway maintenance
  bypasses the public launcher and uses `bin/orbit-gateway-artisan` or direct
  `php apps/gateway/artisan` from a controlled gateway shell;
- app and workspace PHP runtimes are FrankenPHP containers;
- PHP app and workspace process units use Docker process runtime containers by
  default;
- service dependencies, including WebSocket and S3-compatible services, run as
  Docker sibling containers via the host Docker socket;
- production Orbit CLI artifacts carry their own embedded PHP runtime;
  source-mounted topologies execute `apps/cli/orbit` from the mounted checkout;
  host Composer, host Caddy, PHP-FPM, and host Supervisor for PHP app processes
  are intentionally absent.

Public production HTTP tests must preserve the landed ingress contract:
`ingress -> router -> backend`. Downstream WebSocket and S3 topology support
must use the same Docker sibling-container substrate rather than adding
host-native runtime fallbacks.
