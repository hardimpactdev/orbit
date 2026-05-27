# Incus E2E

Incus VM-feature tests use `e2e-feature`, carry the `e2e-provider-incus` group,
and require VM capabilities:

```php
E2ETopologyFactory::fromEnvironment()
    ->requireCapabilities(E2ETopologyCapabilities::vm());
```

Use this lane for prepared-topology tests that need real VM semantics but do not
rebuild topology images and do not run provisioning:

```bash
composer test:e2e:incus
```

When Incus is unavailable or the required prepared topology is missing, the test
should catch `E2ETopologyUnavailable` and call `markTestSkipped()`. That makes
`composer test:e2e` usable on Docker-only hosts.

Do not put provisioning tests in `e2e-provider-incus`. Provisioning tests stay in
`e2e-provision` and run only through `composer test:e2e:provision`.

## Resource budgets

Provisioning and topology clones use independent resource budgets. Image
preparation and provisioning E2E keep `ORBIT_E2E_CPUS=2` because installer work
is CPU- and package-manager-bound. Topology feature clones default to 1 vCPU
because the work is mostly SSH, SQLite, command execution, small API calls, and
readiness polling.

Prepared Incus feature tests do not use `ORBIT_E2E_INCUS_HOST_SLOTS`.
`ORBIT_E2E_INCUS_HOST_SLOTS` is for provisioning/image-prep leases, where a test
mutates Incus host state by creating new base VMs. Prepared feature
tests clone existing topology snapshots and choose a host through
`ORBIT_E2E_INCUS_HOSTS` plus `ORBIT_E2E_INCUS_HOST_VM_CAPS`.

Direct Pest/artisan runs that bypass `php artisan e2e:test` do not get lane caps
automatically. Their concurrency is bounded by:

- `ORBIT_E2E_INCUS_PARALLEL_PROCESSES`, the requested Pest worker count for the
  Incus lane.
- `ORBIT_E2E_INCUS_HOST_VM_CAPS`, the maximum number of Orbit-owned
  prepared-topology VMs allowed on an Incus host.
- The cached topology size selected by the lane.

For example, `ORBIT_E2E_INCUS_PARALLEL_PROCESSES=3`,
`ORBIT_E2E_INCUS_HOST_VM_CAPS=beast:12`, and `ORBIT_E2E_TOPOLOGY_CPUS=1` request
three Incus workers. If the largest selected Incus topology has three roles, all
three workers fit within the 12 VM cap. If the largest selected topology has
five roles, the lane caps to two workers.

## Recommended local baseline

Use this baseline for the local Beast-backed Incus lane.

```bash
ORBIT_E2E_INCUS_HOSTS=beast
ORBIT_E2E_INCUS_HOST_SLOTS=beast:1
ORBIT_E2E_INCUS_STORAGE_POOL=orbit-e2e
ORBIT_E2E_INCUS_HOST_VM_CAPS=beast:12
ORBIT_E2E_INCUS_PARALLEL_PROCESSES=3
ORBIT_E2E_TOPOLOGY_CPUS=1
ORBIT_E2E_EXCLUSIVE_HOSTS=beast
```

`ORBIT_E2E_INCUS_HOST_VM_CAPS` is enforced by the host pool. When a feature test
asks for a topology, the pool walks configured hosts and picks the first one that
has both the prepared templates and enough free Orbit-owned slots. User-owned VMs
are ignored; only instances whose name starts with `ORBIT_E2E_INSTANCE_PREFIX`
are counted.

## Storage pool

`ORBIT_E2E_INCUS_STORAGE_POOL` is optional. Leave it empty to use each host's
Incus default pool. Set it when a host has a faster CoW-capable pool, for
example a dedicated ZFS-backed `orbit-e2e` pool.

When this value is set, prepared templates must be built on the same pool.
Verify with:

```bash
ssh beast 'for name in orbit-template-operator-base orbit-template-gateway-base orbit-template-app-dev-base orbit-template-app-prod-base orbit-template-agent-base; do echo "--- $name"; incus config show "$name" --expanded | sed -n "/root:/,/^[^ ]/p" | head -n 4; done'
```

Every listed template should show `pool: orbit-e2e`. If templates show
`pool: default` while `ORBIT_E2E_INCUS_STORAGE_POOL=orbit-e2e`, rebuild the full
prepared topology before trusting Incus feature-lane timings:

```bash
composer e2e:prepare-topology -- --force operator_gateway_app-dev_app-prod_agent
```

Branch-specific Incus artifacts use the same role suffix model as Docker:
`orbit-template-<role>-<slug>` plus `clean-<source-topology>-<slug>`. Missing
branch role artifacts fall back to the matching `base` template and snapshot, so
a branch override does not need to rebuild every role.

Incus acquisition already resolves branch/base artifacts per role. Forced Incus
preparation currently supports the shared `base` rebuild and explicit full
namespaced rebuilds with `--all-roles`. Targeted `--roles=<role>` rebakes are
guarded until the builder can refresh only the selected VMs from the base source
snapshot.
A custom namespace without `--roles` or `--all-roles` is rejected.

The regression signature for a storage-pool mismatch is
`ORBIT_E2E_TIMINGS=1 composer test:e2e:incus` reporting `batch.copy-start` near
100s per worker. The expected local Beast value after a healthy rebuild on
`orbit-e2e` is about 2s per worker.
