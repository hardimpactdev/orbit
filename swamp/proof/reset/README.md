# Task-local nested fleet reset

Run this repository program through `tasks.script`, or use the same program as
an existing Project check hook on the allocated task VM:

```sh
bin/proof-fleet reset 3187                # build from scratch, about 230 s
bin/proof-fleet ready 3187                # copy the healthy fleet to ready copies
bin/proof-fleet reset 3187 --from-ready   # restore from ready copies, about 75 s; build and copy when there are none
bin/proof-fleet verify 3187 swamp/proof/reset/cases/smoke.sh swamp/proof/firewall/cases/allow-fresh-ipv4-tcp.sh   # fast when the copies are usable
bin/proof-fleet release 3187
```

`verify` takes one or more case programs. It refuses an empty list, a duplicate, and a program that is not candidate source (outside the candidate, or ignored). It resets the fleet once and runs the programs in the order given against that fleet. A program exits `0` (passed), `1` (failed) or `2` (unrun).

- After each case, the phase compares the firewall database records and the UFW rules on each Node with their state just before that case. A leftover fails that case, and its note names it. A failed case does not stop the next one.
- The whole case phase has a budget of `PROOF_BUDGET_SECONDS` seconds (default 1800, at most 2100). Each case gets the remaining budget as its timeout. A case that hits it is killed and is `failed` ("exceeded the budget"); every later case is `unrun`.
- If the fleet never becomes ready, no case starts and every case is `unrun` ("the test fleet was not ready").
- Programs get `PROOF_RESULT`, `PROOF_SOURCE`, `PROOF_GENERATION`, `PROOF_EVIDENCE` and `PROOF_CASE_INDEX` (1 for the first program).
- Evidence in the generation's evidence directory: `cases-N.log` for each case, `cases-started.json` (`{"generation", "programs"}`), `cases.json` and `status.json`. `cases.json` is `{"generation", "cases": [{"program", "verdict", "exit_code", "seconds", "note"}]}`, written after every case, so a failure keeps it.
- The last stdout line is one compact JSON object: `status`, `summary`, `exit`, `cases_ran`, `generation` and `evidence`. `summary` holds one `[letter, seconds]` pair per program in order (`p` passed, `f` failed, `u` unrun; seconds with at most one decimal, 0 for unrun), for example `[["p",12.4],["f",3.1],["u",0]]`. The line holds no `cases` (they stay in `status.json` and `cases.json`), because Swamp cuts step output at 4096 bytes and the summary takes 12 bytes a case. The exit status is 0 only when every case passed, and 1 when a case failed or is unrun. Initialization and admission failures keep their own nonzero codes.

A case that needs a clean fleet gets a new reset: that is a later step. Today every case shares the one generation and must clean its own fixtures.

This entrypoint never provisions
an outer VM, restages the candidate checkout, starts a production worker, or
changes the outer observer. Provision a missing task runtime through the existing
`tasks.provision` / `task-vm-provision` path instead. Prepare the attached seed
once with `sudo bin/e2e-topology-host-setup`.

## Network and operator

The nested fleet has a real WireGuard network, `10.45.0.0/24`. The nested Gateway is the hub (`10.45.0.1`), `app-dev` is `10.45.0.2` and `app-prod` is `10.45.0.3`. The outer VM joins as the roleless member `operator` (`10.45.0.10`) on its own interface, `wg-nested`. That interface never touches the real fleet's `orbit` interface or `10.44.0.0/24`. The native step `operator` sets up the outer VM's side. It installs the candidate CLI as `/usr/local/bin/orbit-nested`, so the real `orbit` on that VM is never replaced, and adds one profile, `nested`, that names the Gateway by address. It refuses to run when the CLI already has another profile, so it never switches a real default Gateway. Cases call `orbit-nested`. It syncs first (next section). A release disables and stops `wg-quick@wg-nested`, and the next reset enables it again. The Gateway recognises the outer VM as a WireGuard member, so a command arrives as it does from an operator machine. Keys live on the outer VM, so every reset agrees with them.

## `orbit-nested` syncs before each call

`/usr/local/bin/orbit-nested` runs `tools/orbit_nested.py` from the candidate. It lives outside `swamp/` because it is a tool the author runs, not a Swamp step: it passes any command through. The `operator` step also writes `~/.orbit/nested.json`: the checkout to sync from (the candidate, the author's own checkout) and the nested VMs to sync to. Every reset writes it again.

Before each call, `orbit_nested.py` runs the sync of the reset (`phases.sync_source`: `rsync -a --checksum --delete` into the Gateway and app-dev, the same excludes, `composer install` only where a `composer.lock` changed). It needs no daemon and no file watcher. When rsync moved or deleted a file in the Gateway, it runs the apply step (`guest.py candidate`: package discovery, migrations, the Swamp repository, a restart of the two proof services). It then runs the CLI, or the command:

```
orbit-nested gateway:status --json
orbit-nested exec gateway -- php artisan about
```

`exec NODE -- CMD` runs `CMD` as user `orbit` in the nested VM `gateway`, `app-dev` or `app-prod`, after the same sync. It starts in `apps/gateway` on the Gateway, in the checkout on app-dev and in `/home/orbit` on app-prod, which holds no checkout. Its exit code and output are the command's.

Calls take a lock, so two calls never sync at once. A call that fails halfway leaves `~/.orbit/nested.pending`; the next call runs composer in every directory with a lock file and the apply step again before it does anything else. When a sync moved files, one line on stderr tells how long the sync and the apply step took. Measured on a task VM: a call that moves nothing adds about 0.3 s to the CLI call (0.18 s alone, 0.46 s through `orbit-nested`); a one-file change in the Gateway adds the sync (0.3 s) and the apply step (1 s).

## Ready copies and the fast reset

A build constructs the whole fleet: 54 s of it creates VMs and the rest installs Orbit again. A fleet that is already
healthy can instead be stored and put back. `bin/proof-fleet ready GROUP` stops the three guests of the active
generation, copies each to `fleet-ready-gateway`, `fleet-ready-app-dev` and `fleet-ready-app-prod` (an Incus copy on the
btrfs pool, about 0.2 s each) and starts the originals again. `reset --from-ready` and `verify` then put the copies back
in place of the guests. The names `ready-*`, `ready2-*` and `ready3-*` belong to earlier hand-made experiments; nothing
here touches them.

**What a ready copy carries.** Nothing that belongs to one generation, so it never blocks release or cleanup. It has no
`user.orbit.e2e.*` label, so the inventory and the harness release see only the guests of an attempt. It has no `eth0`
device: a stopped NIC would keep the network in use, and Incus refuses a second VM that holds the same static MAC or
IP. The MAC, the IP, the subnet, the Gateway's Node ids, the creation time and one set identity are `user.ready.*` keys;
`user.ready.complete` is written last on all three, so a half made set is never used.

A copy keeps its own checkout at `/home/orbit/orbit` on its disk, with its dependencies. No guest shares a directory with
the outer VM. A build still shares the generation's source while it runs; `ready` copies that source onto the disk of the
Gateway and app-dev (a bind mount of `/` shows the disk below the share, and `rsync` fills it, without `.git`) before it
stops the guests, then drops the share device from the copies.

**No secret in a copy.** `ready` deletes the proof Swamp Club key from the running Gateway, scans its files for the key, fails with the paths if any holds it, and trims the disk (`fstrim -av`) so the deleted key's blocks are discarded; it also fails unless all three guests report `Stopped` before the copy. A restore (and the end of `ready`) pushes the key back in once the Gateway agent is up, then restarts Swamp serve and the worker. The contract for other tools is in [`swamp/proof/README.md`](../README.md#ready-copies-the-contract).

**Making copies.** `ready` first runs the readiness check on the fleet and stops if it is unhealthy. It scrubs and scans for the key, stops and copies, starts the originals, delivers the key again and repeats the checks on the restarted fleet. Only then does `ready-complete` write `user.ready.complete`; a failure runs `ready-discard`, which deletes the half-made set. A full reset runs `ready-drop` and deletes all copies, because it builds a new fleet.

**When copies are usable.** All three exist, stopped, with one complete set identity and no label or generation device.
They are for the subnet and the three addresses of the active generation, which also needs a fleet record (the
generation was published healthy; one built before the record existed has the same facts in its evidence). Their credentials were
minted less than 20 h ago, counted from `user.ready.minted` (the Swamp tokens that a build mints last 24 h and its certificates 2 days), and the digest of the boot fixes, the fixture and the agent pin is the candidate's. Otherwise
`verify` builds as it always did, and `reset --from-ready` builds and then makes new copies.

**The fast reset** is the workflow `proof-fleet-reset-ready`; every step is named there. Measured on a task VM, three runs took 78.7, 72.7 and 71.3 s from the command to a checked fleet, against 233 s for a build. Boot is about 50 s of it (`wait` ends 49 to 57 s after the start); the export of the candidate is about 10 s and the readiness check 3 s.

1. `inventory` pins the candidate, as in a build.
2. `snapshot` exports the pinned candidate to a new generation. The generation inherits the previous one's attempt lease
   (`.e2e`), its runtime state (the Gateway `.env`, caches, logs, the agent manifest) and the ACL on the directories that
   lead Caddy to `public`. It becomes the active generation last, so an earlier failure leaves the previous one active
   and releasable. A later release removes the same guests and network, because the lease names them.
3. `restore` deletes the three guests, copies each ready copy back under the generation's name (without snapshots),
   makes it stateful if the copy is not yet, labels it for the attempt, adds a NIC on the generation's network with the
   saved MAC and IP and starts all three. It adds no source share. It records the time just before the start.
4. `candidate` waits for the guest agents (`incus wait agent`), then syncs the generation's source into `/home/orbit/orbit`
   of the Gateway and app-dev, in parallel. The sync is plain `rsync -a --checksum --delete`, with `incus exec` as the transport (a fresh export has new file times, so rsync compares content). It
   leaves out `.git`, `vendor`, `node_modules`, `.env`, `storage`, `bootstrap/cache`, `.e2e` and the agent manifest, which the
   guests keep. `composer install` runs in a directory only when rsync wrote that directory's `composer.lock`; it takes
   about 10 s even when nothing changed, so it never runs otherwise. Then the guest program applies the candidate in the
   Gateway while the Nodes still boot: Caddy access, `package:discover`, migrations, the Swamp repository (serve
   hot-reloads the workflows; its `.swamp.yaml`, which holds the datastore the build set up, stays), the extensions
   whose pin changed, and a restart of the two proof services (the queue worker and the agent-view subscriber). The
   receipt records the seconds of each rsync and each composer run.
5. `wait` blocks. Each wait is one command with a fixed bound of 150 s: `incus wait agent` for every guest and
   `systemctl start` for the Gateway units and the Node agents, which returns when the start jobs end (the agent unit's
   start waits for the Gateway). There is no loop and no sleep. It ends with a fresh WireGuard handshake.
6. `operator` pins the restored Gateway's CA and the single profile, as in a build.
7. `readiness` is the one readiness check, made once: every Node agent is online by the Gateway's presence heartbeat, the
   recorded facts exist, the Swamp worker is enrolled in serve, the Gateway answers the outer VM as a WireGuard member, scoped Doctor is
   healthy for each Node, and the firewall baseline for the cases is recorded.
   `base` (step `base`, phase `base-take`) follows: the memory snapshot of the three guests, see [The base snapshot](#the-base-snapshot).
8. `publish` records the receipt and the fleet record. `prune` runs beside it, in every reset: it keeps the active
   generation and the previous one and deletes the other source directories of this candidate and group that no guest
   mounts (the guests wrote some of it as root, so it uses sudo).

A fleet restored from ready copies sends no new Node fact, because the agent reports a fact only when it changes, so
the build's check for facts received after the start does not apply. The presence heartbeat in `wait` proves that each
agent is alive, and the recorded facts must exist.

A failure at any step runs the same failure cleanup as a build: it releases the active generation. The ready copies
stay, but with no fleet they are unusable until the next build. The launcher then runs the full build once (`reset --from-ready` builds and makes copies) when the fast path failed before a case started; the fast evidence is kept as `fast-*.json`. Pruning is best effort: a directory that cannot be deleted is reported in `prune-generations.json` and does not fail the run. `release` removes the generation and leaves them.

`status.json` of a run records `mode` (`full`, `ready`, `ready-make` or `build-ready`), the run's seconds and the seconds of
every job. `mode.json` records the workflow chosen and why.

## The base snapshot

With no source share, Incus can take a memory (stateful) snapshot of the three nested VMs. The snapshot is named `base`. It is the way to put the fleet back to a known state in seconds, with its processes and memory, instead of booting it.

**Stateful guests.** A snapshot with memory needs `migration.stateful=true` and a root disk `size.state` equal to `limits.memory`. Incus changes both only on a stopped VM, so `ready.make_stateful` sets them where the reset already has the VMs stopped: `ready` sets them on the stopped originals before it copies them, and a restore sets them on each restored guest before it starts it (this also covers a set made before the guests were stateful). The copies and restores use `incus copy --instance-only`, so a copy never carries an old `base`. A full build never stops its guests, so it has no `base`; `reset --from-ready` builds, makes copies and then takes `base`.

**Taking `base`.** The phase `base-take` runs `incus snapshot create VM base --stateful --reuse` on the three guests at once; `--reuse` replaces the previous `base`. The VMs keep running. A guest that is not stateful fails the phase before any snapshot. Measured on a task VM: 16.5 s for the three. The workflows `proof-fleet-reset-ready` (after `readiness`) and `proof-fleet-ready` (after `complete`) call it, so a fleet that is handed over has a `base`. `base` holds the proof Swamp Club key, on the Gateway's disk and in the memory of Swamp serve. That is accepted: `base` belongs to one task VM and is destroyed with it. Ready copies stay key-free.

**Restoring `base`.** The phase `base-restore` (workflow `proof-fleet-base-restore`) has one limit of 45 s for everything, and no retry. Past the limit, or on any error, it fails with an `Infrastructure error`: a fault of the test infrastructure, not a verdict about a command.

1. `incus snapshot restore VM base --stateful` on the three guests at once (about 9 s).
2. Per guest, at once: `incus wait VM agent`, then set the guest clock from the outer VM (`date -u -s @TIME`). A restore puts the clocks back to the snapshot time.
3. Beside step 2, one blocking `ping -c 1 -w N` to the nested Gateway. The outer VM's WireGuard session to the restored Gateway is not valid any more, and WireGuard starts a new handshake 15 s after the first packet that gets no answer. The ping sends that packet as soon as the guests are back and returns at the first reply. No peer is reset or changed.
4. `gateway:status` is `ok`.
5. Every Node agent is `online` in `node:list`. The Gateway counts an agent online for a heartbeat in the last 15 s, and the restore leaves the last heartbeat at snapshot time, so the agents come online up to 5 s after the clocks are right. The phase reads the list until all three are online, inside the 45 s limit.
6. `doctor` is healthy. It runs last because it reports a silent agent as drift.

The receipt records the seconds at `restored`, `clocks`, `tunnel` and `healthy`. Measured on a task VM with a change in the fleet (a firewall rule that the restore removed each time): healthy after 19.8 and 20.9 s when the restore follows `base` at once, and after 26.3 and 25.1 s when the fleet ran for 60 and 120 s first. The restore itself takes 9 s; the tunnel handshake is the long part after an idle period.

## Boot behaviour

A reset boots three VMs. [`tools/swamp-proof-guest/boot.py`](../../../tools/swamp-proof-guest/boot.py) writes the boot fixes during the build, so a fresh generation boots as a ready copy does:

- `orbit-agent` starts after `wg-quick@orbit`, and an `ExecStartPre` waits until `/api/v1/agent/realtime` returns a URL;
- `cloud-init.disabled`;
- an `orbit-hosts.path` unit restores the Orbit names in `/etc/hosts` after the Incus agent rewrites it;
- Swamp serve and the worker are enabled and ordered at boot;
- the Gateway services that use the checkout use `WorkingDirectory=-` and wait for nothing: the checkout is on the guest's disk;
- the Gateway VM has 2 vCPUs;
- PHP uses `opcache.validate_timestamps=1`, and no Laravel config, route or event cache exists.

`tests/test_boot.py` pins each file. Measured on ready copies built with these fixes, the whole fleet is Doctor-clean about 50 s after `incus start`; before them it took 95-99 s.

## Source and the live checkout

A build mounts the generation's private source copy, not the outer VM's live checkout; a reset from ready copies syncs that copy into each guest's own checkout. The Gateway bootstrap writes into the source it runs from: it replaces `apps/gateway/.env`, runs `key:generate` and `package:discover`, and writes `storage` and `bootstrap/cache`. Against the live checkout that would overwrite the task's own `.env`, change its caches and break the preservation assertions below. A live edit would also change code under a pinned candidate identity. The copy uses reflinks and the candidate's installed dependencies, so it is cheap.

## Ownership and results

The entrypoint runs its whole transaction inside the stable `proof-owner`
`command/shell` behavior in the owned group controller. Swamp's native per-behavior
method lock covers workflow definition installation, validation, the entire native
run, failure cleanup, cases and receipt collection. Reset, verify and release use
the same behavior. There is no Python lock or second workflow mutex. Contenders
use Swamp's default lock wait and report native lock timeout without touching the
active lease; they do not bypass the owner. The owner command has a one-hour
budget covering the existing reset, case and cleanup budgets. Each independent verification run calls `proof-fleet-reset` once. Cases
share that generation and must clean their own fixtures. A clean-state case or
failed case cleanup requires a new independent run, not an implicit reset.

`proof-fleet-reset` owns the sequencing: inventory, scoped release, candidate
snapshot, nested resource preparation, fixture configuration, readiness, and
result publication. Its failure path collects diagnostics and releases only
the generation it started. The shell/PHP programs perform individual bounded
phases. A timed-out diagnostic (including inventory collection) is recorded and cannot skip the owned release. Null Incus configuration on unmanaged interfaces carries no ownership. `proof-command-verification` composes that workflow with the selected
repository case programs and records a verdict (`passed`, `failed` or `unrun`) for each. Initialization
failure skips cases and is never a passing command proof.

The persistent orchestration repository and run history live under
`~/.local/state/orbit-proof-GROUP/controller`. The disposable source and control
plane live under `generations/GENERATION`; diagnostics and receipts live under
`CHECKOUT/.orbit-artifacts/GROUP/reset/GENERATION`. The latter two paths are
outside every guest and every release scope. The entrypoint uses only the
proof-only `~/.config/swamp/club-api-key` via `SWAMP_API_KEY_FILE` (private file,
not its value in argv). Online authentication with that file is sufficient;
a separate sign-in token is not a prerequisite. Do not discover or copy other
credentials.

The inventory records HEAD, HEAD tree, the effective dirty tree using a temporary
Git index, real-index digest, outer boot identity, observer identity and Pi service PID/start identity. Source
is exported into a separate Git checkout with private copies of installed
Composer dependencies. Guest convergence may edit that copy, never the candidate.
The snapshot includes nonignored candidate edits, not `.env`, runtime credentials,
or ignored artifacts. No commit, real-index staging, fetch or publication occurs.

The harness prepares VM copies from the existing seed with its normal attempt
lease, ownership metadata, host creation lock and capacity checks. This is not
normal `acquire`: the retired Gateway bootstrap action is unavailable. Preparation
explicitly does **not** claim a ready product topology. The combined nested Gateway/Swamp runtime needs 2560 MiB, not the ordinary Gateway catalog budget; preparation verifies its complete attempt ownership before stopping, resizing and booting that guest through the existing harness agent-readiness gate. Do not hotplug memory on its live virtiofs source mount. Other guests and the outer VM retain their limits. Use an allocated task VM with at least 8 GiB. Separate native steps use the shared `tools/swamp-proof-guest/fixture.py` bootstrap with explicit source, state and topology paths inside the nested Gateway. Swamp starts before Gateway seeding. The fixture installs its own RSA4096 CA, worker identity at the admitted role-worker path, matching agent secrets and observers, and publishes known-hosts. The same shared helper starts pinned Reverb, the agent-view subscriber and the real operations queue worker against the isolated SQLite database. The fixture uses the product firewall catalog to install the managed baseline, adds an unmanaged proof-network transport rule, and enables UFW before readiness. It never changes the outer host's services. Readiness has separate native steps: authenticated operator HTTP and a queued SSH probe using the admitted identity on each managed Node; one fresh host/ufw/units observation; then scoped Doctor and firewall baseline checks. The fact check requires generation-fresh real records and enabled UFW on every managed Node. It never polls or retries Gateway state: missing, stale or disabled facts fail closed, retain `facts-not-ready.json`, skip cases, and enter native owned cleanup. Native queueTimeout bounds worker admission. These are observations, not service-name-only checks. Test fixture seeding is setup, not command proof. Command
cases must invoke the actual candidate CLI and independently inspect effects. After each case, the cases phase compares real UFW output and firewall database records with that case's own pre-case state. Any residue fails that case; it never silently repairs a failing case.

Before deleting anything, scoped release validates the recorded network and all
guest identities. It does not sweep by name prefix. Unrelated fixtures, template,
outer system services, checkout and evidence are outside its scope. A refusal preserves the lease for diagnosis. If release refuses ownership, inspect the generation's `owner.json`, source `.e2e/attempt.json`, and failure inventory; correct the ownership discrepancy with the task orchestrator before retrying. Never remove the lease or sweep by prefix to bypass refusal. A failed preparation uses the harness's owned
rollback; later fixture/readiness failure releases the prepared generation.

## Native prerequisites and checks

The pinned Swamp `command/shell` builtin supplies bounded repository phase
execution and structured exit results. Native workflow dependencies supply
ordering/failure handling; a child workflow supplies reset reuse. The pinned
[behavior reference](https://swamp-club.com/manual/reference/behavior-definitions#per-behavior-method-locking)
and `swamp help behavior run` document the native owner lock and its default wait.
The workflow schema has no automatic step retry or observer-state wait primitive;
therefore the observation is one-shot and fails closed, rather than hiding an
Orbit-state polling loop in a helper. Discovery found
no official Incus extension; the community `@whitemars/incus` offers generic VM
lifecycle but not Orbit's attempt leases, seed topology, capacity locks or ownership
preflight. Replacing the existing harness would duplicate those rules. The
repository phase bridge therefore calls the existing harness, not a new Incus
lifecycle engine. No custom Swamp extension, transport or dispatcher is added.

Run the focused harness Pest tests and `python3 -m unittest discover -s
swamp/proof/reset/tests`. Native validation and real task-VM receipts are required
in addition to those regression tests. FakeIncus generations are not live resets.
See `reset.mmd` for the native composition and the generated workflow definitions
beside this page for the authoritative phase ordering.
