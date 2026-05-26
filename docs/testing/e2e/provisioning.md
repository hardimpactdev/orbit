# E2E provisioning

Use E2E backed by a VM only when the behavior depends on real provisioning,
WireGuard, VM networking, OS trust-store mutation, systemd, package
installation, cloud-init, or host-level daemon behavior.

```bash
composer e2e:preflight
composer e2e:prepare-base-image -- --force
composer e2e:prepare-topology -- --force operator_gateway_app-dev_app-prod_agent
composer test:e2e:provision
```

Use `composer test:e2e:provision` only when topology, installer, image,
`node:new`, WireGuard provisioning, or other VM setup behavior changes. Ordinary
command ports should add feature tests that use prepared topologies instead.

## Incus image model

The VM E2E harness uses Incus VMs on the configured E2E host (`beast` by
default). It builds one reusable base image plus prepared source snapshots:

1. Base image `orbit-base-ubuntu-26.04`. Built once via
   `composer e2e:prepare-base-image -- --force`. It contains Ubuntu cloud, the
   bootstrap user, the `orbit` user, sshd, the E2E OS dependency set, and the
   PHP 8.5 CLI baseline used by Orbit itself. It does not contain Orbit source.
   It is used by the provisioning lane's base-VM lifecycle test and as the
   source for prepared topology roles.
2. Prepared source templates `orbit-template-control`, `orbit-template-gateway`,
   `orbit-template-dev`, `orbit-template-prod`, and `orbit-template-agent`.
   Build them with
   `composer e2e:prepare-topology -- --force operator_gateway_app-dev_app-prod_agent`.

During topology preparation, Orbit tars the current checkout, ships it plus
`bin/install-orbit` and `bin/e2e-provision-node` to the host, installs Orbit on
the operator template from the base image, snapshots `clean-operator`, then
starts that template and provisions the gateway through real `node:new`. After
the gateway is seeded, app-dev, app-prod, and agent are provisioned in parallel
before the five-role source snapshot is taken.

App-dev carries database and Redis state by default. App-prod carries the
ingress role by default.

## Source bundle and archives

Source code lives in the per-run bundle, not in the base image. Forced topology
preparation resumes from the highest complete canonical prerequisite snapshot
set and rebuilds only later roles. Rebuild the base image only when the
base image shape changes.

The provisioning bundle stages host-local `orbit-runtime:current`,
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

Provision tests clean up on success. On failure they keep tracked VMs/templates
for inspection and print their names plus a reap command. Set
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
ORBIT_E2E_CONTROL_USER=orbit # Legacy alias for older scripts.
ORBIT_E2E_INSTANCE_PREFIX=orbit-e2e
ORBIT_E2E_TIMEOUT_SECONDS=600
ORBIT_E2E_KEEP=1
```
