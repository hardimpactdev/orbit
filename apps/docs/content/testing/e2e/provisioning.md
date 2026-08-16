# E2E provisioning

E2E backed by a VM is manual-only. Use retained topology proof for ordinary
feature verification when behavior depends on real provisioning, WireGuard, VM
networking, OS trust-store mutation, systemd, package installation, or
host-level daemon behavior.

```bash
composer e2e:preflight
composer e2e:prepare-base-image -- --force
composer e2e:prepare-topology -- --force operator_gateway_app-dev_app-prod_agent_websocket
composer test:e2e
composer test:e2e:provision:incus
```

Provision commands for a specific provider are user-run commands for topology,
installer, image, `node:new`, WireGuard provisioning, or other provider setup
behavior. Ordinary command ports use focused Pest plus retained topology proof.

When the user manually runs E2E, feature E2E should precede provision gates.
The topology preparer loads the current source checkout into prepared
Docker/Incus artifacts, so `composer test:e2e` proves behavior against
topologies prepared from source. Incus prepared topology builds sync the
initiating worktree to the Incus host, bind-mount that synced copy into each VM,
mirror it onto the VM ext4 filesystem, and snapshot the mirrored runtime. Incus
provision is the last manual verification gate for fresh installation,
`node:new`, VM boot, WireGuard, systemd, package installation, and host
mutation.

Docker provision is not part of the ordinary post-`composer test:e2e` sequence;
the user runs it only when Docker runtime/support images, prepared role images,
Docker host artifact distribution, or Docker topology-preparation behavior
changed. When production artifact behavior matters and the user chooses manual
E2E, the final pass starts with feature E2E against a source-prepared topology.
Then run the affected provider artifact/provision gate and the artifact-backed
feature flow when that lane exists.

`composer test:e2e:provision:docker` rebuilds and distributes the Docker
runtime/support images and prepared role images. `composer
test:e2e:provision:incus` runs the fresh VM provision gate. They can run in
parallel only when both are independently required because Docker and Incus
mutate separate provider substrates.

`composer test:e2e:provision` is a human-only aggregate alias for both provider
provision commands. Agents must never run the aggregate or provider-specific
`composer test:e2e*` commands.

The Incus provision gate has one supported shape:

1. Launch a fresh VM from `orbit-base-ubuntu-26.04-runtime`.
2. Install Orbit from the synced source checkout on the operator.
3. Provision the gateway through the real gateway path.
4. After the gateway is ready, use the configured operator client to start
   app-dev, app-prod, and agent bootstrap in parallel. For each downstream role,
   the operator opens the client-local SSH edge, streams the gateway-authored
   minimal bundle, waits for Agent readiness, and lets the gateway complete
   convergence through Agent push.
5. Bake websocket against app-dev Valkey as soon as the app-dev role succeeds,
   app-dev runtime services are ready, and the provisioning-owned
   gateway/app-dev WireGuard route is ready. Websocket does not wait for
   app-prod or agent unless a future contract adds a real dependency.
6. Snapshot operator, gateway, app-dev, app-prod, and agent role templates
   inside the isolated provision-test namespace for validation and failure
   inspection.

Serving assertions are feature behavior, not provision-gate behavior. `app:new`,
Caddy/FrankenPHP serving, and `composer install` run on the host from prepared
Incus feature coverage against `operator_gateway_app-dev`, which boots only
operator, gateway, and app-dev while the Incus provider uses the source
snapshot from the websocket-capable superset topology as its base. The default
Incus provision gate runs only the build/validation path for that
websocket-capable superset.

Before the user runs an Incus feature lane, refresh the shared Incus prepared
topology pool when the current source or topology shape changed:

```bash
composer e2e:prepare-topology -- --force operator_gateway_app-dev_app-prod_agent_websocket
composer test:e2e:incus
```

For a manual full Incus proof, `composer test:e2e:provision:incus` follows that
source-prepared feature lane when the change also needs fresh VM provision
verification.

Command contracts use `e2e-feature` or in-memory Pest coverage when prepared
topology state is enough to prove the behavior.

## Incus image model

The VM E2E harness uses Incus VMs on the configured E2E host (`beast` by
default). It builds one reusable base image plus prepared source snapshots:

1. Base image `orbit-base-ubuntu-26.04-runtime`. Built via
   `composer e2e:prepare-base-image -- --force` from the non-cloud Ubuntu 26.04
   VM image through direct Incus-agent bootstrap. It contains the bootstrap
   user, the `orbit` user, sshd, the E2E OS dependency set, WireGuard, Docker
   Engine, Docker Swarm initialized on first boot, PHP CLI, and
   Composer. It also preloads the Caddy, FrankenPHP, and wg-easy Docker images
   that topologies prepared from source require. It does not contain Orbit
   source. It
   is used by the Incus provision gate and as the source for prepared topology
   roles.
2. Prepared source templates `orbit-template-operator-base`,
   `orbit-template-gateway-base`, `orbit-template-app-dev-base`,
   `orbit-template-app-prod-base`, `orbit-template-agent-base`, and
   `orbit-template-websocket-base`. Build them with
   `composer e2e:prepare-topology -- --force operator_gateway_app-dev_app-prod_agent_websocket`.

During topology preparation, Orbit syncs the current checkout to the Incus host,
launches each VM from `orbit-base-ubuntu-26.04-runtime`, attaches the synced
checkout as a temporary Incus disk, mirrors it onto the VM ext4 filesystem, and
links `/usr/local/bin/orbit` to the mirrored CLI shim. The operator mirrors into
`/home/operator/orbit`; gateway and managed roles mirror into
`/home/orbit/orbit`. Gateway-local artisan commands run from the mirrored
`apps/gateway` path through the FrankenPHP PHP image.

After the gateway is seeded, the prepared full topology uses the explicit role
DAG `operator -> gateway -> {dev, prod, agent}`. Dev, prod, and agent launch and
client-bootstrap tasks run independently from the operator; post-readiness bake
commands are gateway-authored Agent-push convergence. In the websocket-capable
topology, websocket is a dev-dependent task. It starts after app-dev is baked,
app-dev Docker, Caddy, FrankenPHP, and Valkey services are ready, and the
provisioning-owned gateway/app-dev WireGuard route is ready. It does not wait
for app-prod or agent completion. After websocket completes, the development
app node seeds database and Valkey registry state before the full source snapshot
is taken.

The prepared base image's preloaded Docker, Swarm, PHP, Composer, and container
images are harness-only fixture acceleration. They do not redefine the public
`node:new` bootstrap contract or authorize gateway-to-target SSH.

Feature tests clone only their requested roles from that full prepared source.

App-dev carries database, Valkey, Caddy, and FrankenPHP app-serving readiness by
default. `app-prod` carries the ingress role by default. Websocket carries the
Reverb runtime baseline and uses app-dev Valkey.

The shared prepared Incus artifact set is `base`: role templates are named
`orbit-template-<role>-base`, and source snapshots are named
`clean-<source-topology>-base`. Set
`ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE=<slug>` only when preparing branch or
worktree-specific role artifacts. Incus feature acquisition resolves each role
independently by trying `orbit-template-<role>-<slug>` with
`clean-<source-topology>-<slug>` first, then falling back to the matching
`base` template and snapshot for that role.

Custom namespace preparation without `--roles` or `--all-roles` is rejected.
Targeted `--roles` rebakes are artifact-mode operations. They require
`--use-build-artifacts` and a non-base custom namespace: they copy each selected
role from its base source snapshot into the slug namespace, overlay the current
checkout bundle, and retake the `clean-<source-topology>-<slug>` snapshot.
Unselected roles remain absent and fall back to `base` during acquisition.
Gateway and operator must be selected together because they share CA trust and
WireGuard contracts. Leave the namespace empty and omit `--roles` to rebuild
the shared base Incus artifact set from the synced source checkout.

## Source sync and artifact bundles

Source code lives in the synced worktree on the Incus host, not in the base
image. Forced topology preparation rebuilds the canonical full prepared source
from the base image. Rebuild the base image only when the base image shape
changes.

Use `--use-build-artifacts` when the prepared topology should consume the native
CLI binary, packaged gateway runtime image, source archive, and forwarded Docker
image archives instead of the synced source checkout:

```bash
composer e2e:prepare-topology -- --force <kind> --use-build-artifacts
```

The provision fingerprint separates three input classes:

- CLI artifact inputs: the built Orbit CLI binary and source/build inputs needed
  to produce it (`apps/cli`, `packages/core`, root and CLI Composer manifests
  and lockfiles, and CLI runtime configuration). Generated CLI build output
  under `apps/cli/build/` and `apps/cli/builds/` is excluded. The CLI artifact
  is consumed by operator, gateway, app-dev, app-prod, agent, and
  websocket-capable prepared roles.
- Gateway artifact inputs: `docker/orbit-gateway/Dockerfile.inputs` is the
  Dockerfile-adjacent authority consumed by remote context staging and both
  gateway artifact fingerprint paths. Contract coverage keeps it equal to every
  host-context `COPY` source in the gateway Dockerfile. These inputs apply to
  the gateway role and to downstream roles whose prepared state depends on
  gateway database registration.
- Provision support inputs: `bin/install-orbit`, `bin/e2e-provision-node`,
  `bin/_e2e-deps.sh`, E2E topology builder/support code, command-shape code,
  topology kind/DAG, `ORBIT_E2E_*` environment values that affect topology
  shape, and base image identity.

Ordinary assertion-only files under `apps/e2e/tests/**` are not provision
fingerprint inputs. Changing a Pest assertion should rerun the assertion without
forcing a fresh provision rebuild. Changing provision support, the role DAG,
base image identity, CLI artifact inputs, or gateway artifact inputs invalidates
the affected checkpoints.

Runtime archive byte hashes are kept as diagnostic fingerprint metadata, but
role checkpoint validity depends on the stable source and build inputs that
produce those archives. Rebuilding the same CLI binary or Docker image archive
from unchanged source should not invalidate otherwise reusable VM checkpoints.

In default source mode, workload roles consume the mirrored checkout and do not
require the native CLI binary artifact or packaged gateway image. In
artifact mode, workload roles consume the CLI artifact and the gateway runtime
image; they do not rely on a full gateway source checkout as their
production-style artifact contract.

The provisioning bundle stages host-local `orbit-gateway:current`,
`orbit-reverb:current`, `caddy:2-alpine`, `4km3/dnsmasq:latest`, and
`ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm` Docker image archives when those images
exist on the Incus host. `bin/install-orbit` loads those archives before
falling back to Docker Hub and marks archive-seeded installs with
`ORBIT_FORWARD_INSTALL_IMAGE_ARCHIVES=1` so `node:new` can forward the same
local runtime images to freshly provisioned gateway and app nodes.

Forwarded archives and source bundles are staged under `/var/tmp` rather than
`/tmp`; VM images may mount `/tmp` as a small tmpfs that cannot hold Docker
image archives.

## SSH requirements

Every machine that runs the Incus lane must reach the configured Incus host with
ordinary non-interactive SSH and SCP. The harness runs Incus commands over
`ssh beast ...` and copies the current checkout archive with
`scp ... beast:/tmp/...`; both paths intentionally use the same SSH host alias
and may use keys from the local SSH agent.

If `ssh -o BatchMode=yes beast true` works but checkout copy fails with
`Permission denied (publickey)`, check local SSH options before changing E2E lane
selection.

## Artifact source overrides

Use these flags when the prepared topology should source code from something
other than the current worktree in artifact mode.

```bash
composer e2e:prepare-topology -- --force <kind> --use-build-artifacts --branch=<ref>
composer e2e:prepare-topology -- --force <kind> --use-build-artifacts --source-archive=<path>
composer e2e:prepare-topology -- --force <kind> --use-build-artifacts --composer-cache=<dir>
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

The Incus provision builder records a checkpoint manifest for each provision
artifact namespace. The manifest records schema version, topology kind, role
DAG, role checkpoints, snapshot names, creation timestamps, compact artifact
fingerprints, and per-role fingerprints. The raw source file hash maps stay in
the in-memory fingerprint calculation and are not persisted in the manifest.
Snapshot existence is never enough to resume: a checkpoint is reusable only when
the manifest exists, the required snapshot exists, and the stored role
fingerprint matches the current computed fingerprint.

The first implementation uses conservative role fingerprints. Provision support,
base image, environment, and role-DAG changes invalidate every role. CLI
artifact changes invalidate every CLI-consuming role. Gateway artifact changes
invalidate gateway and downstream gateway-state-dependent roles while leaving the
operator substrate reusable when CLI/provision inputs are unchanged.

When a prepared full topology fails after the gateway is ready, successful
siblings are checkpointed independently. For example, if app-prod fails while
app-dev, agent, and websocket succeed, the next run may reuse operator,
gateway, app-dev, agent, and any dev-dependent websocket state that was safely
captured, while retrying the missing app-prod work. Resume mode must not
relaunch or rebake roles whose checkpoint fingerprints and snapshots are still
valid.

## Image environment

Use these defaults for local Incus image preparation unless a branch explicitly
changes the image shape.

```bash
ORBIT_E2E_HOST=beast
ORBIT_E2E_INCUS_IMAGE_BUILD_HOST=beast
ORBIT_E2E_SOURCE_IMAGE=images:ubuntu/26.04
ORBIT_E2E_BASE_IMAGE=orbit-base-ubuntu-26.04-runtime
ORBIT_E2E_BOOTSTRAP_USER=provisioner
ORBIT_E2E_OPERATOR_USER=orbit
ORBIT_E2E_CONTROL_USER=orbit # Operator user alias.
ORBIT_E2E_INSTANCE_PREFIX=orbit-e2e
ORBIT_E2E_TIMEOUT_SECONDS=600
ORBIT_E2E_KEEP=1
```
