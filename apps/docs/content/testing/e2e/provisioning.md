# E2E provisioning

Use E2E backed by a VM only when the behavior depends on real provisioning,
WireGuard, VM networking, OS trust-store mutation, systemd, package
installation, cloud-init, or host-level daemon behavior.

```bash
composer e2e:preflight
composer e2e:prepare-base-image -- --force
composer e2e:prepare-topology -- --force operator_gateway_app-dev_app-prod_agent_websocket
composer test:e2e
composer test:e2e:provision:docker
composer test:e2e:provision:incus
```

Use provider-specific provision commands only when topology, installer, image,
`node:new`, WireGuard provisioning, or other provider setup behavior changes.
Ordinary command ports should add feature tests that use prepared topologies
instead.

Run feature E2E before provision gates. The topology preparer loads the current
source checkout into prepared Docker/Incus artifacts, so `composer test:e2e`
proves the feature against source-prepared topologies. Provider provision
commands are the last verification gate for fresh installation, binary/image
asset preparation, and host mutation. When production artifact behavior matters,
the ideal final pass is source-prepared feature E2E, provider provision, then an
artifact-backed feature flow using the built CLI and gateway image when that
lane exists.

`composer test:e2e:provision:docker` refreshes the Docker runtime/support images
and prepared role images. `composer test:e2e:provision:incus` runs the fresh VM
provision gate. They can run in parallel because Docker and Incus mutate
separate provider substrates.

`composer test:e2e:provision` is a human-only aggregate alias for both provider
provision commands. Agents must never run the aggregate.

The Incus provision gate has one supported shape:

1. Launch a fresh VM from `orbit-base-ubuntu-26.04`.
2. Install Orbit from the current source bundle on the operator.
3. Provision the gateway through the real gateway path.
4. Run `node:new` for app-dev, app-prod, and agent in parallel.
5. Bake websocket against app-dev Redis and converge its Reverb runtime.
6. Snapshot the resulting role templates inside the isolated provision-test
   namespace for validation and failure inspection.

Before running the Incus feature lane, refresh the shared Incus prepared
topology pool when the current source or topology shape changed:

```bash
composer e2e:prepare-topology -- --force operator_gateway_app-dev_app-prod_agent_websocket
composer test:e2e:incus
```

Run `composer test:e2e:provision:incus` after that source-prepared feature lane
when the change also needs fresh VM provision verification.

Command contracts use `e2e-feature` or in-memory Pest coverage when prepared
topology state is enough to prove the behavior.

## Incus image model

The VM E2E harness uses Incus VMs on the configured E2E host (`beast` by
default). It builds one reusable base image plus prepared source snapshots:

1. Base image `orbit-base-ubuntu-26.04`. Built once via
   `composer e2e:prepare-base-image -- --force`. It contains Ubuntu cloud, the
   bootstrap user, the `orbit` user, sshd, the E2E OS dependency set, and the
   PHP 8.5 CLI baseline used by Orbit itself. It does not contain Orbit source.
   It is used by the Incus provision gate and as the source for prepared
   topology roles.
2. Prepared source templates `orbit-template-operator-base`,
   `orbit-template-gateway-base`, `orbit-template-app-dev-base`,
   `orbit-template-app-prod-base`, `orbit-template-agent-base`, and
   `orbit-template-websocket-base`. Build them with
   `composer e2e:prepare-topology -- --force operator_gateway_app-dev_app-prod_agent_websocket`.

During topology preparation, Orbit tars the current checkout, ships it plus
`bin/install-orbit` and `bin/e2e-provision-node` to the host, installs Orbit on
the operator template from the base image, then provisions the gateway through
real `node:new`. After the gateway is seeded, app-dev, app-prod, and agent are
provisioned in parallel; app-dev then seeds database and Redis registry state,
and the websocket role is baked with its Reverb runtime baseline before the
full source snapshot is taken. Feature tests clone only their requested roles
from that full prepared source.

App-dev carries database and Redis state by default. App-prod carries the
ingress role by default. Websocket carries the Reverb runtime baseline and uses
app-dev Redis.

The shared prepared Incus artifact set is `base`: role templates are named
`orbit-template-<role>-base`, and source snapshots are named
`clean-<source-topology>-base`. Set
`ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE=<slug>` only when preparing branch or
worktree-specific role artifacts. Incus feature acquisition resolves each role
independently by trying `orbit-template-<role>-<slug>` with
`clean-<source-topology>-<slug>` first, then falling back to the matching
`base` template and snapshot for that role.
Custom namespace preparation without `--roles` or `--all-roles` is rejected.
Targeted `--roles` rebakes copy each selected role from its base source snapshot
into the slug namespace, overlay the current checkout bundle, and retake the
`clean-<source-topology>-<slug>` snapshot. Unselected roles remain absent and
fall back to `base` during acquisition. Gateway and operator must be selected
together because they share CA trust and WireGuard contracts.

## Source bundle and archives

Source code lives in the per-run bundle, not in the base image. Forced topology
preparation rebuilds the canonical full prepared source from the base image.
Rebuild the base image only when the base image shape changes.

The provisioning bundle stages host-local `orbit-gateway:current`,
`caddy:2-alpine`, `4km3/dnsmasq:latest`, and
`dunglas/frankenphp:1-php8.5-bookworm` Docker image archives when those images
exist on the Incus host. `bin/install-orbit` loads those archives before
falling back to Docker Hub and marks archive-seeded installs with
`ORBIT_FORWARD_INSTALL_IMAGE_ARCHIVES=1` so `node:new` can forward the same
local runtime images to freshly provisioned gateway and app nodes.

Forwarded archives and source bundles are staged under `/var/tmp` rather than
`/tmp`; Ubuntu cloud VMs often mount `/tmp` as a small tmpfs that cannot hold
Docker image archives.

## SSH requirements

Every machine that runs the Incus lane must reach the configured Incus host with
ordinary non-interactive SSH and SCP. The harness runs Incus commands over
`ssh beast ...` and copies the current checkout archive with
`scp ... beast:/tmp/...`; both paths intentionally use the same SSH host alias
and may use keys from the local SSH agent.

If `ssh -o BatchMode=yes beast true` works but checkout copy fails with
`Permission denied (publickey)`, check local SSH options before changing E2E lane
selection.

## Source overrides

Use these flags when the prepared topology should source code from something
other than the current worktree.

```bash
composer e2e:prepare-topology -- --force <kind> --branch=<ref>
composer e2e:prepare-topology -- --force <kind> --source-archive=<path>
composer e2e:prepare-topology -- --force <kind> --composer-cache=<dir>
```

Without `--composer-cache`, `~/.cache/orbit-e2e/composer` is bundled when
present. A warm cache cuts `bin/install-orbit` runtime inside each role clone.

Forced topology preparation prints live phase checkpoints to STDERR with the
`[orbit-e2e]` prefix. Each measured phase emits `started`, then
`done <seconds>` or `failed <seconds> <exception>`. JSON responses stay on
STDOUT.

## Provision failure behavior

The provision gate cleans up on success. On failure it keeps tracked
VMs/templates for inspection and prints their names plus a reap command. Set
`ORBIT_E2E_KEEP_ON_FAILURE=0` to restore cleanup-on-failure behavior.

## Image environment

Use these defaults for local Incus image preparation unless a branch explicitly
changes the image shape.

```bash
ORBIT_E2E_HOST=beast
ORBIT_E2E_INCUS_IMAGE_BUILD_HOST=beast
ORBIT_E2E_SOURCE_IMAGE=images:ubuntu/26.04/cloud
ORBIT_E2E_BASE_IMAGE=orbit-base-ubuntu-26.04
ORBIT_E2E_BOOTSTRAP_USER=provisioner
ORBIT_E2E_OPERATOR_USER=orbit
ORBIT_E2E_CONTROL_USER=orbit # Operator user alias.
ORBIT_E2E_INSTANCE_PREFIX=orbit-e2e
ORBIT_E2E_TIMEOUT_SECONDS=600
ORBIT_E2E_KEEP=1
```
