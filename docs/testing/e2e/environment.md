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
ORBIT_E2E_DOCKER_IMAGE_BUILD_HOSTS=beast
ORBIT_E2E_INCUS_HOSTS=beast
ORBIT_E2E_INCUS_HOST_SLOTS=beast:1
ORBIT_E2E_INCUS_HOST_VM_CAPS=beast:12
ORBIT_E2E_INCUS_PARALLEL_PROCESSES=3
ORBIT_E2E_PROVISION_PARALLEL_PROCESSES=1
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

`composer test:e2e` runs `php artisan e2e:test`, which reads `ORBIT_E2E_LANES`
and starts selected prepared-topology lanes concurrently. The lane aliases set
`ORBIT_E2E_LANES` before invoking the same orchestrator:
`composer test:e2e:docker` selects `docker`, and `composer test:e2e:incus`
selects `incus`.

`composer test:e2e:provision` runs serially when
`ORBIT_E2E_PROVISION_PARALLEL_PROCESSES=1`. Set the value above `1` to enable
Pest parallel mode for provision tests; every worker still acquires a
provisioning lease before creating Incus resources.

## Lease namespaces

`composer test:e2e:docker` and `composer test:e2e:provision` use separate lease
namespaces in the same shared lease directory. Docker feature tests read
`ORBIT_E2E_DOCKER_TEST_RUNNERS`; Incus provisioning tests read
`ORBIT_E2E_INCUS_HOST_SLOTS`.

By default those namespaces do not block each other. Add a host to
`ORBIT_E2E_EXCLUSIVE_HOSTS` when the same machine appears in more than one
backend pool and the backend families must not overlap.
