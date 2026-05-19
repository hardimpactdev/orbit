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
topology but not fresh host provisioning:

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
composer e2e:prepare-docker-topology
composer e2e:prepare-docker-hosts
composer e2e:prepare-base-image
composer e2e:reap-incus
composer e2e:reap-docker
composer e2e:reap-hcloud
```

## Porting Rule

Do not reintroduce standing live-node smoke tests as a default verification
lane. Persistent nodes are operational infrastructure, not a test fixture.

When porting an old behavior, start with in-memory Pest coverage. Move the test
to Docker-backed feature E2E only when the prepared topology is part of the
contract. Move it to Incus/provisioning E2E only when the behavior depends on
real VM, network, OS, or installer semantics.
