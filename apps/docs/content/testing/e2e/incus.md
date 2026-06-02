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

Use the Incus provider provision gate when base image shape, installer behavior,
gateway provisioning, `node:new`, WireGuard, VM boot, package installation, or
host mutation may have changed:

```bash
composer test:e2e:provision:incus
```

After that gate passes, refresh the shared prepared Incus artifacts before
running feature tests against the new topology shape:

```bash
composer e2e:prepare-topology -- --force operator_gateway_app-dev_app-prod_agent_websocket
composer test:e2e:incus
```

Retained/live Incus development topologies acquired with
`composer e2e:incus -- --start` or `composer e2e:incus -- --live` are
source-mounted at `/home/orbit/orbit` instead of unpacking a checkout archive.
Those flows add an Incus `orbit-source` disk before boot, point
`/usr/local/bin/orbit` at `/home/orbit/orbit/apps/cli/orbit`, and keep mutable
gateway/runtime state under `/home/orbit/.config/orbit`. Ordinary
`composer test:e2e:incus` prepared acquisitions still use prepared artifacts
and do not mount the local checkout.

When `ORBIT_E2E_INCUS_WARM_SNAPSHOTS=1` is enabled, ordinary prepared feature
acquisitions may still restore warm snapshots. Source-mounted retained/live
acquisitions bypass the warm pool and take a fresh source-mounted clone so the
local checkout mount is always present for that run.

When Incus is unavailable or the required prepared topology is missing, the
selected lane fails before Pest workers start and includes a scoped
`composer e2e:ensure-artifacts` command for the missing role set. Use
`composer test:e2e:docker` when intentionally checking only Docker feature
coverage.

Do not put provisioning tests in `e2e-provider-incus`. Provisioning tests stay in
`e2e-provision` and run through `composer test:e2e:provision:incus`.
`composer test:e2e:provision` is a human-only aggregate alias and must not be
used by agents.

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

For example, `ORBIT_E2E_INCUS_PARALLEL_PROCESSES=4`,
`ORBIT_E2E_INCUS_HOST_VM_CAPS=beast:16`, and `ORBIT_E2E_TOPOLOGY_CPUS=1` request
four Incus workers. Each topology acquisition leases the VM capacity it needs
for its selected roles. If later tests need eight VMs, they can run when eight
host slots are free without lowering the whole lane's Pest worker count.

## Warm stateful snapshots

Incus feature tests can optionally use stateful warm snapshots to avoid the
first cold VM boot during topology acquisition. Warm snapshots are prepared
artifacts, not test-time provisioning:

```bash
composer e2e:prepare-warm-topology -- --force operator_gateway_agent
```

Enable warm acquisition explicitly:

```bash
ORBIT_E2E_INCUS_WARM_SNAPSHOTS=1
ORBIT_E2E_INCUS_WARM_SNAPSHOT_SLOTS=1
composer test:e2e:incus
```

`ORBIT_E2E_INCUS_WARM_SNAPSHOT_SLOTS` is the maximum warm slots inspected per
topology. If several selected tests use the same topology, prepare enough slots
for the desired same-topology concurrency:

```bash
composer e2e:prepare-warm-topology -- --force --slots=3 operator_gateway_app-dev
ORBIT_E2E_INCUS_WARM_SNAPSHOTS=1 ORBIT_E2E_INCUS_WARM_SNAPSHOT_SLOTS=3 composer test:e2e:incus
```

Warm slots are persistent Incus VMs named from the topology and artifact set.
The preparation command clones the cold prepared topology, boots and retargets
it, starts the gateway API, takes a stateful `warm-ready` snapshot, then leaves
the slot stopped. A feature test leases a warm slot, restores the stateful
snapshot, verifies agent/SSH/API readiness, and restores the same snapshot again
when `E2ETopologyLease::reset()` is called. Releasing the lease stops the warm
slot so the next acquisition can restore it from `warm-ready`.

When `ORBIT_E2E_INCUS_WARM_SNAPSHOTS=1`, `composer test:e2e:incus` fails before
Pest if a selected topology is missing warm snapshots and prints the exact
`composer e2e:prepare-warm-topology` command to run. The feature lane never
creates warm snapshots itself.

## Recommended local baseline

Use this baseline for the local Beast-backed Incus lane.

```bash
ORBIT_E2E_INCUS_HOSTS=beast
ORBIT_E2E_INCUS_HOST_SLOTS=beast:1
ORBIT_E2E_INCUS_STORAGE_POOL=orbit-e2e
ORBIT_E2E_INCUS_HOST_VM_CAPS=beast:16
ORBIT_E2E_INCUS_PARALLEL_PROCESSES=4
ORBIT_E2E_INCUS_WARM_SNAPSHOTS=0
ORBIT_E2E_INCUS_WARM_SNAPSHOT_SLOTS=1
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
composer e2e:prepare-topology -- --force operator_gateway_app-dev_app-prod_agent_websocket
```

Branch-specific Incus artifacts use the same role suffix model as Docker:
`orbit-template-<role>-<slug>` plus `clean-<source-topology>-<slug>`. Missing
branch role artifacts fall back to the matching `base` template and snapshot, so
a branch override does not need to rebuild every role.

Incus acquisition resolves branch/base artifacts per role. Forced Incus
preparation supports the shared `base` rebuild, explicit full namespaced
rebuilds with `--all-roles`, and targeted selected-role rebakes with `--roles`.
A custom namespace without `--roles` or `--all-roles` is rejected.

For a targeted `--roles` rebake, the builder copies each selected role from its
base source snapshot into the slug namespace, starts the VM, overlays the
current checkout bundle, stops the VM, and takes a fresh
`clean-<source-topology>-<slug>` snapshot. Unselected roles remain absent in the
slug namespace and fall back to the matching `base` artifacts during acquisition.

Gateway consistency is enforced: `gateway` and `operator` must always be
selected together because they share CA trust and WireGuard contracts. Selecting
one without the other is rejected with a clear error.

Use the artifact ensure command to inspect targeted Incus role templates before
rebuilding:

```bash
ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE=agent-isolation \
composer e2e:ensure-artifacts -- --lanes=incus --roles=agent operator_gateway_agent
```

The regression signature for a storage-pool mismatch is
`ORBIT_E2E_TIMINGS=1 composer test:e2e:incus` reporting `batch.copy-start` near
100s per worker. The expected local Beast value after a healthy rebuild on
`orbit-e2e` is about 2s per worker.
