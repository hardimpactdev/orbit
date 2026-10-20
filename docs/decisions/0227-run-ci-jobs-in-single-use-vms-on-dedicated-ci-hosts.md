---
title: "ADR 0227: Run CI jobs in single-use VMs on dedicated CI hosts"
sidebarTitle: "0227 Single-use CI VMs"
description: "In progress. Sabre and Shark become dedicated CI hosts. GARM, a stock runner manager, runs each self-hosted CI job in a fresh Incus VM from the recipe image and deletes the VM after that job. GitHub-hosted runners take fork pull requests and every other job. This supersedes ADR 0213."
---

# ADR 0227: Run CI jobs in single-use VMs on dedicated CI hosts

Sabre and Shark run only CI. On each host, GARM gives every self-hosted job a fresh Incus VM, built from the same recipe image as task VMs, and deletes the VM after that one job. GitHub-hosted runners take fork pull requests and every job that the pool does not take. Feature development runs on Beast, with UpCloud for overflow. The persistent runners and their hand-made setup go. This supersedes [ADR 0213](/decisions/0213-run-ci-runners-through-a-ci-runner-role).

## Status

In progress.

Nick decided this on 2026-10-20.

Principle: [Security fits the real threat model](/mission#principles). Orbit is public, so the code of a pull request is untrusted. A VM that lives for one job and cannot reach the fleet is the boundary, and no filter inside a host pretends to be one. It also serves [Lean](/mission#principles): a stock runner manager replaces a setup script, host services, and the runner records that ADR 0213 planned. No principle exception is proposed.

## Context

Orbit runs its own CI partly on persistent self-hosted runners. This section states that layout, the measurements, and why GARM fits.

### Runners today

GitHub lists five runners for `nckrtl/orbit`. All of them are persistent.

| Runners | Host | How they run | Labels |
| --- | --- | --- | --- |
| `sabre-1`, `sabre-2` | Sabre: 6 cores, 12 threads, 30 GiB | Two host services as the user `github-runner`, which has `NOPASSWD:ALL` sudo | `self-hosted`, `Linux`, `X64`, `sabre`, `orbit-shard` |
| `ci-beast-1`, `ci-beast-2` | Beast: 8 cores, 16 threads, 128 GiB | Persistent unprivileged Incus containers that `tools/ci-runners/setup.sh` builds, each with 4 CPUs and 8 GiB | `self-hosted`, `Linux`, `X64`, `orbit-shard` |
| `ci-shark-1` | Shark: 6 cores, 12 threads, 64 GiB | One container of the same kind | `self-hosted`, `Linux`, `X64`, `orbit-shard` |

The [implementation loop](/reference/implementation-loop#self-hosted-gateway-runner) describes this setup. Three facts matter here:

- On Sabre a job can run `sudo`. The `github-runner-egress` nftables rule matches the runner's UID, so a job that becomes root bypasses it and can act as Sabre toward the Gateway. The sudoers drop-in `/etc/sudoers.d/github-runner` was made by hand on 2026-10-11, and nothing in Orbit manages it.
- No runner is single-use. A job can leave files and processes for the next job.
- Beast and Shark share their cores between runner containers, task VMs, and development Instances. Shark holds the Instances `pantry-sync` of Hauzer (388) and Mealou (389), and up to five task VMs.

GitHub advises against persistent self-hosted runners on a public repository. Fork pull requests already use hosted runners, and every external contributor needs approval. But a `runs-on` expression is not a boundary: an approved pull request can change its workflow.

ADR 0213 planned a `ci-runner` role, Gateway runner records, and persistent containers. None of it is built.

### Measurements

These come from the CI runner audit of 2026-10-19, which sampled 20 pull request runs and 20 `main` runs.

- Reference pull request run [39315174974](https://github.com/nckrtl/orbit/actions/runs/39315174974) took 348 s to `Required checks` and 42.1 runner-minutes over 19 jobs. Four jobs ran on self-hosted runners.
- In that run, hosted jobs spent 420 s in PHP setup, summed. A self-hosted job spends about 1 s, because PHP is already on the machine.
- In the four latest runs of the current shape, Gateway shard tests took 129 to 254 s at 4 Pest processes. The Gateway job ran quality checks for 54 to 165 s.
- A full Gateway suite on `main` takes about 510 to 600 s at 6 Pest processes.
- The median job queue was 2 s for hosted and self-hosted jobs. The tails were 186 s for a shard and 245 s for a rerun Gateway job.
- During the sample, Sabre and Shark were almost idle, with a load of 0.00 and 0.08. Beast had about 7.6 busy logical CPUs.

### GARM

[GARM](https://github.com/cloudbase/garm) is an open-source runner manager for GitHub Actions. The current releases are GARM 0.2.1 (2026-05-08) and [garm-provider-incus](https://github.com/cloudbase/garm-provider-incus) 0.1.5 (2026-04-26). GARM fits this decision:

- The Incus provider creates VMs by default (`instance_type = "virtual-machine"`). It uses a named project, a local image, and an Incus profile as the VM size. It talks to Incus over the local socket.
- Every runner is ephemeral and registers with just-in-time configuration. It takes one job, and then GARM deletes its instance.
- A scale set asks GitHub for jobs with long polls. No webhook reaches the host, so Sabre and Shark need no public ingress.
- A GitHub App credential works for one repository. It needs `Administration: Read & write` and `Metadata: Read-only`. Only webhook pools need the webhook permission.
- `min-idle-runners` keeps fresh VMs booted and waiting. `max-runners` caps the VMs.
- It is one binary with an embedded SQLite database, run as a systemd service.

GARM leaves four gaps, and this decision closes them:

- The provider applies no network policy. The role's bridge ACL applies it.
- The VM fetches its runner configuration from GARM and reports its status to GARM, so the VM must reach GARM's API port. The ACL allows that port and nothing else on the host.
- GARM's default cloud-init gives the runner user `NOPASSWD:ALL` sudo. A pre-install script, which is a GARM extra spec, removes it before the runner starts.
- GARM is not at 1.0. The recipe pins its version and checksum, and the trial in [step 4](#migration) proves the layout.

Other stock managers fit worse. Actions Runner Controller needs Kubernetes. GitHub's scale set client, `actions/scaleset`, is a library for a custom autoscaler. The philips-labs runners and RunsOn target AWS.

## Decision

The parts below form one contract: the hosts, the role, the runner VMs, the runner App, the workflow, the capacity, and the boundary.

### Hosts

- Sabre and Shark are dedicated CI hosts. Each holds only the role `ci-host`, and runs no Instance and no task VM.
- Feature development runs only on Beast, in task VMs of 2 vCPU and 4 GiB. A task VM runs light checks only: Pint, PHPStan and Larastan, Rector, lint, and deliverables. UpCloud takes development overflow.
- The full test suites run in CI. A separate change moves each handoff's full suite into sharded pull request CI. This decision depends on that change but does not contain it.

### The ci-host role

`orbit node:role:add <node> ci-host` assigns the role. It is Linux-only, is not a singleton, is not relocatable, and ignores Clusters. It shares a Node with every role except `gateway` and `vpn`, whose Nodes hold the fleet's trust anchors. Sabre and Shark hold it alone. Beast can add it only under the [queue rule](#capacity).

The role takes the Node setting `settings.ci_host`, with `cidr`, `zfs_dataset`, `cpus`, `memory`, and `disk`. It does this through [agent commands](/decisions/0225-make-node-changes-through-agent-commands):

- It installs Incus, GARM, and garm-provider-incus from the recipe. The recipe's `ci-host` set pins GARM and the provider by version and SHA-256.
- It creates a ZFS pool from `zfs_dataset`, as for a [task VM host](/reference/compute-drivers#prepare-an-incus-host).
- It creates the Incus project `orbit-runners`. The project is restricted: it allows VMs only, no containers, no host disks, no low-level config, and only the bridge `orbitrun0`.
- It creates `orbitrun0` from `cidr`, with IPv4 NAT, no IPv6, and port isolation. It attaches the ACL `orbitrun0-egress` at creation.
- It writes the profile `runner` with `cpus`, `memory`, and `disk`.
- It writes GARM's configuration, the provider configuration, and the GARM systemd unit. GARM's API listens only on the bridge address. GARM's database, its passphrase, and its JWT secret stay on the host.
- It adds the ufw rules for the bridge and for GARM's port on the bridge address.

The image chain of [ADR 0206](/decisions/0206-build-task-vm-images-as-chains-of-small-jobs) builds the recipe's base image on every active `ci-host` Node too. It puts the image in `orbit-runners` under the alias `orbit-runner-base`, and builds no topology seed. A recipe change builds and promotes a new image, as on a task VM host. No package cache, CI cache ref, or credential enters the image.

Doctor checks the parts above: the GARM service and its version, the project restrictions, the bridge, the ACL rules, the profile, and the image alias.

GARM's own state is not Orbit state. An operator sets it once per host with `garm-cli`: the admin user, the GitHub credential, the repository, and the scale set. The reference page lists those commands.

### Runner VMs

- Each job gets a fresh VM from `orbit-runner-base`. GARM registers it for `nckrtl/orbit` with just-in-time configuration. The VM takes one job, and GARM deletes it.
- The scale set's `min-idle-runners` equals its `max-runners`, so every free slot holds a booted VM that waits for a job. A VM that has run no job is fresh.
- The VM holds nothing of the fleet: no WireGuard key, no Orbit agent or agent secret, no Node SSH key, no Gateway token, no CLIProxyAPI key, no package-cache mount, and no CI cache credentials.
- The job user `runner` has no sudo. So pool jobs leave out the `privileged` group, as shards do today, and a test that needs root fails outside that group.
- Pest runs one process for each vCPU of the VM.
- The job verifies that the image holds the recipe's PHP build. It installs nothing.

### The runner App

A separate GitHub App, `orbit-ci-runners`, is installed only on `nckrtl/orbit`. It has `Administration: Read & write` and `Metadata: Read-only`. The GARM of each CI host holds its own private key of that App, so one host's key can be revoked alone.

The Gateway holds no runner credential. The Orbit App stays at `Administration: read`, as [ADR 0223](/decisions/0223-run-one-gate-from-handoff-to-merge) decides. A user-owned repository has no narrower permission for runners, and `Administration: write` also permits deleting the repository, so that right stays off the Gateway and only on CI hosts.

### Workflow

| Jobs | Runner |
| --- | --- |
| The Gateway project job and the four Gateway shards, for pushes, dispatches, schedules, and pull requests from branches of this repository | The pool |
| Every job of a fork pull request | GitHub-hosted |
| Every other job | GitHub-hosted, as today |

- The GARM of each host runs one scale set, named after the host: `orbit-ci-sabre` and `orbit-ci-shark`. Both carry the label `orbit-ci`.
- If the trial in step 4 shows that GitHub spreads jobs with one label over both scale sets, the jobs target `orbit-ci`. If not, each job names a host. On a pull request, the Gateway project job and shards 1 and 2 run on Sabre, and shards 3 and 4 on Shark. On `main`, the Gateway job runs on Shark.
- Overflow is static. GitHub queues a job until a runner with its labels is free, and never moves it to a hosted runner. The repository variable `ORBIT_CI_POOL` replaces `ORBIT_SABRE_RUNNER`. When it is not `true`, every pool job runs on a hosted runner. Hosted minutes are free, because the repository is public.
- A job moves into the pool when the image holds its tools and the capacity below has room. That is a workflow change, not a new decision. Jobs that need root, Docker, browsers, or the Tauri libraries stay hosted.
- A pull request that changes the PHP build cannot pass on the pool before the image holds the new build. So before its CI runs, the operator sets `ORBIT_CI_POOL` to `false`. After the merged recipe's image is promoted, the operator sets it back to `true`. This replaces the manual runner update in [Merge a PHP build change](/reference/implementation-loop#merge-a-php-build-change).

### Capacity

The vCPUs of a host's runner VMs add up to at most its threads, so each Pest process has a thread. Each VM gets 2 GiB of memory per vCPU, the ratio that task VMs use for full suites today.

| Host | Threads | Memory | Runner VMs | vCPU used | Memory used | Memory left for the host |
| --- | --- | --- | --- | --- | --- | --- |
| Sabre | 12 | 30 GiB | 3 of 4 vCPU, 8 GiB, 20 GiB disk | 12 | 24 GiB | 6 GiB |
| Shark | 12 | 64 GiB | 3 of 4 vCPU, 8 GiB, 20 GiB disk | 12 | 24 GiB | 40 GiB |
| Pool | 24 | 94 GiB | 6 | 24 | 48 GiB | |

Demand, from the measurements:

- A pull request run takes 5 VMs: the Gateway project job and four shards of 4 processes.
- A `main` run takes 1 VM, for one full Gateway recording run. At 4 processes instead of 6, it takes about 765 to 900 s. This scales the measured 510 to 600 s and is an estimate.
- One pull request run and one `main` run fill the pool. The jobs of a second pull request wait for free VMs.
- A pull request run holds its VMs for about 4 to 5 minutes. So the pool runs about 12 pull request runs an hour while `main` is idle. This is an estimate.

The VM size is a Node setting, and the count is the scale set's `max-runners`. A trial of two VMs of 6 vCPU and 12 GiB per host, with four shards of 6 processes, changes those values and not this decision.

Queue rule: a CI VM on Beast comes only when measurements show queues. The measure is GitHub's queue time of pool jobs, `started_at` minus `created_at`, over one week of normal load. If its 90th percentile is above 60 s, Beast adds the `ci-host` role with one VM of 4 vCPU and 8 GiB. That leaves 12 of Beast's 16 threads for development.

### Security boundary

The VM is the boundary. Everything inside it is untrusted.

- The ACL rejects every private, CGNAT, loopback, link-local, multicast, and reserved IPv4 range, and all IPv6, as `orbit-ci-egress` does today. A job reaches the public internet, but not the LAN, WireGuard, the Gateway's WireGuard address, another Node, or the host.
- Two paths to the host stay open. GARM's API port on the bridge address lets the VM fetch its runner configuration and report its status. Every GARM call needs the admin token or the VM's own instance token. Incus DNS and DHCP on the bridge are accepted before the ACL applies. On a CI host, that DNS knows only runner VM names.
- Port isolation blocks traffic between VMs.
- A job reaches the Gateway only as the public internet does, like a hosted runner. The Gateway trusts only WireGuard addresses.
- The host is a trusted fleet Node. It holds GARM, the App key, and its own WireGuard identity, and no VM can read them. An escape from the VM through QEMU or KVM is the remaining risk, as on every VM host.
- Sending fork pull requests to hosted runners is a cost and capacity choice, not a boundary. An approved fork pull request that changes its workflow to target the pool gets the same VM.
- No UID firewall, sudoers rule, or other filter inside a host counts as a boundary.

### Migration

1. The change that moves each handoff's full suite into sharded pull request CI lands.
2. Task VMs shrink back to 2 vCPU and 4 GiB. Set `cpus` to `2` and `memory` to `4GiB` in `settings.task_vms` of Beast (Node 9) and Shark (Node 13). A VM keeps its size until it is destroyed.
3. Shark's two development Instances, 388 and 389, move to Beast with `instance:transfer`. Shark stops taking task VMs: [remove it as a task VM host](/reference/compute-drivers#add-a-task-vm-host), then delete its `orbit-tasks` project, images, topology seeds, bridge, ACL, and pool. Then remove Shark's `app-dev` role, and its `database` role, which no Database server uses.
4. GARM single-use runners start on Sabre and Shark:
   1. Set `ORBIT_SABRE_RUNNER` to `false`, so every job runs on a hosted runner. Stop the runner services on Sabre and the runner container on Shark, so their threads go to the VMs.
   2. Build the `ci-host` role, `settings.ci_host`, the recipe's `ci-host` set, the image target, and the Doctor checks.
   3. Create the App `orbit-ci-runners` and install it on `nckrtl/orbit`.
   4. Add the role to Sabre and Shark. On each, initialize GARM, add the App credential and the repository, and create the scale set.
   5. Run the trial, then switch the Gateway jobs in `ci.yml` to the pool and set `ORBIT_CI_POOL` to `true`.
5. The old runners are removed, as listed below. The pull request of this step writes the CI hosts reference page and retires this ADR and ADR 0213.

The trial in step 4 passes when all of these hold:

- GARM launches a VM from the local alias `orbit-runner-base`.
- The VM registers with just-in-time configuration, takes one job, and is deleted after it.
- The job user has no sudo.
- From the VM, connections fail to the host's LAN and WireGuard addresses, the Gateway's WireGuard address, and every port on the bridge address except GARM's.
- The result for one label over two scale sets is known.
- A full pull request run and a `main` run are timed against run 39315174974: queue, VM boot, setup, tests, and `Required checks`.

### What gets deleted

| Part | Where |
| --- | --- |
| The persistent runner setup, `tools/ci-runners/setup.sh`, and its `covers:` entry | Repository |
| The runner registrations `sabre-1`, `sabre-2`, `ci-beast-1`, `ci-beast-2`, and `ci-shark-1`, and the variable `ORBIT_SABRE_RUNNER` | GitHub |
| The runner services `sabre-1` and `sabre-2`, the stale `runner-3` drop-in, and their work-directory mounts | Sabre |
| The user `github-runner`, its home, and the sudoers drop-in `/etc/sudoers.d/github-runner` | Sabre |
| The UID egress firewall: the `github-runner-egress` unit and its nftables table | Sabre |
| The Composer `dpkg-divert`, the ACL on the managed user's home, and the recorded `ci-runner` recipe profile | Sabre |
| The containers, the Incus project `orbit-ci`, the bridge `orbitci0`, the ACL `orbit-ci-egress`, and its ufw rule | Beast and Shark |
| `orbit-ci/*` in `task_vms.operator_owned` | Beast |
| The `--profile` option of `node:converge-recipe`, and the path of nightly recipe maintenance for a roleless Node with that profile | Gateway |
| The self-hosted runner sections of the implementation loop, and the text that calls the UID firewall a boundary | Documentation |

The recipe's `ci-runner` set stays, because hosted CI jobs install their PHP from it. ADR 0213's runner records, its `ci-runner:*` commands, and its App permission change were never built, so no code goes for them.

## Rejected alternatives

- Keep ADR 0213's plan of Gateway runner records and persistent containers: a job still leaves state for the next one, and Orbit would write lifecycle code that stock managers already have.
- Write an Orbit service that creates and deletes runner VMs, for example on `actions/scaleset`: it is custom code for a solved problem.
- Actions Runner Controller: it needs a Kubernetes cluster that the fleet does not have.
- Single-use containers instead of VMs: they share the host kernel, and jobs that need Docker would need nesting.
- Only hosted runners: every hosted job repeats its machine setup, 420 s of PHP setup alone in the reference run, and the 24 threads of Sabre and Shark stay unused. Hosted runners stay for forks and overflow.
- Move a job to hosted runners when the pool is busy: Actions has no stock way to do it, and a custom router is the scheduler that this decision avoids.
- Give the Orbit App `Administration: write`: that right permits deleting the repository, and the Gateway would hold it.
- A personal access token, even a fine-grained one: it belongs to one person and expires.
- Webhook pools: they need a public endpoint into a host on the LAN.
- One GARM for both hosts over the Incus API: one host would control the other's Incus, which is root on that host. One GARM per host keeps the hosts independent.
- Use enrolled task VMs as runners: they hold a WireGuard key, an agent secret, and a CLIProxyAPI key.
- Keep sudo for the job user: tests that need root outside the `privileged` group would pass on the pool and fail elsewhere.

## Consequences

- Every pool job starts on a clean VM, and no state crosses from one job to the next.
- The Gateway holds no runner credential, and the Orbit App keeps `Administration: read`.
- Sabre gets a role, so Doctor and agents see the CI hosts.
- The pool image follows the recipe. Self-hosted runners need no manual update after a recipe change, except the PHP build switch above.
- Pool jobs lose warm local caches. Composer and Bun downloads come from the Actions cache, and PHP comes from the image. Idle VMs hide the boot time.
- The `main` Gateway run uses 4 processes instead of 6 until a six-vCPU layout is tried.
- One pull request run and one `main` run fill the pool. More concurrent runs queue until the queue rule adds capacity.
- Shark loses its development work. Beast and UpCloud carry all of it.

## Affects

- Components: apps/gateway, apps/cli, apps/docs, apps/e2e
- ADRs: supersedes [0213](/decisions/0213-run-ci-runners-through-a-ci-runner-role); keeps the App permission of [0223](/decisions/0223-run-one-gate-from-handoff-to-merge); adds `ci-host` Nodes as image targets of [0206](/decisions/0206-build-task-vm-images-as-chains-of-small-jobs); builds the role through [0225](/decisions/0225-make-node-changes-through-agent-commands)
- Detail: `.github/workflows/ci.yml`, `.github/actions/php`, `tools/ci-runners`, and the machine software recipe; a new reference page, CI hosts, written in step 5; [Implementation loop: Self-hosted Gateway runner](/reference/implementation-loop#self-hosted-gateway-runner) and [Self-hosted shard runners](/reference/implementation-loop#self-hosted-shard-runners); [Role compatibility](/reference/node-provisioning#role-compatibility); [Converge a Node to a recipe](/reference/node-provisioning#converge-a-node-to-a-recipe); [Build the base image](/reference/compute-drivers#build-the-base-image); [Role and base tool sets](/reference/compute-drivers#role-and-base-tool-sets); [Node settings](/reference/node-settings)
- Verify: Gateway feature tests for the role's compatibility, its setting, the image target, and its Doctor checks; an Incus E2E that adds the role and probes from a runner VM that the host, LAN, and WireGuard are out of reach; the live trial in step 4
