---
title: "Fixture retirement: instance containers"
description: "Status: kept as fixtures until the math fits. How each case of the instance-container fixtures would move to a proof case, and the proof infrastructure and wall time that the move needs."
---

# Fixture retirement: instance containers

**Status: kept as fixtures until the math fits.** The proof of these cases does not fit in the five minutes that a pull request may take ([Implementation loop](/reference/implementation-loop)). So the three fixture files and their registries stay as they are. This page records what the move would need, so that a later decision can start from it. See [The five-minute math](#the-five-minute-math).

[ADR 0262](/decisions/0262-prove-changed-operations-on-the-pull-request#build-order) retires the shape-gate fixtures. This page classifies each case of the first family that it reviewed:

- `swamp/tests/ansible_instance_container_test.ts` (80 cases)
- `swamp/tests/ansible_instance_container_destroy_test.ts` (4 cases)
- `swamp/tests/ansible_instance_container_power_test.ts` (18 cases)

Their registries are the three files of the same name under `.github/ci/fixtures/swamp/tests/`. A case is one `Deno.test` call. A loop that makes several tests is one case, and its row gives the number of variants.

## Classes

Each case gets one class.

| Class | Meaning |
| --- | --- |
| **New** | A new [proof case](/reference/verification-cases) takes the case over. The row names the command and the case id. |
| **Covered** | A proof case that another row of this page adds also checks this effect. |
| **Copy path** | A realistic nested-fleet or proof-copy behaviour. It stays a fixture case under the [exception of ADR 0262](/decisions/0262-prove-changed-operations-on-the-pull-request#exception-the-code-that-makes-the-proof-copy). A rare one is designed away or dropped, as any other rare case. |
| **Designed away** | A rare situation that the design makes impossible. The row says how. |
| **Dropped** | A rare situation that a proof copy cannot make on demand, such as a fault or a crash between two calls. The row says why. |
| **Pure-code** | Code with no playbook, workflow run or Node behind it. It stays as a quick check until the owner decides ADR 0262's open question. |

The instance proof cases of today only check refusals of the CLI, so none of them covers a fixture case. When a case checks several things, the row gives the class of its main behaviour and names the parts that are dropped or designed away.

## ansible_instance_container_test.ts

This file runs the shipped `instance-container.yml` and the Instance host preparation against an Incus stand-in.

| Line | Case: what it proves | Class | Proof case or reason |
| --- | --- | --- | --- |
| 219 | First build makes the container, checks out the default branch, starts FrankenPHP and seeds it; a rerun changes nothing | New | `instance:create` `create-default` |
| 282 | A converted default store refreshes its same-commit seed once | Designed away | A one-time conversion: only a default made before the private Node store converts its old shared store, once. No default made today takes this branch |
| 315 | A deployment at the seed's commit retakes the seed | New | `instance:deploy` `deploy-same-commit` |
| 359 | A retake with no free isolated ID range keeps the old seed and names the cause | Dropped | Needs a Node with every isolated ID range in use. The old seed stays until a new one exists |
| 411 | A retake stopped between the delete and the rename is finished first | Designed away | Proposed (not built): every retake takes its new seed under `next-` first, as the pair retake (line 3401) does. Until it is built, the case stays |
| 438 | A deployment sends only what the workspace lacks, also after a force-moved branch | New | `instance:deploy` `deploy-force-pushed`. Local commits in a default: designed away, a default has no T3 and no user writes |
| 486 | A deploy step with inputs is skipped at the same inputs, and runs when one moves | New | `instance:deploy` `deploy-step-skip` |
| 533 | A proof checkout fetches the commit by SHA and checks it out detached | Copy path | A missing commit: dropped |
| 582 | A deployment checks out the new commit, seeds it and deletes the older seed | New | `instance:deploy` `deploy-new-commit` |
| 626 | A deployment refuses a pinned commit off the branch | New | `instance:deploy` `deploy-commit-off-branch`. Dirty or diverged work: designed away, as in line 438 |
| 671 | One play of a merged deploy step runs its phases and their handlers in order | Covered | `deploy-new-commit`: the Processes restart on the new commit. The hung fetch: dropped |
| 748 | A start links `php` to the Project's PHP version, and a Project without PHP has no link | New | `instance:create` `create-no-php`; the version is checked by `create-default` |
| 784 | A clone copies the seed, takes its own address, ACL, firewall rules and T3 port, and starts T3 | New | `instance:clone` `clone-from-seed` |
| 894 | The agent phase keeps runtime JSON keys and writes the managed keys | Covered | `clone-from-seed`. A repair of keys edited by hand: dropped |
| 967 | A clone refuses a seed that a deployment replaced; a silent T3 fails it | Dropped | A race between a clone's copy and a deployment, and a T3 fault |
| 1016 | The first build installs the Processes as `orbit` units with environment files from the vault; a changed value replaces its file | New | `instance:deploy` `deploy-changed-process-value`; the units are checked by `create-default`. A drifted web configuration: dropped, an edit by hand |
| 1073 | A deployment's new seed restarts the running Processes on the new commit | Covered | `deploy-new-commit` |
| 1108 | A deployment starts and waits for a new Process | New | `instance:deploy` `deploy-new-process` |
| 1150 | A new Process that does not start fails the processes phase by name | New | `instance:deploy` `deploy-process-fails` |
| 1194 | A Process's start, stop, restart and destroy change only its own unit | New | `process:start` `container-start`, `process:stop` `container-stop`, `process:restart` `container-restart`, `process:destroy` `container-destroy` |
| 1267 | A Process whose unit does not come up fails with the shared code; a stopped container has no unit to change | New | `process:start` `container-start-fails`, `process:stop` `container-asleep` |
| 1314 | A deployment's start and restore-start do no web wait and no marker work | Covered | `deploy-new-commit`, `deploy-fails-restores`: no asleep marker appears |
| 1333 | A wake's start removes the marker once the app answers, through a short 5xx | New | `instance:wake` `wake-stopped` |
| 1361 | A wake keeps the marker when the web unit never answers | Dropped | A fault of the Project's app. The marker stays, so the next visit tries again |
| 1384 | A wake removes the marker before the T3 wait; a silent T3 fails the wake | Dropped | A T3 fault |
| 1413 | A wake of a Project without a web unit removes the marker without a web wait | New | `instance:wake` `wake-no-web` |
| 1474 | A wake of a Frozen container restores and resumes it and closes swap, with no boot | New | `instance:wake` `wake-frozen` |
| 1507 | A wake after the Node's fast wake reports that wake's ready file | New | `instance:wake` `wake-by-visit`. Another Operation's file: dropped, concurrent wakes |
| 1548 | A resumed container whose web unit stays silent is stopped and booted | Dropped | Needs a resumed app that stays silent |
| 1567 | A wake boots a Stopped container, verifies a Running one, and reports a Frozen one without a marker | New | `instance:wake` `wake-awake`; the Stopped one is `wake-stopped`. Frozen without a marker: designed away, the sleep writes the marker before it freezes |
| 1605 | A wake closes the swap of the container and of each inner cgroup the sleep opened | Covered | `wake-frozen`. The inner cgroups: copy path |
| 1624 | A deployment's start brings a sleeping container back and removes its marker | New | `instance:deploy` `deploy-asleep` |
| 1648 | A wake of a nested-fleet container resumes a Frozen one and never boots a Stopped one | Copy path | |
| 1681 | A deployment's start resumes a Frozen container, then converges | Covered | `deploy-asleep` |
| 1710 | A clone runs its own Process units, not the default's, also at its next boot | Covered | `clone-from-seed`, `wake-stopped` |
| 1766 | A clone's first boot starts no unit of the default; it has its own machine-id | Covered | `clone-from-seed` |
| 1814 | The workspace stays group-writable around the deploy steps; Git excludes `.env` once | Covered | `create-default`, `deploy-new-commit` |
| 1863 | The agent phase trusts only its workspace and keeps other Git settings | Covered | `clone-from-seed` |
| 1908 | The agent commits with its default identity and keeps a configured one | Covered | `clone-from-seed` |
| 1957 | Container PHP reads the shared memory limit, Data Bridge and CLI PCOV; a Project without PHP has no `php-cli.ini` | Covered | `create-default`, `create-no-php`. A repair after a hand edit, and a module the Node lacks: dropped |
| 2032 | The access plan's private names go into `/etc/hosts` and the proxy environment, and go when the plan has none | New | `instance:node-service-attach` `container-attach`, `instance:node-service-detach` `container-detach`. The machine name: `create-default` |
| 2096 | Start refuses a NIC with a missing ACL, a permissive default or the bridge union ACL (3 variants) | Designed away | Create, clone and migrate write the NIC with its ACL in the one call that makes the container. Nothing else writes it |
| 2122 | Start refuses a NIC without the address filters | Designed away | The same call writes the filters |
| 2156 | Start refuses an unguarded extra bridge NIC | Designed away | As line 2096 |
| 2181 | The first attach adds an exact bridge endpoint and repeats without changes | Covered | `container-attach`. An ETag conflict: dropped, a race |
| 2207 | A copied seed with an unguarded extra NIC refuses before the clone starts | Designed away | As line 2096 |
| 2233 | A queued Process start does not block, and each running unit has a bounded readiness read | Covered | `create-default`: the Processes run |
| 2265 | The PHP ini write and restart come before the Process start | Covered | `deploy-new-commit` |
| 2306 | A Process that takes thirty seconds to stop stops | Covered | `container-stop`: the fixture worker takes that long to stop |
| 2348 | Nested-fleet start gate (14 variants) and root disk sizes (4 shapes) | Copy path | The damage variants: designed away, only the converge and the create write these keys |
| 2435 | A normal start never writes `boot.autostart`; a stopped one gets the machine name before its boot | Covered | `create-default`. Pending Incus templates: dropped |
| 2496 | A nested-fleet create refuses while the Node records a failed nested-fleet image build | Copy path | |
| 2518 | A nested-fleet create uses the nested-fleet image; a normal create never reads the record | Copy path | The normal half: `create-default` |
| 2555 | A new nested-fleet default gets its inner volume, disk and inner pool in order | Copy path | |
| 2588 | A default without an inner volume makes no volume, disk or inner pool | Designed away | The nested-fleet shape without an inner volume goes with its conversion. The normal half: `create-default` |
| 2617 | The start gate admits the inner volume only with its exact keys | Copy path | The refusals: designed away, as line 2348 |
| 2684 | A normal host intent runs the exact tasks, Incus calls and host files of main | New | `node:converge` `instance-host`. The transcript pin: dropped, it pins the code, not an effect |
| 2707 | A switched-on host with a short subuid or subgid range refuses (2 variants) | Dropped | A bootstrap fault of the host |
| 2730 | Only a switched-on host prepares the nested project, profile and modules | Copy path | The normal half: `instance-host` |
| 2769 | The nested clone probe refuses a damaged profile (2 variants) | Designed away | Only the converge writes the profile |
| 2800 | A changed shared folder restarts the running runtimes, then clears its marker (2 variants) | New | `node:converge` `instance-host-shared-folder`. The nested variant: copy path |
| 2897 | A nested-fleet create and deploy deliver the Club key into `/run` by stdin, and nothing records it | Copy path | |
| 2933 | A stopped nested-fleet container loses the key; the deploy start delivers it again | Copy path | |
| 2961 | A nested-fleet start without the vault copy starts without the key | Copy path | |
| 2985 | A nested-fleet start refuses an unsafe key transfer (2 variants) | Designed away | The shipped `ansible.cfg` turns on pipelining, and no Orbit step keeps remote files |
| 3003 | A nested-fleet start refuses a `/run` that is not a tmpfs | Designed away | The image's systemd mounts `/run` as a tmpfs |
| 3019 | A normal container runs no Club key task | Covered | `create-default`: no key in `/run` |
| 3048 | A nested-fleet default runs its fleet phases by tag and refuses a Club key on its disk | Copy path | |
| 3185 | A default with an inner volume seeds, retakes and keeps one container and volume pair | Copy path | |
| 3247 | The proof default's retake keeps the seed that a CI Node holds as its base | Copy path | |
| 3270 | The proof default's retake at a new commit keeps the held base and drops the others | Copy path | |
| 3292 | The proof default's retake keeps the base while the Gateway names it | Copy path | |
| 3326 | The inner volume is flushed before its half of every seed | Copy path | A failed flush: dropped |
| 3373 | A failed volume snapshot keeps the older pair | Dropped | An Incus fault |
| 3401 | A same-commit pair retake takes both halves under `next-` first | Copy path | |
| 3444 | A `next-` pair left by an earlier run takes the seed's name; a half is dropped | Dropped | A crash between two snapshot calls. The [reviewer rule](/reference/tasks#the-reviewer-rule) asks no guard |
| 3485 | A failed deployment of a default with an inner volume restores both halves | Copy path | |
| 3516 | A default converted in place starts only with both records | Designed away | A one-time hand conversion of a default made before the inner volume ([Convert a default](/reference/development-containers#convert-a-default)). No default made today takes this branch |
| 3570 | A normal default seeds, retakes and restores with no volume call | New | `instance:deploy` `deploy-fails-restores`. The shape without an inner volume: designed away, as line 2588 |
| 3599 | A failed nested-fleet deployment restores the newest seed, stopped | Copy path | |

## ansible_instance_container_destroy_test.ts

This file runs the shipped container deletion against the Incus stand-in.

| Line | Case: what it proves | Class | Proof case or reason |
| --- | --- | --- | --- |
| 19 | A normal destroy deletes the container with the shipped Ansible settings | New | `instance:destroy` `destroy-clone` |
| 30 | A sleeping container's destroy removes its container, ACL, own rules, marker and mirror; a retry changes nothing | New | `instance:destroy` `destroy-asleep`. Retained Git of a migrated checkout goes with migration as an old path. A rule-number shift and a retry: dropped, a race and a fault |
| 262 | A destroy deletes the inner volume its intent names, with its snapshots | Copy path | |
| 332 | A proof copy's destroy releases its CI place only after the container is gone | Copy path | |

## ansible_instance_container_power_test.ts

This file runs the one phase of a sleep against the power helper stand-in, and checks the steps of the sleep, wake and start workflows.

| Line | Case: what it proves | Class | Proof case or reason |
| --- | --- | --- | --- |
| 18 | The sleep and the wake each run one charted phase with the shipped Ansible settings | Covered | `sleep-awake`, `wake-frozen` |
| 40 | Every step that runs `instance-container.yml` names its Operation, which the helper's restore records | Covered | `wake-by-visit` |
| 54 | A nested-fleet clone's start runs the deploy start with the Club key, then the fleet check and resume | Copy path | |
| 82 | A start's result adds the fleet's seconds when the fleet step ran | Pure-code | A shell expression of the workflow |
| 100 | The fleet step writes its milliseconds and keeps the exit code of the failed phase | Pure-code | A shell expression of the workflow |
| 212 | A sleep refuses when Caddy's loaded site lacks the asleep check | Dropped | Only a site published before the check existed lacks it |
| 219 | A sleep writes the marker, prepares through the helper and reads counters until RAM holds nothing | New | `instance:sleep` `sleep-awake` |
| 233 | A sleep without a published Route or web unit checks no Caddy site | New | `instance:sleep` `sleep-no-route`, on a clone without a Route |
| 241 | A sleep intent without `route` fails before it changes anything | Designed away | The Gateway always writes `route` as true or false. Pest owns the intent's shape |
| 258 | A nested-fleet sleep counts the inner cgroups the helper opened | Copy path | |
| 266 | A sleep whose memory stays in RAM resumes and stops the container | Dropped | Needs a Node whose swap cannot take the memory |
| 278 | A nested-fleet sleep whose memory stays in RAM never stops | Dropped | As line 266 |
| 289 | A failed stop after a full swap leaves the container serving | Dropped | An Incus fault |
| 297 | A failed resume after a full swap leaves the container frozen behind its marker | Dropped | An Incus fault |
| 305 | A failed pause leaves the container running and fails with the helper's code | Dropped | An Incus fault |
| 314 | A sleep leaves a Frozen container with its earlier result | New | `instance:sleep` `sleep-asleep`. A Stopped one: dropped |
| 327 | A busy helper lock is tried again; a crash fails by name | Dropped | Lock contention and a crash. The helper's own tests keep the lock |
| 343 | The role probes the helper's primitives through its own unit, which holds no `CAP_KILL` | Covered | `instance-host`: the probe runs in every converge |

## Counts

This table counts the cases of each class.

| Class | Container | Destroy | Power | All |
| --- | --- | --- | --- | --- |
| New | 23 | 2 | 3 | 28 |
| Covered | 18 | 0 | 3 | 21 |
| Copy path | 20 | 2 | 2 | 24 |
| Designed away | 11 | 0 | 1 | 12 |
| Dropped | 8 | 0 | 7 | 15 |
| Pure-code | 0 | 0 | 2 | 2 |
| All | 80 | 4 | 18 | 102 |

No case is covered by a proof case of today.

The new rows name 33 proof cases:

| Cases file | Cases |
| --- | --- |
| `instance/create.cases.yaml` (new) | `create-default`, `create-no-php` |
| `instance/deploy.cases.yaml` (new) | `deploy-new-commit`, `deploy-same-commit`, `deploy-force-pushed`, `deploy-step-skip`, `deploy-commit-off-branch`, `deploy-changed-process-value`, `deploy-new-process`, `deploy-process-fails`, `deploy-fails-restores`, `deploy-asleep` |
| `instance/clone.cases.yaml` (new) | `clone-from-seed` |
| `instance/destroy.cases.yaml` | `destroy-clone`, `destroy-asleep` |
| `instance/sleep.cases.yaml` | `sleep-awake`, `sleep-no-route`, `sleep-asleep` |
| `instance/wake.cases.yaml` | `wake-frozen`, `wake-stopped`, `wake-awake`, `wake-by-visit`, `wake-no-web` |
| `instance/node-service-attach.cases.yaml`, `instance/node-service-detach.cases.yaml` | `container-attach`, `container-detach` |
| `process/{start,stop,restart,destroy}.cases.yaml` (new) | `container-start`, `container-start-fails`, `container-stop`, `container-asleep`, `container-restart`, `container-destroy` |
| `node/converge.cases.yaml` | `instance-host`, `instance-host-shared-folder` |

## Infrastructure the new cases need

None of the new cases can run today: the nested `app-dev` Node of a proof copy has no Incus. The cases need four things.

### An Instance host in the proof fleet

The nested `app-dev` Node needs the container branch of its role ([Prepare an Instance container host](/reference/compute-drivers#prepare-an-instance-container-host)):

- Incus with the `orbit-instances` btrfs pool and the bridge `orbitinst0`.
- The prepared container image.
- The power helper.

This adds one level of nesting: a CI Node holds the proof copy, the copy holds the nested `app-dev`, and that Node holds the Instance container. The image build is long. So the copy's base snapshot holds the prepared host, and no batch builds it.

### Two fixture Projects in the nested Gateway

The cases create their Instances from two small Projects.

- `proof-web` has PHP, a web unit and one deploy step whose input is `composer.lock`.
- `proof-web` has one worker Process that takes about thirty seconds to stop.
- `proof-web` has a branch with prepared commits: a next commit, a commit whose deploy step fails, a commit off the branch, and a force-push target.
- `proof-plain` has no PHP and no web unit.

The nested Gateway must read their source without a GitHub App.

### Batches

The cases need three starting states.

- `instance-host`: the host is ready and no Instance exists. The create and converge cases run here.
- `instance-default`: the `proof-web` default exists with its seed. The deploy cases run here, in two batches.
- `instance-clone`: as `instance-default`. Each case makes its own clone in its setup. The other cases run here, in two or three batches.

Every case is a writer on `app-dev`, so the cases of a batch run one after another.

### New runner steps

The cases need five new steps.

| Step | Use |
| --- | --- |
| `instance_fixture` | Setup: make a default or a clone with the real CLI, and bind its id, name, address and Route. |
| `container` | Expect: the Incus state of a container, with its devices, keys and snapshots. |
| `container_exec` | Expect: the output of a command in a container, run as a named user. |
| `http` | Expect: the status and body of a Route. |
| `container_power` | Setup: stop or freeze a container, as a host reboot or a sleep leaves it. |

### Wall time

This is an estimate. No proof Node can host a container yet, so nothing is measured.

- A batch starts with the restore of `base` and the preparation of the fleet, then its setup.
- A create from the image takes some minutes: the checkout, the deploy steps and the seed.
- A clone, a sleep or a wake takes tens of seconds. A deployment takes about a minute.
- A batch of eight to ten cases takes about 10 to 15 minutes.

The 33 cases fill about six batches. On a pull request that selects all of them, the proof takes about 15 minutes of wall time.

## The five-minute math

A pull request's proof fits in five minutes only when its slowest batch fits. The wall time of one pull request's proof is about:

`restore + preparation + setup + (selected cases × case time) / (places × hosts per copy)`

The terms are these:

| Term | Today | What would make it fit |
| --- | --- | --- |
| Restore of `base` | Some seconds | Nothing to change |
| Preparation | The observer install and its wait on every batch, bounded by its budget in [Verification cases](/reference/verification-cases) | A base snapshot that holds the candidate's observers, so a batch only waits for the report |
| Setup | A create from the image: some minutes | A base snapshot with the inner Incus, the prepared image and the `proof-web` default with its seed. Setup then makes only clones |
| Case time | Tens of seconds for a clone, a sleep or a wake; about a minute for a deployment | Nothing to change: the cases run the real operation |
| Cases per batch | 8 to 10 | About 4 deployments, or 6 clone, sleep or wake cases, so that the cases fit in the time left after restore and preparation |
| Places | The free proof places of the CI Nodes when the pull request runs | One free place for each batch of the selection |
| Hosts per copy | 1: only `app-dev` hosts Instances, and every case is a writer there | 2: `app-prod` also hosts Instances, so two writers run at the same time |
| Selected cases | All 33 for a change to `swamp/ansible/instance-container.yml`, which every phase shares | A selection by phase: a change to the sleep phase selects the sleep and wake cases only |

All four changes are needed together. Then the full selection is about eight batches of under five minutes, which needs eight free places. A narrow selection needs one or two.

## Open

The owner decides these questions when the math is taken up again.

- **Pure-code.** The 2 pure-code cases wait for the open question of ADR 0262.
- **Fixture source.** The nested Gateway must read the fixture Projects without a GitHub App. Options: a public repository through the egress proxy, or a Git server in the fleet.
