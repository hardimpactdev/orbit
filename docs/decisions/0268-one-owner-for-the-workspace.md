---
title: "ADR 0268: One owner for the workspace"
sidebarTitle: "0268 One workspace owner"
description: "In progress. `agent` owns a development container's workspace. It runs Orbit's checkout Git calls and the Project's development deploy steps. `orbit` stays the app user: FrankenPHP, queue workers and every Orbit Process. Root's group pass also makes `agent` the owner of each workspace entry that `orbit` owns."
---

# ADR 0268: One owner for the workspace

`agent` owns a development container's workspace, `/srv/workspace`. It runs Orbit's checkout Git calls and the Project's development deploy steps. `orbit` stays the app user: FrankenPHP, queue workers and every Orbit Process. Root's pass that keeps the workspace writable for its group also makes `agent` the owner of each entry that `orbit` owns.

## Status

In progress.

The owner decided on 2026-11-16.

Principle: [One way, one name](/mission#principles): one user owns the workspace, and one pass keeps it so.

## Context

In a default, the development deploy steps and the checkout's Git calls ran as `orbit`. Agents in copies run as `agent`. The files a step made in the default, such as compiled Blade views, `vendor/pestphp/pest-plugin-browser/.temp` and `.pest/tia`, reached each copy through the seed, owned by `orbit`.

The workspace already gives the group `work` write access: setgid folders with mode 2770, a default ACL, and root's `g+rwX` pass ([Who runs what](/reference/development-containers#who-runs-what)). So group write was not the cause. Three calls are open only to a file's owner: `chmod`, `utime` with explicit times, and `setfacl`. When `agent` made one of these calls on an `orbit` file, it failed with EPERM. On 16 November 2026, 29 browser tests failed in task group 5464 of `dlf-leden`, and copy 3472 exited 255 on `playwright-server.json`.

## Decision

The owner decided these points, and they stay closed:

- **Q1. Keep the names.** `agent` (uid 2750) is the owner of the workspace and runs the steps. `orbit` (uid 1000) is the app user. No user is renamed and no uid changes.
- **Q2. Sudo for `agent`.** Today the normal image gives `agent` no sudo, and the [nested-fleet image](/reference/development-containers#the-nested-fleet-image) gives it passwordless sudo. The owner decided that `agent` gets passwordless sudo in every container, normal and nested, and that membership of `incus-admin` stays as it is. That comes with a security model in which a container holds no production-grade secrets. A separate task group builds it; the [amendment](#amendment-sudo-for-agent-in-every-container) below records it.
- **Q3. Development deploy steps run as `agent` everywhere**, nested-fleet defaults included.

From those points:

- **Who runs what.** As `agent`, with `HOME=/home/agent`: the checkout's Git calls (the first `git init`, the fetch, the checkout, the repack and its clean-up), the program that names `.env` in the checkout's exclude file, the reads and writes of the deploy steps' record, and the wrapper of each development deploy step in a deployment, a create and a proof run. As `orbit`: FrankenPHP, every Orbit Process and Schedule, the Node store, and the nested fleet's commands as the app user (`fleet_as_app_user`).
- **One owner.** Root's pass "Keep the workspace writable for its group" also gives `agent` each entry that `orbit` owns. It changes the owner through the entry it opened without following a link. A link or a socket gets only its own new owner, never its target's. A file it gives away loses its setuid and setgid bits, so `orbit` can never make a program that runs as `agent`. An entry that is not a folder and has a second name, a hard link, keeps its owner: its other name may be outside the workspace, where the pass must change nothing. Its mode follows the group rule as before. An entry that is gone needs nothing, as for the mode, and an entry that a link replaced is skipped. The pass runs before the deploy steps, after them, before a proof run's steps, and in the `services` part of a clone's create. One program does it in both places: `swamp/ansible/instance_workspace_owner.py`. The pass names each entry it changed, up to 20; above that it prints only the count.
- **What the app writes.** The running app may make `orbit` files between two deployments, such as in `storage/` and `bootstrap/cache`. They stay writable for `agent` through the setgid folders and the default ACL. The next pass gives them to `agent`.
- **The model route.** [ADR 0252](/decisions/0252-reach-the-model-router-from-containers-through-a-node-forwarder) holds: `orbit` stays off the model route. A deploy step runs as uid 2750, so it can reach the model forwarder. That is the same exposure the agent itself has: the steps are the Project's own commands, which an agent in the workspace may already run.

### Migration

An existing default gets the new owner at its next deployment: the pass runs at its checkout and after its steps. The seed that the deployment takes then carries it to new copies. A copy made from an older seed gets it in the `services` part of its create. A running copy keeps its files until it ends. Nothing else is needed.

### The nested fleet

The nested fleet's guests mount the checkout with `raw.idmap` `both 1000 1000`, `uid 2750 2750` and `gid 2760 2760` ([instance-fleet.yml](/reference/development-containers#fleet-rules)). So an `agent:work` file keeps its owner and group in the guests, and the inner `orbit` is a member of `work` there. On 16 November 2026, in nested-fleet copy 3481, the inner Gateway's `orbit` read and wrote a `2750:2760` file with mode 0660 in the checkout, and PHP ran an `agent`-owned script that loads the Gateway's autoloader. The map stays as it is.

### Amendment: sudo for `agent` in every container

The owner decided on 2026-11-16 to build Q2.

- **The grant.** `agent` (uid 2750) has passwordless sudo in every development container, from the normal image and from the [nested-fleet image](/reference/development-containers#the-nested-fleet-image). One file holds it: `/etc/sudoers.d/orbit-agent`, root:root, mode 0440, with the one line `agent ALL=(ALL:ALL) NOPASSWD: ALL`. Both images take it from the same definition, `InstanceImage::shared()`. `orbit` (uid 1000) has no sudo. `incus-admin` stays nested-only. The image build refuses an image with any other sudo path.
- **Existing containers.** The grant is one of the image's [shared files](/reference/development-containers#existing-containers). The Node's converge writes it into every existing container, defaults and copies, normal and nested. A clone writes it before its first start, so a copy of an older seed has it too. Every container already has the `sudo` program: the stock Ubuntu image ships it.
- **Security model.** A container holds no production-grade secrets, only dev-scoped keys. That is the rule for anything Orbit or a Project puts into a container.
- **Reason.** Convenience: the agent installs a package or fixes a system file itself and never needs outside help.
- **Trade-off.** Root in a container can read that Instance's `.env` and its per-copy model key, and can change the container's own firewall, which holds the model-route rule of [ADR 0252](/decisions/0252-reach-the-model-router-from-containers-through-a-node-forwarder). So that rule no longer keeps `agent`'s own processes apart; it still keeps `orbit` and the app off the model route while nobody uses root to change it. The container wall stays the boundary: the container is unprivileged, with an isolated ID map, and root in it is no user on the Node.
- **Existing sudo use stays.** The task check's `sudo -n systemd-run`, `bin/e2e-topology-host-setup` and `reconcile-firewall.py` work as before.

## Rejected alternatives

- **`orbit` owns the workspace and the agent runs as `orbit`.** The agent would read the app's secrets and reach what a public preview reaches. It breaks the user boundary of [ADR 0250](/decisions/0250-run-instances-and-task-groups-in-incus-containers).
- **More group rights.** The workspace already gives the group full access. The calls that failed are open only to the owner, and no mode or ACL opens them.
- **Sudo for `agent` to fix the owner itself.** Root's pass already fixes the owner from outside the container, at each deployment and each clone's create. So the agent needs no root for it, and the fix does not depend on the image.
- **A one-off fix of the existing workspaces.** The pass already runs at each deployment and each clone's create, so they converge without a separate step.

## Consequences

- The deploy steps write their files as `agent`. A step that needs to be `orbit`, such as one that writes a file only the app may read, has no way to do so. No such step is known.
- A file that the running app makes between two deployments stays `orbit`'s until the next pass. An agent can read and write it through the group, but cannot change its mode or its times.
- A pass after a long time without a deployment may change many owners at once. Its output stays short: one count line.
- A clone's create runs the same pass as a deployment. Its `services` part no longer has its own copy of the mode rule.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: [0250](/decisions/0250-run-instances-and-task-groups-in-incus-containers), [0252](/decisions/0252-reach-the-model-router-from-containers-through-a-node-forwarder)
- Detail: [Who runs what](/reference/development-containers#who-runs-what), [Inside a container](/reference/development-containers#inside-a-container), [Existing containers](/reference/development-containers#existing-containers), [`instance:deploy`](/cli/instance/deploy)
- Verify: the Python test of the pass (`swamp/tests/instance_workspace_modes_test.py`), the clone guest's tests (`swamp/tests/instance_clone_guest_test.py`), the container phase's Deno tests and the Pest tests of the step argv
- Verify the amendment: the image tests (`apps/gateway/tests/Feature/NestedFleetImageTest.php`, `apps/gateway/tests/Feature/Domain/Instances/InstanceHostIntentTest.php`) and the image build's audit (`swamp/tests/ansible_instance_host_image_test.ts`)
