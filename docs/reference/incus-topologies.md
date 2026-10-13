---
title: "Incus topologies"
description: "How the harness composes a disposable Incus topology from declared Nodes and per-role images, runs it nested in a task VM or on a bare host, and releases it."
covers:
  - bin/{e2e-topology,e2e-topology-images,e2e-topology-host-setup,e2e-clone-bridge,e2e-task-cleanup}
  - apps/e2e/config/e2e.php
  - apps/e2e/app/Console/Commands/{Topology,TopologyImages}/**
  - apps/e2e/app/E2E/**
  - apps/e2e/resources/**
  - apps/gateway/app/Console/Commands/RenderNodeProgramsCommand.php
---

# Incus topologies

This page is for the contributor, agent, or reviewer who runs Orbit on disposable Incus machines. The `apps/e2e` harness composes one topology per issue from the Nodes the issue declares. Each Node starts from a prepared role image, and the harness then bootstraps a fresh test Gateway and enrolls the Nodes. `bin/e2e-topology` controls a topology, `bin/e2e-topology-images` builds the role images, and `bin/e2e-topology-host-setup` prepares a machine, such as a task VM, to run topologies nested. The [using-incus-topologies](https://github.com/nckrtl/orbit/blob/main/.agents/skills/using-incus-topologies/SKILL.md) skill covers the working habits.

## Declared topologies in task VM groups

An Orbit task group on a [task VM](/reference/compute-drivers#task-vms) gets a topology only when its subtasks [declare](/reference/compute-drivers#declare-a-topology) one. Orbit prepares it nested in the group's VM as `TASK-<group>`, with `--nodes` set to the union of the declarations, before the subtask's baseline check or implementer. Agents use it and never acquire or release it. The reviewer records evidence on each declared node, and Orbit refuses the approval without it. See [Prove work on a topology](/reference/compute-drivers#prove-work-on-a-topology).

## Topologies on the reviewer's request

This section applies to shared task workspaces only. An Orbit task group has no topology by default. Workspace provisioning never acquires one. The reviewer decides whether discovery needs a topology and ends a turn with `topology_requested` through the [turn receipt](/reference/tasks#request-a-topology). An implementer asks the reviewer through the existing consult instead. Agents run as `orbit-worker` without sudo; they do not acquire topologies themselves.

Orbit consumes the receipt, acquires `TASK-<group>` as the managed user, and resumes the same requesting reviewer thread in its original review, consult, or relay context with the acquisition result or failure. A consult stays open and its implementer stays paused during acquisition; a resource request neither answers it nor escalates it to the operator. The resumed reviewer answers normally under the original context's outcome and cause rules.

Acquisition changes host firewall rules. Each group holds at most one topology, shared across its subtasks and review turns. A repeated request uses that group's complete discovery topology, not another one. Orbit acquires the default Nodes, `app-dev` and `app-prod`.

A retained discovery lease without its topology record is an incomplete acquisition, not an already-held usable topology. Orbit reports that failure and keeps its ownership and state for normal workspace-removal cleanup; it neither acquires another topology nor asks the agent to release it. Failure replies include a bounded, redacted reason from the harness output. Acquisition failure or absence of a topology never prevents approval: these topologies support discovery, not mandatory proofs. Orbit releases the topology when it removes the group's workspace.

## Declared Nodes

Every topology has a `gateway` VM and an `operator` system container. `acquire --nodes=LIST` adds the workload Nodes the work needs. `LIST` is a comma-separated list of entries, in any order. An entry names one of `app-dev`, `app-prod`, and `app-prod-2`, and may add roles after a `+`: `ingress`, `database`, and `websocket`, for example `app-prod+ingress`. `app-prod-2` requires `app-prod`. Without the option, the list is `app-dev,app-prod`. An empty list, `--nodes=`, gives the Gateway and the operator only. A task VM group's subtask `topology` uses the same entries.

| Node key | Role image | Incus address | WireGuard address | Roles | Checkout |
| --- | --- | --- | --- | --- | --- |
| `gateway` | `orbit-e2e-gateway` | `.10` | `10.44.0.1` | `gateway`, `vpn` | yes |
| `app-dev` | `orbit-e2e-app-dev` | `.11` | `10.44.0.2` | `app-dev` | yes |
| `app-prod` | `orbit-e2e-app-prod` | `.12` | `10.44.0.3` | `app-prod` | no |
| `app-prod-2` | `orbit-e2e-app-prod` | `.13` | `10.44.0.4` | `app-prod` | no |
| `operator` | `orbit-e2e-operator` | `.14` | `10.44.0.5` | none | yes |

`app-prod-2` is a second `app-prod` Node from the same role image. Each production Node gets its own name as its development TLD, and `app-dev` gets `beast`.

The harness assigns the roles in the table and the roles each entry adds, and nothing else. It creates no Cluster or Router, no other role, and no sample Project, Instance, or Route. Add other things the work needs with the Orbit CLI on the topology, for example `orbit project:create`, and record those steps as evidence. So a topology holds only what the task declared and created.

| Field | Value |
| --- | --- |
| Network | `oe-<hash>` on `10.232.<slot>.0/24`. The hash is 12 hex characters of the SHA-256 of `<issue>:<attempt>`. |
| Guests | `orbit-e2e-<issue-lowercase>-<attempt-prefix>-<node>`, with the first 8 characters of the attempt ID |
| Checkouts | `gateway`, `app-dev`, and `operator` mount the worktree live at `/home/orbit/orbit`. Production Nodes have no checkout. |
| Issue ID | Matches `[A-Z][A-Z0-9]{1,9}-[1-9][0-9]{0,8}`. `acquire` and `sync` require it in the worktree's branch name, such as `TASK-58` on branch `task-58`. |

### The operator guest

The operator is a roleless Node registered with the topology's Gateway, with a Gateway access grant and its own WireGuard peer in that topology. It has no workload role and hosts no Instance. It trusts the topology's Orbit CA and mounts the worktree live, so host edits reach `apps/web` without a copy or rebuild. Its CLI profile and web proxy configuration refer only to this topology. Missing configuration is an error, not permission to use the real Gateway or another user's credentials.

The operator is separate from the host's network namespace. A host such as beast or a task VM may already belong to the real fleet, and the topology reuses the fleet's WireGuard address range. Joining that tunnel on the host would mix live and disposable traffic.

## Role images

A role image is an identity-free Incus image with the packages and tools of one Node kind installed. There are four: `orbit-e2e-gateway`, `orbit-e2e-app-dev`, and `orbit-e2e-app-prod` are VM images, and `orbit-e2e-operator` is a container image. They live in the harness's Incus project. `bin/e2e-topology-images build` builds them the way Orbit builds the [task VM base image](/reference/compute-drivers#task-vms): it runs the role's programs in a builder, records them, and removes the identity.

| Step | What happens |
| --- | --- |
| Render | Runs `php apps/gateway/artisan orbit:node-programs ROLE... --json` from the checkout, with a throwaway `ORBIT_HOME`. The Gateway renders the programs that Node enrollment runs for those roles. |
| Launch | Starts one builder per role on its own harness network, from the stock Ubuntu 26.04 image |
| Prepare | Creates `orbit` with uid and gid 1000 and passwordless sudo, installs OpenSSH and ufw, and keeps the public DNS upstreams `1.1.1.1` and `8.8.8.8` |
| Programs | Runs the role's programs as `orbit`, in order |
| Record | Writes `/var/lib/orbit-e2e/role-image.json`: the role, the program fingerprints, the source image fingerprint, and the checkout commit |
| Clean | Runs the Gateway's task VM image clean, `base-image-clean.sh`, then the harness seal, which turns cloud-init off |
| Publish | Publishes `orbit-e2e-<role>-<UTC time>`, moves the alias to it, and deletes older images of that role that no guest uses |

Render and enrollment share the same Gateway code. The throwaway `ORBIT_HOME` holds one fixed placeholder SSH public key, whose private half does not exist, so every render is identical.

Builders start from `images:ubuntu/26.04/cloud` for VMs and `images:ubuntu/26.04` for the container. `ORBIT_E2E_IMAGE_VM_SOURCE` and `ORBIT_E2E_IMAGE_CONTAINER_SOURCE` change them. A stock VM reboots once after the Incus agent writes its cloud-init seed, so the build waits until cloud-init is done and the boot ID holds. The stock cloud images lack ufw, which Ubuntu Server ships and Orbit uses for every Node firewall, so Prepare installs it.

Both image pipelines share one clean script. It clears APT archives and removes the SSH host keys, machine ID, cloud-init state, authorized keys, logs, and per-install service IDs. The seal empties known hosts, gives the VM a systemd-networkd DHCP file, and checks that no WireGuard configuration or Gateway database remains. A guest from the image boots once and creates its own SSH host keys.

| Role image | Programs |
| --- | --- |
| `gateway` | The Gateway's own prerequisites, then `orbit:node-programs gateway vpn` without its bootstrap program |
| `app-dev` | `orbit:node-programs app-dev`, the enrollment programs the task VM base image also runs |
| `app-prod` | `orbit:node-programs app-prod` |
| `operator` | The operator's web tooling, then `orbit:node-programs` with no role, for a roleless Node |

The Gateway's own prerequisites are PHP 8.5 with the Gateway's extensions, Composer, dnsmasq, and WireGuard. `orbit:node-programs gateway vpn` adds the Caddy package and the VPN prerequisites. The Gateway image skips the bootstrap program, because the Gateway is never enrolled over SSH. The operator's web tooling is PHP 8.5, Composer, and Vite+.

A program fingerprint is the SHA-256 of its exact arguments and input, in the format of the task VM image manifest. `orbit:node-programs --json` prints the Gateway's fingerprint with each program, and the build stops when the harness computes another. `bin/e2e-topology-images status` renders the programs again and reports each image as `current`, `stale`, or `missing`. A stale image still composes a correct topology: enrollment runs every program again, each program is idempotent, and it only takes longer. A missing image stops `acquire`, and the error names the build command.

An image holds no Gateway database, CA, SSH key, WireGuard key, authorized key, or Node record. The composition creates all of them for each topology. The build holds the host lock `topology-images`, so one build runs at a time. A failed build deletes its builders and its network and keeps the current images.

| Command | What it does |
| --- | --- |
| `bin/e2e-topology-images build [--role=ROLE ...]` | Builds the named role images, or all four |
| `bin/e2e-topology-images status` | Prints each image's alias, fingerprint, build time, and state |

## Composition

`acquire ISSUE WORKTREE [--nodes=LIST]` composes the topology in this order. Each step names itself in a failure.

| Step | Work |
| --- | --- |
| `reserve` | Under the host lock `topology-create`, counts the declared VMs against the budget, picks a network slot, and creates the network |
| `create` | Creates each guest from its role image, with a fixed MAC and Incus address, and mounts the worktree on the checkout Nodes |
| `start` | Starts every guest and waits for its agent and its address |
| `bootstrap.gateway` | Prepares the worktree's Gateway `.env` and runs `orbit:bootstrap` at the Gateway's address |
| `enroll.<node>` | Enrolls each workload Node in table order with `orbit:node-provision` |
| `enroll.operator` | Registers the roleless operator, grants it Gateway access, and points its CLI at the topology Gateway and CA |
| `roles.<node>` | For each workload Node whose entry adds roles, runs `orbit node:role:add <node> <role> --json` from the operator for each role, in entry order |
| `readiness` | Runs the readiness probes |

`bootstrap.gateway` writes the Gateway checkout path into the worktree's Gateway `.env` and applies the migrations. `orbit:bootstrap` then creates the Orbit CA, the Gateway SSH key, and the VPN at `10.44.0.1`.

For each workload Node, `enroll.<node>` authorizes the Gateway SSH key and reads the Node's host key. It passes the Node's roles, WireGuard address, and host key fingerprint to `orbit:node-provision`. Enrollment is serial, because it writes the Gateway's SQLite store.

The harness changes guests only through Orbit commands and its own guest scripts. It never edits the Gateway database. A failed acquisition removes every resource it created, drops the lease, and exits nonzero with the step and the redacted error. When cleanup itself fails, the lease stays so `release` can finish it.

### Readiness

Readiness runs these probes on the Nodes the topology has. A probe that fails is retried until the readiness window ends; then the topology fails with every failed probe and its last output.

| Probe | Node | Passes when |
| --- | --- | --- |
| `nodes.registered` | `gateway` | `orbit node:list` shows exactly the declared Nodes, each `active` with the roles from the table and its entry, and the operator roleless |
| `service.gateway` | `gateway` | Caddy and PHP-FPM are active |
| `service.vpn` | `gateway` | `wg-quick@orbit` is active |
| `wireguard.reachability` | `gateway` | Every other Node answers a ping on its WireGuard address |
| `caddy.<node>` | `app-dev`, `app-prod`, `app-prod-2` | Caddy is active and its Caddyfile, written by a [Node Caddy build](/reference/caddy-configuration#node-caddy-build), is valid |
| `operator.gateway` | `operator` | `orbit gateway:status` answers through the operator's own topology profile |
| `source.<node>` | checkout Nodes | The worktree is mounted and its guest source marker matches the host |

| Setting | Default | Variable |
| --- | --- | --- |
| Guest start window: agent and address | 900 seconds | `ORBIT_E2E_GUEST_READINESS_SECONDS` |
| Readiness window | 600 seconds | `ORBIT_E2E_READINESS_SECONDS` |
| One probe command | 120 seconds | none |

Nested guests boot about three times slower than on a bare host, so the windows are long. A slow start only waits; a guest that never answers still fails at the end of the window.

## Run topologies nested in a task VM

A topology also runs inside a VM, such as a task VM, with nested KVM. Run `sudo bin/e2e-topology-host-setup` once in that VM before the first `bin/e2e-topology-images build`. The script is idempotent. Orbit [runs it](/reference/compute-drivers#prepare-the-topology) in a task VM group that declares a topology.

| Step | What happens |
| --- | --- |
| Packages | Installs `incus`, the QEMU system emulator for the machine's architecture, `btrfs-progs`, and `nftables` |
| Idmap | Adds `root:1000000:1000000000` to `/etc/subuid` and `/etc/subgid` when they have no root entry, and restarts Incus. Incus maps the unprivileged operator container from root's range and refuses to create it without one, as on a task VM whose user came from `useradd`. |
| Incus | Initializes Incus when it has no `orbit-e2e` storage pool |
| Access | Adds the invoking user to `incus-admin`. The group applies to new logins only, so start the harness in a new session, for example over SSH or with `sudo -u orbit -i`. |
| AppArmor | Adds `file mr /usr/bin/bash,` to `/etc/apparmor.d/local/wg-quick` and reloads the profile, so `wg-quick` runs in the unprivileged operator container. |
| Worktrees | Creates `/fast/worktrees/orbit`, the default worktree root, owned by the invoking user |
| Isolation | Installs the nftables table `orbit-e2e-isolation` and the boot unit `orbit-e2e-isolation.service` that loads it |

Incus gets a btrfs pool `orbit-e2e` on a sparse loop file (`--pool-size`, default 45GiB), the bridge `incusbr0` on `10.99.0.1/24`, and a default profile with a root disk and an `eth0` NIC. The harness replaces that NIC for each guest.

The isolation table keeps topology guests away from the machine's own WireGuard network, `orbit` on `10.44.0.0/24` by default (`--wireguard-interface`, `--wireguard-subnet`).

| Rule | Drops |
| --- | --- |
| Forward from `oe*` to the WireGuard interface | Topology guests reaching fleet Nodes through the machine's tunnel |
| Forward from `incusbr0` to the WireGuard interface | Other inner guests doing the same |
| Input from `oe*` to the WireGuard subnet | Topology guests reaching the machine's own WireGuard address |
| Input from `incusbr0` to the WireGuard subnet | Other inner guests doing the same |

A topology guest needs none of these paths: it talks to its own Gateway over its own WireGuard network. Its public egress and the WireGuard traffic between topology Nodes keep working.

## Capacity

The harness counts capacity from `incus list`, never from a ledger. It counts the VMs that carry `user.orbit.e2e.owner=orbit-e2e` and the `10.232.<slot>.0/24` subnets in use.

| Setting | Value |
| --- | --- |
| VM size | 1 vCPU and 16 GiB root disk (`e2e.incus.cpu`, `e2e.incus.root_size`). Memory is 1.5 GiB for `gateway`, 2 GiB for `app-dev`, 1 GiB for `app-prod` and `app-prod-2`, and 512 MiB for the operator container (`e2e.incus.memory`). `ORBIT_E2E_INCUS_MEMORY=2GiB` sets every guest for one run. |
| VM budget | `e2e.incus.max_vms`, default 24. `ORBIT_E2E_INCUS_MAX_VMS` sets it for one run. |
| Network slots | 1 to 200 |
| Incus scope | `e2e.incus.remote`, `e2e.incus.project`, and `e2e.incus.storage_pool`, from `ORBIT_E2E_INCUS_REMOTE`, `ORBIT_E2E_INCUS_PROJECT`, and `ORBIT_E2E_INCUS_STORAGE_POOL`. The defaults are `local`, `default`, and `orbit-e2e`. The remote must be `local`, because network creation and deletion also change host firewall rules. |

Every `incus` call carries the configured project. The harness reserves the declared VMs before it creates a network or a guest, and refuses `acquire` when the budget cannot hold them, naming the count and the limit. The operator container is not a VM. A role image build counts its three builder VMs the same way.

### Locks

Every command except `status`, `shell`, and `web` holds the lock `topology-<ISSUE>` under `<primary>/.e2e/locks/`. Topology creation holds the host lock `topology-create` from network creation until every guest exists. A role image build holds `topology-images`. `web` atomically reserves its operator proxy device rather than holding the issue lock throughout the session, so other discovery commands and release can run while the page is open.

## Commands

`acquire` takes the worktree as an argument. Every other command finds the issue among the registered Git worktrees by branch or directory name. It needs exactly one match, or `--worktree=PATH`. Every command accepts `--json`. A failure prints `{"state":"failed","error":"..."}` and exits nonzero.

| Command | What it does |
| --- | --- |
| `acquire ISSUE WORKTREE [--nodes=LIST]` | [Composes](#composition) the topology and verifies readiness |
| `shell ISSUE NODE` | Opens a login shell as `orbit` on one Node |
| `exec ISSUE NODE --argv=JSON [--timeout=SECONDS] [--record=LABEL]` | Runs one argument vector as `orbit` on one Node and waits for it. The timeout defaults to 60 seconds, with a maximum of 3600. |
| `spawn ISSUE NODE NAME --argv=JSON` | Starts a long-lived process as the transient unit `orbit-e2e-NAME.service` and returns at once |
| `logs ISSUE NODE NAME [--since=TIME] [--lines=N] [--record=LABEL]` | Prints the unit's output with precise timestamps, also after the process ends |
| `kill ISSUE NODE NAME` | Stops the unit. Its output stays readable. |
| `sync ISSUE [--quick]` | Proves the mount, applies pending Gateway migrations, and verifies readiness. `--quick` skips verification. |
| `verify ISSUE` | Verifies readiness and records the report |
| `status ISSUE` | Reports the state files without touching Incus |
| `web ISSUE` | Runs the web development server in the operator, publishes it on the host's loopback, and streams output until stopped; see [Web session](#web-session) |
| `release ISSUE` | Stops its web session, removes its loopback publication, removes the topology, and verifies that it is gone |
| `register [--force]` | Registers this checkout as the primary for its origin, so task workspace clones can [bridge](#task-workspace-clones) to it. `--force` replaces a live registration. |

`NODE` is a Node key of the topology: `gateway`, `operator`, or a declared workload Node. `--argv-file=PATH` can replace `--argv` on `exec` and `spawn`. The file holds `{"argv":[...],"stdin":null}`, and only `exec` accepts stdin. The harness refuses both options together.

An issue holds one discovery topology at a time. `acquire` creates it and `release` removes it. Its state lives under `<worktree>/.e2e/`, in `attempt.json` and `topology.json`, and it dies with the worktree. A lease names the issue, the attempt ID, the operation ID, the declared Nodes, and the acquisition time. There is no reaper. A topology lives until the operator or Orbit releases it.

Task agents and reviewers use only the topology allocated to their task. They never acquire or release a topology, receive no sudo, and do not touch the host firewall. Use `exec`, `spawn`, `logs`, and `kill` from the task workspace to work on the allocated topology. Clean up processes you spawn, but leave the topology allocated for teardown.

### Web session

Run `bin/e2e-topology web TASK-58` in the task workspace only after its topology has been acquired. `web` requires that topology and its configured operator; it never acquires one implicitly. It installs the mounted web app and annotation package dependencies with `vp install` and runs `vp dev` as the transient `orbit-e2e-web.service` unit in `/home/orbit/orbit/apps/web` in the operator, with guest TCP port `5173` and strict-port behavior. An occupied guest port fails startup; there is no guest-port fallback.

The command publishes the dev server only on the host's IPv4 loopback, `127.0.0.1`, using an available OS-assigned ephemeral host TCP port. After readiness, it prints the actual host port and URL, such as `http://127.0.0.1:P` with the numeric port in place of `P`. There is no fixed host port, DNS name, or Caddy Route. [Web app: Run against a topology](/reference/web-app#run-against-a-topology) gives the Mac SSH forwarding steps.

`web` stays in the foreground and streams dev-server output. Keep it running while using the page. Ctrl-C, command termination, startup failure, and topology release stop that session's dev-server process and remove its loopback publication. Stopping `web` does not release the topology. Only one web session may run per topology. A second invocation fails clearly without disturbing the first.

The proxy device records a random ownership token before startup, and the unit's description carries the same token. Cleanup reads that durable reservation rather than trusting whether an external command returned successfully. It stops only the matching unit and keeps the reservation until the unit is confirmed stopped. A guest lock serializes startup and cleanup; cancellation records prevent a delayed start from reviving a cleaned session. Release can recover a session after its foreground owner crashes, and failed cleanup retains the marker for retry.

The public wrapper transfers its PID through the clone bridge to PHP, so TERM or HUP sent to that PID reaches the foreground owner and waits for cleanup, just as terminal Ctrl-C does.

The session pins the Gateway URL and trusted CA to the selected topology. Inherited endpoint overrides must not redirect Gateway, realtime, or metrics traffic to the live fleet. If the topology, operator, URL, CA, Gateway grant, or WireGuard configuration is absent, startup fails clearly instead of falling back to the caller's live profile or another user's credentials.

Agents drive the page from `operator` at `http://127.0.0.1:5173`, using the Playwright dependency in the mounted `apps/web`. Install Chromium and WebKit with `vp exec playwright install --with-deps webkit chromium` there, then run browser code with `vp exec node --input-type=module -e '...'` through `exec`. The [topology skill](https://github.com/nckrtl/orbit/blob/main/.agents/skills/using-incus-topologies/SKILL.md#check-the-web-ui-on-the-held-topology) describes checks against the topology, live reload, and isolated actions. In a task workspace clone, run a harness command such as `status` after each edit to mirror it into the mounted bridge. Demo-mode screenshots remain the layout check; they do not replace the live Gateway check when a topology is held.

### Guest commands

`exec` runs the vector through `runuser -u orbit -- env -C /home/orbit HOME=/home/orbit ORBIT_HOME=/home/orbit/.orbit DB_DATABASE=/home/orbit/.orbit/gateway.sqlite PROGRAM ARGS`. No shell profile loads. `argv[0]` must resolve on the guest `PATH` or be an absolute path. It cannot start with `-` or contain `=`.

The harness links `apps/cli/orbit` to `/usr/local/bin/orbit` on each checkout Node, so `orbit` resolves by name. Wrap a pipeline in `["sh","-c","..."]` and root work in `["sudo","..."]`. `shell` opens the same environment with `bash -l`. It starts in `/home/orbit/orbit` on a checkout Node and in `/home/orbit` on a production Node.

With `--json`, `exec` prints `{"state":"executed","exit_code":N,"stdout":"...","stderr":"..."}`. Without it, `exec` prints the guest stdout. It exits `0` only when the guest command does.

Incus waits for every process that an `exec` session starts. A command that leaves a background process holds `exec` open until the timeout. Start a long-lived process with `spawn` instead. `spawn` runs `systemd-run --collect` with the same user, directory, and environment as `exec`. A name has 1 to 40 lowercase letters, digits, or hyphens. Kill a unit before you spawn another one with the same name. The harness log `<worktree>/.e2e/log` records every `exec`, `spawn`, and `kill`, with the redacted argv and the exit code.

### Evidence log

`exec --record=LABEL` and `logs --record=LABEL` append one entry to `<worktree>/.e2e/evidence.log`. A label has 1 to 80 letters, digits, spaces, dots, dashes, or underscores, and is not blank. The harness checks the label before it touches Incus.

```text
=== 2026-09-24T06:40:00.123Z viewer after crash node=app-dev exit=0 duration=1234ms end=2026-09-24T06:40:01.357Z
$ orbit node:list --json
--- stdout
...
--- stderr
...
```

The header gives the start time, the label, the Node, the exit code, the duration, and the end time, in UTC with milliseconds. The `$` line is the argv as shell-quoted words. The harness redacts the argv and the output as it does in `<worktree>/.e2e/log`. The log is append-only. The harness creates it with mode `0600` in the `0700` `.e2e/` directory, and refuses a symbolic link in that directory path.

Recording never changes the command's output or exit code. A failed append prints a warning on stderr. The log can hold output that the redactor does not recognize as a secret, so treat it like the rest of `.e2e/`. `bin/worktree-remove` deletes it with the worktree, so copy what you need first.

## Discovery mount

`acquire` attaches the worktree to the checkout Nodes as the Incus disk device `orbit-source`. It is a read-write virtiofs share at `/home/orbit/orbit`. A host edit is live in every checkout Node at once. Run `sync` only after a migration or a change to a guest helper script.

Guests never run Composer. Host `bin/bootstrap` owns `vendor/` and the Gateway `.env`. `acquire` refuses a worktree without the Gateway, CLI, and SDK autoloaders or without `apps/gateway/.env`. The harness sets only that file's `ORBIT_GATEWAY_CHECKOUT` to `/home/orbit/orbit/apps/gateway`. The Gateway's database, CA, and keys live in the guest under `/home/orbit/.orbit`, not in the worktree.

### Gateway schema

`acquire` and `sync` run `php artisan migrate --force --no-interaction` from the mounted Gateway as `orbit`. This applies only the migrations that the mounted source declares. A migration error makes `acquire` or `sync` exit nonzero. A failed `acquire` removes its own resources. A failed `sync` keeps the topology and its last good source binding in `topology.json`. Fix the source and run `sync` again. Then check `php artisan migrate:status` on the Gateway and run `verify`.

`sync --quick` proves the mount, installs the current guest helper scripts, and applies the migrations. It skips the readiness probes and prints a note that readiness was not verified. It leaves `topology.json` and the guest source marker at the last full `sync`. So `status` shows that binding, and `verify` refuses a mount that differs from it until the next full `sync`.

## Task workspace clones

On a host shared by several task workspaces, such as beast, a task workspace is not a linked worktree of the primary checkout. It is a linked worktree of Orbit's default Instance, or an independent clone. It does not hold the host locks. So `bin/e2e-topology` runs the command in place only in the primary checkout and its linked worktrees. In any other checkout, it runs the command through a bridge worktree of the primary checkout. The table calls that checkout the clone.

| Step | What happens |
| --- | --- |
| Find the primary | Reads the primary registration for the clone's origin; without a live one, the command runs in the clone |
| Update the bridge | Checks out the clone's HEAD in `<worktree root>/<clone directory>-e2e`, on branch `<clone branch>-e2e`. The worktree root is the primary's `orbit.worktreeRoot`, default `/fast/worktrees/orbit`. |
| Mirror the work | Copies the clone's modified and untracked files, removes its deleted tracked files, and mirrors each `vendor/` directory without preserving source owners or groups |
| Run | Runs the bridge's `bin/e2e-topology`, with each clone path replaced by the bridge path and `--worktree` set to the bridge |

The registration is the first of `$XDG_STATE_HOME/orbit/e2e-primary-checkouts/{origin key}` (when set), `$HOME/.local/state/orbit/e2e-primary-checkouts/{origin key}`, and `/var/lib/orbit/e2e-primary-checkouts/{origin key}`. Its link must point to a checkout that is not a linked worktree. The primary owner is the invoking user or the owner of the invoking checkout.

`bin/e2e-topology register` writes that registration. The first live primary keeps it; another checkout replaces it only with `--force` or when the registered checkout is gone. A task VM has one checkout and no registration, so its commands run in place.

The origin key is the SHA-256 of the origin URL's lowercase host and its path, so every clone of one repository finds the same primary. The cleanup helper uses the same lookup order and owner rule.

Missing or dangling links and explicitly rejected registrations are successful no-ops. Once an eligible primary is registered, operational failures while probing its Git directories, origin, or worktree-root setting are cleanup failures, not an absent registration. An unset worktree-root key still uses the default. The helper returns nonzero before changing task or bridge resources, so teardown retains the task checkout for retry. [Primary registration](/reference/instance-setup#primary-registration) describes the shared registry and the worker's access grants.

State directories and operation locks allow access only to their owner and ACL-named users. The harness keeps existing ACL masks, including the managed user's grant to `orbit-worker`, when it opens the state root, ensures a parent directory, or acquires a lock. It removes access for the owning group and other users without recalculating those masks. An already-private owner-owned directory or lock needs no permission change when the worker uses it.

A managed-user run that creates a directory with explicit mode `0700` can mask inherited named-user ACL grants and cancel worker access despite default ACLs. Keeping an existing ACL mask does not repair that inaccessible directory owned by another user. The operator must restore its effective grants and keep state owning-group/other access closed. The task lifecycle must preserve effective worker access on state and bridge directories it creates.

The mirror leaves the bridge's other ignored files, such as `.e2e/`, `.env`, and Gateway storage, because the harness and the guests write them. So a file that a guest writes into the mount appears in the bridge, not in the clone. The evidence log is in the bridge too. Every command mirrors the clone first, so any command, such as `status`, pushes an edit. Set `ORBIT_E2E_BRIDGE=0` to run in the clone itself. `bin/e2e-topology-images` never bridges, because images belong to the host's Incus project, not to a checkout.

In a task workspace on branch `task-58`, use the allocated topology with commands such as `bin/e2e-topology exec TASK-58 gateway --argv='["orbit","node:list","--json"]'`. When the Gateway ends a task, the Orbit Project's configured teardown step runs the installed copy of `bin/e2e-task-cleanup` to remove the task's bridge. [Tasks](/reference/tasks#complete-and-cleanup) describes that cleanup. Orbit releases the group's topology, including any web session and loopback publication, before removing the workspace. Bridge removal itself does not release a topology.

Vendor mirroring keeps content, symbolic links, file timestamps, and deletion of stale entries. With rsync, it disables owner, group, and permission preservation after archive mode and omits directory timestamps. Existing destination permissions and ACL grants stay in place; new files use source permissions subject to the destination's defaults and the caller's umask, including executable permissions. This lets `orbit-worker` update a managed-user-owned bridge without trying to change its ownership, chmod owner-owned entries, or set timestamps on directories the worker does not own.

The helper checks the checkout identity and origin and the repository identity. It reads the same registration paths as the bridge, including the shared directory, and it accepts a primary owned by the checkout owner. A primary registered by the managed user is found after teardown runs as `orbit-worker`. The worker also needs to traverse and write the primary's `.git` and the bridge worktree root. [Primary registration](/reference/instance-setup#primary-registration) records the copy and that grant.

It targets `task-{id}-e2e` under that primary's worktree root. It removes a registered worktree only when both its path and branch match. A bridge checked out on another branch stays. It deletes the task bridge branch only when no worktree has it checked out, and deletes only `refs/orbit/e2e-bridge/task-{id}`. It never removes another task's bridge or releases a topology.

An absent bridge, or a checkout with no registration, is success. A registration that exists is not that case. A cleanup command failure exits nonzero and makes teardown retain the task checkout and Instance for retry. Removal of a matching bridge, its unused branch, and its staging ref is idempotent. The helper does not alter the checkout that runs it or that checkout's worktrees.

## Release

### Order

`release` checks every recorded guest against the attempt's ownership metadata. It stops the owned web session's dev-server process and removes its loopback publication. It force-stops any running recorded guests, deletes them, and verifies that every recorded guest is absent. It checks the network's ownership just before deleting it.

Only after web-session cleanup succeeds, every recorded guest is absent, and the network is removed does release drop the lease and topology record. It never drops topology state while a recorded guest remains. The output lists `released`, `already_absent`, and `networks_reaped`.

### Failures

If an ownership mismatch or cleanup failure occurs, release stops and keeps every unrelated resource. The lease and attempt record stay for diagnosis and retry. A retry continues from the same exact target and handles already-absent owned guests without selecting new resources.

### Orphan networks

Every network named `oe-*` belongs to the harness and never outlives its topology or image build. Every release ends with an orphan sweep. The sweep selects networks by that name prefix, not by ownership metadata. It deletes each one in the configured project whose `used_by` list is empty. It holds the `topology-create` lock, so it never deletes a network that a starting acquisition just created.

### After a merge

`bin/worktree-remove ISSUE` cleans up after a merge. It refuses a dirty worktree and a branch that `origin/main` does not contain, queues a TIA cache refresh, releases the discovery topology, removes the worktree, deletes the merged branch, and prunes the worktree list.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### The reviewer requests discovery on a shared host

This reason applies to shared task workspaces. A task VM group declares its topology up front, because the declaration decides the VM size and the reviewer must prove the work on those nodes.

Ordinary groups use no topology resources. Acquisition at workspace provisioning was rejected because most groups do not need discovery and startup should not depend on it. The reviewer decides when discovery helps; the existing consult gives implementers a way to ask without granting agents host sudo or adding another request API. Orbit owns acquisition because it changes host firewall rules. A topology is not proof of correctness, so acquisition failure or absence never prevents approval. This serves lean resource use and the principle that agents operate while humans steer.

### Composed from role images

A topology starts only the Nodes the work declares, from one prepared image per role, and the harness wires them together for that topology.

One shared whole-topology snapshot was rejected. It baked every Node, the Gateway database, the CA, and every key into a single generation. So it could not start a subset, and its extension Node took a cold build of about 20 minutes. Every change to prepared state needed a refresh, promotion, and recovery machinery. A snapshot for each combination of Nodes was rejected for the same reasons, multiplied.

Role images hold no identity, so a topology always has its own Gateway, CA, and keys. Composition takes longer than cloning a snapshot, and that is acceptable: start time matters little for the groups that need a topology, and a composed topology is simple and the same every time.

The images run the programs that enrollment renders, the way the task VM base image does, so enrollment on a composed Node finds the work done. Enrollment still runs every program, so a stale image is slow, never wrong.

### Declared roles only

The harness assigns the base roles of each declared Node and the roles its entry adds, and nothing else. The old profile also added a Cluster, Router, Ingress, Metrics, a database role, WebSocket, and sample Laravel applications to every topology. Building and hydrating those samples was the slowest and most fragile part of preparation, and most tasks did not need them. A declared role is added with `orbit node:role:add`, the product path the fleet uses, so the topology is set up the way a real one is. Anything else a task needs, it adds with the CLI.

### Nested in the task VM

A group's topology runs inside the group's own task VM, so the VM edge stays the only boundary and an Orbit task VM is like any other task VM. The inner Incus uses a btrfs loop pool: a `dir` pool copies every disk in full, and ZFS on a ZFS volume caches twice. The isolation rules live in their own nftables table at a priority before the filter table, because the harness inserts its own forward rules at the top of the iptables `FORWARD` chain for each topology network.

### An isolated operator for web work

The operator is a small roleless container rather than another workload VM. Its own WireGuard identity, Gateway grant, CA, and pinned endpoint keep UI actions on the disposable fleet. Joining the topology tunnel on the host was rejected: the host may have a live identity, and topologies reuse address ranges. Running experiments against the real Gateway or falling back to a live profile was rejected because a missing disposable configuration must never turn a safe experiment into a live operation.

The web session owns both its systemd unit and its Incus proxy device. The proxy publishes only on host IPv4 loopback; SSH forwarding gives Mac access without a public listener, DNS entry, or Caddy Route. Those alternatives add shared fleet state and exposure. Concurrent groups need OS-assigned host ports rather than a fixed port. The guest port stays fixed and strict so a conflict fails visibly, rather than silently opening the wrong server. Stopping the session removes both resources; release retries cleanup without forgetting ownership on failure.

### Exact ownership

Every resource records its owner, issue, attempt, and operation when the harness creates it. Setup and cleanup change only recorded resources, after they check the live metadata again. They never select by prefix, glob, age, broad query, or unset variable. A resource without the expected metadata is outside the topology, even when its name looks right. So a mistake cannot delete another person's machine or a production resource. The one exception is the orphan network sweep. It selects by the harness name prefix, and deletes only networks that no guest uses.

### Topologies are never production

Topologies never reuse production resources or credentials. Evidence from a topology never authorizes a production deployment.

### A live mount

Discovery mounts the worktree, so an edit reaches the guests at once. Copying source into the guests after every edit is a rejected alternative, because it is slow and hides which source the guests run.

### Long-lived processes as systemd units

systemd already supervises, logs, and stops processes. Detaching inside `exec` with `nohup`, `setsid`, or a double fork is a rejected alternative, because it depends on shell skill and keeps no output. A separate process supervisor on the guests is also rejected.

### Chosen evidence entries

The harness records an `exec` only when you give it a label. Most commands are exploration, and a proof summary needs chosen, labelled entries. The log is plain text, not JSON, because people read and grep it, and JSON hides line breaks in output.

### A quick sync keeps the verified binding

`sync --quick` does not record the new source. Otherwise `status`, `verify`, and the guest source marker would describe a binding that no readiness check has seen. A full `sync` stays a complete readiness check.

### A bridge for task workspace clones

The harness expects every topology on a shared host to belong to a linked worktree of the primary checkout, which holds the host locks. The bridge gives a clone that shape. Teaching the harness to accept clones is a rejected alternative, because it changes about ten identity checks and the guest mount evidence. Mirroring with `rsync --delete` is also rejected, because it deletes the files that the harness and the guests keep in the mount. Finding the primary through the main cache store is rejected, because topologies would then depend on published test caches.

### Cleanup stays with the Project

The bridge layout belongs to this repository, so the Orbit Project's teardown runs the installed helper. Putting that removal in the Gateway was rejected, because the engine would then know one repository's worktrees. A failed cleanup exits nonzero and teardown keeps the checkout for retry. The helper does not release the topology.
