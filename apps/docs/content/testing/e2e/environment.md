# E2E environment

The Composer E2E scripts source `.env.e2e` when that file exists, then apply the
lane-specific defaults shown in `composer.json`. Copy `.env.e2e.example` to
`.env.e2e` in the main checkout for local machine pool settings. Worktrees
should symlink their `.env.e2e` back to the main checkout so every worktree uses
the same slot configuration.

```bash
ORBIT_E2E=1                           # Enable ephemeral E2E tests
ORBIT_E2E_LANES=docker,incus          # composer test:e2e lane set: docker, incus, docker,incus, or all
ORBIT_E2E_PROVIDER=incus              # Backend provider
ORBIT_E2E_PROVIDERS=incus             # Ordered provisioning provider pool
ORBIT_E2E_TOPOLOGY_PROVIDER=docker    # Prepared topology provider for direct artisan/Pest runs
ORBIT_E2E_TOPOLOGY_PROVIDERS=docker   # Ordered prepared topology provider pool
ORBIT_E2E_GATEWAY_API=1               # Start gateway API/10.6 routes for tests that need it
ORBIT_E2E_DOCKER_TEST_RUNNERS=sidecar1:4:28,sidecar2:4:28
ORBIT_E2E_DOCKER_SOURCE_PATH=              # Optional remote Docker source-sync target override
ORBIT_E2E_DOCKER_SOURCE_PATH_SIDECAR1=     # Optional per-host Docker source-sync target override
ORBIT_E2E_DOCKER_IMAGE_BUILD_HOSTS=beast
ORBIT_E2E_INCUS_HOSTS=beast
ORBIT_E2E_INCUS_SOURCE_PATH=               # Optional remote Incus source-sync target override
ORBIT_E2E_INCUS_SOURCE_PATH_BEAST=         # Optional per-host Incus source-sync target override
ORBIT_E2E_INCUS_HOST_SLOTS=beast:1
ORBIT_E2E_INCUS_HOST_VM_CAPS=beast:16
ORBIT_E2E_INCUS_PARALLEL_PROCESSES=4
ORBIT_E2E_LIVE_WIREGUARD_ENDPOINT=192.168.1.150:51820 # composer e2e:incus -- --live client endpoint
ORBIT_E2E_INCUS_WARM_SNAPSHOTS=0
ORBIT_E2E_INCUS_WARM_SNAPSHOT_SLOTS=1
ORBIT_E2E_EXCLUSIVE_HOSTS=beast
ORBIT_E2E_SLOT_WAIT_SECONDS=900
ORBIT_E2E_SLOT_STALE_SECONDS=7200
ORBIT_E2E_LEASE_DIRECTORY=
ORBIT_E2E_INCUS_IMAGE_BUILD_HOST=beast
ORBIT_E2E_INCUS_STORAGE_POOL=orbit-e2e
ORBIT_E2E_TOPOLOGY_CACHE=process
ORBIT_E2E_CHECKOUT_CACHE=process
ORBIT_E2E_CHECKOUT_ARCHIVE_CACHE_DIR=
ORBIT_E2E_DOCKER_PARALLEL_STARTS=0
ORBIT_E2E_TOPOLOGY_RESET=fresh-clone
ORBIT_E2E_TIMINGS=1
ORBIT_E2E_CPUS=2
ORBIT_E2E_MEMORY=2GiB
ORBIT_E2E_TOPOLOGY_CPUS=1
ORBIT_E2E_TOPOLOGY_MEMORY=2GiB
ORBIT_E2E_TOPOLOGY_ROOT_SIZE=16GiB
ORBIT_E2E_TOPOLOGY_STATE_SIZE=4GiB
ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE=
```

## Provider variables

`ORBIT_E2E_PROVIDER` and `ORBIT_E2E_PROVIDERS` choose provisioning providers.
`ORBIT_E2E_TOPOLOGY_PROVIDER` and `ORBIT_E2E_TOPOLOGY_PROVIDERS` choose prepared
topology providers for `e2e-feature` tests. Keep provisioning provider selection
VM-backed when a test proves machine setup, SSH, sudo, package installation,
trust-store mutation, or system services.

`ORBIT_E2E_PROVIDER=auto` expands to Incus only. Provisioning providers support
Incus only.

## Lane variables

`composer test:e2e` runs `bin/orbit-gateway-artisan e2e:test`, which reads `ORBIT_E2E_LANES`
and starts selected prepared-topology lanes concurrently. The lane aliases set
`ORBIT_E2E_LANES` before invoking the same orchestrator:
`composer test:e2e:docker` selects `docker`, and `composer test:e2e:incus`
selects `incus`.

Each selected lane is mandatory. `composer test:e2e` defaults to `docker,incus`
and fails before Pest workers start when either provider is missing required
prepared artifacts, runner capacity, or reachable hosts. Missing artifacts print
a scoped `composer e2e:ensure-artifacts` command; the test lanes themselves
never run preparation. Use `composer test:e2e:docker` or
`composer test:e2e:incus` when intentionally checking only one
prepared-topology provider.

`composer test:e2e:provision` runs the single superset provisioning test. It
launches the base VM, installs Orbit, provisions the gateway, and internally
provisions app-dev, app-prod, and agent in parallel.

## Lease namespaces

`composer test:e2e:docker` and Incus image/topology preparation use separate
lease namespaces in the same shared lease directory. Docker feature tests read
`ORBIT_E2E_DOCKER_TEST_RUNNERS`; Incus image-preparation commands read
`ORBIT_E2E_INCUS_HOST_SLOTS`.

By default those namespaces do not block each other. Add a host to
`ORBIT_E2E_EXCLUSIVE_HOSTS` when the same machine appears in more than one
backend pool and the backend families must not overlap.

## Live Incus endpoint

`ORBIT_E2E_LIVE_WIREGUARD_ENDPOINT` is only used by
`composer e2e:incus -- --live`. It is the endpoint written into the local
WireGuard client config after minting an operator identity. The live command
uses that config to start a disposable local `wg-quick` tunnel unless `--manual`
is passed.

For a trusted LAN Incus host reachable at `192.168.1.150`, set:

```bash
ORBIT_E2E_LIVE_WIREGUARD_ENDPOINT=192.168.1.150:51820
```

Root Composer E2E scripts source the repository-level `.env.e2e`; that file is
not converted into `apps/e2e/.env`. When running the `apps/e2e` command directly,
either export the variable in the shell or put it in `apps/e2e/.env`.

Retained/live Incus acquisitions also source-mount the initiating worktree at
`/home/orbit/orbit`. Remote Incus hosts rsync the current worktree to
`/tmp/orbit-e2e-sources/<worktree>-<hash>` before acquisition and then mount that
synced path. Set `ORBIT_E2E_INCUS_SOURCE_PATH` only when a host needs a fixed
target path, or use `ORBIT_E2E_INCUS_SOURCE_PATH_<HOST>` for a host-specific
override.

## Artifact namespace

Leave `ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE` empty for normal prepared
topology feature tests. Docker then uses the shared role images
`orbit-e2e:<role>_base`; Incus uses shared role templates
`orbit-template-<role>-base` and snapshots
`clean-<source-topology>-base`.

Set it to a branch or worktree slug only when that branch has role-specific
prepared artifacts. With `ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE=agent-isolation`,
the Docker provider tries `orbit-e2e:agent_agent-isolation` for the agent role
and falls back to `orbit-e2e:agent_base` when that override is absent. The same
per-role fallback applies to operator, gateway, app-dev, and app-prod. Incus
uses the same rule with template and snapshot suffixes: it tries
`orbit-template-agent-agent-isolation` plus
`clean-<source-topology>-agent-isolation`, then falls back to
`orbit-template-agent-base` plus `clean-<source-topology>-base` for that role.

Preparing artifacts while this variable is set requires an explicit scope. Use
`composer e2e:ensure-artifacts -- --roles=<comma-separated roles>` for Docker
role-image overrides or `--all-roles` for an intentional full namespaced
rebuild. Incus targeted `--roles` preparation is guarded until selected-role
rebakes are implemented; Incus acquisition falls back per role when branch
artifacts are absent.
