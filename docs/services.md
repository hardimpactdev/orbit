# Swamp on Services

Services (Orbit Node 17) runs the orchestrator as the `swamp-serve` Node
Process, with runtime `systemd` and user `orbit`. There is no dedicated Swamp
account. Orbit owns its lifecycle; do not install a second Swamp daemon.

| Path | Contents |
| --- | --- |
| `/home/orbit/apps/orbit-swamp/default` | Checkout of `main` |
| `default/.swamp/` | Complete Swamp datastore, vault, tokens, grants, run history, schedules and installed official extensions |
| `/home/orbit/.local/bin/swamp` | Binary matching `.swamp.yaml` |
| `/home/orbit/.swamp/` | Deno and its cache |
| `/home/orbit/.config/swamp/` | Club identity, API key and driver token |

Secret files belong to `orbit`, with mode `600`; their directories have mode
`700`. Process arguments use `SWAMP_API_KEY_FILE` and `SWAMP_SERVER_TOKEN_FILE`
paths, plus `SWAMP_HOME`, `SWAMP_CONFIG_DIR`, `HOME` and `PATH`. Update checks
and telemetry are disabled. No secret value belongs in Orbit's Process record.

Process 281 has this definition. Use it only to recreate a missing Process
record, after restoring the checkout, runtime data, binaries and secret files
at the paths above. Do not create a second Process while 281 exists.

```sh
orbit process:create swamp-serve --node=17 --runtime=systemd --user=orbit \
  --working-directory=/home/orbit/apps/orbit-swamp/default \
  --restart=always --keep-alive \
  --command=/usr/bin/env \
  --command=HOME=/home/orbit \
  --command=PATH=/home/orbit/.local/bin:/usr/local/bin:/usr/bin:/bin \
  --command=SWAMP_HOME=/home/orbit/.swamp \
  --command=SWAMP_CONFIG_DIR=/home/orbit/.config/swamp \
  --command=SWAMP_API_KEY_FILE=/home/orbit/.config/swamp/api-key \
  --command=SWAMP_SERVER_TOKEN_FILE=/home/orbit/.config/swamp/serve-driver-token \
  --command=SWAMP_NO_UPDATE_CHECK=1 --command=SWAMP_NO_TELEMETRY=1 \
  --command=/home/orbit/.local/bin/swamp --command=serve \
  --command=--repo-dir --command=/home/orbit/apps/orbit-swamp/default \
  --command=--port --command=9417 --command=--host --command=127.0.0.1 \
  --command=--auth-mode --command=token --command=--admins --command=user:nckrtl \
  --command=--club-api-key-file --command=/home/orbit/.config/swamp/api-key \
  --command=--trusted-hosts --command=swamp.orbit --command=--trust-proxy \
  --command=--dispatch-env-allow=,
```

The command creates a stopped Process with `restart_policy: always` and
`keep_alive: true`. After checking the restored state, start it with
`orbit process:restart <returned-process-id>`. Normal deployments require the
local filesystem datastore for active-run and schedule checks. Authenticated
serve readiness is checked after restart.

Serve listens on `127.0.0.1:9417`, with token authentication and admin
`user:nckrtl`. The private Route `https://swamp.orbit` proxies that listener
through Services' Caddy. Only WireGuard peers can reach it. Clients use
`--server wss://swamp.orbit --token-file <local-token-file>` and trust the Orbit
root CA. Never publish this Route publicly. The
[Swamp manual](https://swamp-club.com/manual/how-to/set-up-tls-for-swamp-serve)
describes this reverse proxy arrangement.

The final argv entry is `--dispatch-env-allow=,`. Swamp drops empty fields in this
comma-separated list, so it ships no serve environment variables to task VMs.
The pinned CLI rejects a literal empty flag argument. Keep the explicit empty
list when updating Process 281; omitting the flag restores Swamp's default
dispatch environment. The worker supplies its own runtime environment.

## Deploy main

From a separate checkout of
[hardimpactdev/orbit-swamp](https://github.com/hardimpactdev/orbit-swamp) on a host
with GitHub access, Orbit CLI access to the Gateway, and SSH access as `orbit`
to Services (`10.44.0.3`), run:

```sh
bin/deploy-services
```

Nick's Mac can deploy and successfully deployed main at `1ebd12e`. Beast
currently cannot: SSH to `orbit@10.44.0.3` fails with
`Permission denied (publickey)`. WireGuard and Gateway access alone do not
permit deployment. Other hosts need a working SSH identity for that account.

The command resolves Node 17 through Orbit and acquires one protected in-progress
marker on Services at
`/home/orbit/.local/state/orbit-swamp/services-deploy-in-progress.json` before
fetching or changing deployment state. Atomic creation serializes deployments
from all checkouts and hosts. The marker names the deploying host, PID, and
protected local snapshot path. A second deployment fails immediately with that
identity, even if the first client crashed or the checkout already matches main.
Recover the earlier snapshot before removing a stale marker; never replace it
with the current paused schedule list.

With the marker held, the command fetches `origin/main` and sends a
git bundle over SSH. Services needs no GitHub credential. Before interrupting
schedules, it validates the bundle and requested SHA, the live `main` branch,
tracked edits, fast-forward ancestry, and conflicting untracked paths. A bad
bundle, divergent checkout, or conflicting local file leaves serve and schedules
alone. An unrelated untracked model remains in place. If the checkout already
matches main, it checks authenticated readiness without pausing or restarting.

For a changed deployment, the command saves the full schedule list in a unique
local `swamp-deploy-schedules-*.json` file with mode `0600`. It flushes the file
before pausing any schedule and prints its path to stderr. The snapshot remains
available after success or failure. Only enabled, non-orphaned schedules enter
the pause and restore set. Existing pauses, including `subtasks-driver`, are
never changed. Schedule IDs identify the restoration set.

With those schedules paused, the command waits at most 600 seconds for
`swamp run history --active --repo-dir` to become empty. Schedule controls and
active-run reads use native local commands, with remote URL variables cleared.
The filesystem control-plane store supports these commands while serve holds
the writer lease. They do not wait on serve's busy websocket event loop.

Swamp `20261028.205235.0-sha.1602a617` fixes queued cron fires bypassing a
pause ([#4048](https://swamp-club.com/lab/4048)). The helper requires that
release, matching `.swamp.yaml`. It creates no scheduler deny grant. The native
regression queues fires before pausing and proves none start during the drain.
See the [runtime upgrade](services-runtime-upgrade.md) before the first deploy.

A timeout or unreadable response aborts before stopping serve and restores the
saved enabled schedules. Do not start manual or webhook work during deployment:
a schedule pause does not prevent those starts. Serve's default 30-second
shutdown drain remains a backstop for a run that starts just before the stop.

The command stops the Process, advances the checkout with
`git merge --ff-only <validated-sha>`, and restarts through Orbit. The update
uses the commit SHA even if another deploy changes the bundle ref. Readiness
uses an authenticated loopback Swamp command, retrying timeouts and invalid JSON
during startup for up to 360 seconds. Remote commands have 180-second deadlines
and `SWAMP_SERVE_TIMEOUT_MS=120000`; readiness has a 400-second outer deadline.
The JSON result includes the commit, Process ID, whether the
checkout changed, and the protected `scheduleSnapshot` path.

SIGTERM and SIGHUP raise a deployment error so cleanup runs. SIGHUP is ignored
during schedule restoration and marker release. Normal exits and handled errors
restore the schedules whose pauses were attempted, including an uncertain pause
reply. Untouched schedules are excluded. Restoration attempts continue after an
individual failure. Every attempted pause receives a native local resume, even if serve is
unreachable or an earlier restore failed. A final local list verifies the result.
If that evidence is unreadable, the marker stays in place. A failed pause reply
also retains it: the remote write might complete after the cleanup resumes.
Confirm that command has ended, then repeat the saved resumes before removing
the marker.

The helper observes systemd state before changing the checkout; a lost Orbit
reply does not determine whether serve is stopped. Restoration always uses
native local schedule commands, including after a failed stop, restart or
readiness check. It restores persisted state without starting serve. Inspect
the Process separately after those failures.

A schedule restoration failure makes the deployment fail, even if the code
update succeeded. The error names each failed schedule and the retained snapshot;
when deployment also failed, its original error remains in the message. Inspect
serve before retrying those resumes. Never resume schedules whose snapshot says
`paused: true`. See the [cutover runbook](services-group-cutover.md) for recovery
commands. The marker is removed only after all attempted pauses have been
reconciled and resumed successfully, or when deployment exits before pausing
anything.
It remains after a crash or restoration failure and blocks every later deploy.
Failure to remove the temporary bundle is logged to stderr without
replacing the deployment result or its original error.

Python 3, git, SSH and scp are required on the deploying host. SSH uses the
normal host configuration; `SWAMP_SSH_IDENTITY` can select an existing identity
file. It never copies an SSH key. The binary and runtime state are provisioned
separately; a normal deployment never copies or replaces them.

Run the focused Python deployment tests locally. CI does not run test suites.

## Initial move or manual restore

Inventory binaries, definitions, local paths, vault metadata, schedules and
active runs first. Services needs Swamp, Deno, git and curl, plus the Orbit root
CA. The Gateway identifies Services as Node 17 by its WireGuard address.
An access refusal needs orchestrator approval before adding an access edge.

Announce cutover, pause the active schedules with `swamp workflow schedule
pause`, and wait for `swamp run history --active` to be empty. Stop and disable
Beast's user unit, leaving its checkout and files in place. Keep Services'
Process stopped during restore. Before copying runtime state, restore the
tracked checkout manually. From a fresh checkout of `main` on a deploying host,
create a bundle containing the `main` branch and transfer it:

```sh
git bundle create /tmp/swamp-restore.bundle main
scp /tmp/swamp-restore.bundle orbit@10.44.0.3:/tmp/swamp-restore.bundle
```

On Services as `orbit`, clone into an absent or empty application directory:

```sh
mkdir -p /home/orbit/apps/orbit-swamp
git clone /tmp/swamp-restore.bundle -b main /home/orbit/apps/orbit-swamp/default
rm /tmp/swamp-restore.bundle
```

For an existing nonempty checkout, preserve it separately before restoring;
do not overwrite runtime state. The clone restores `.swamp.yaml` and an
existing `HEAD`. Do not copy `.swamp.yaml` before cloning or use
`bin/deploy-services` to initialize an empty checkout.

With all writers stopped, copy the entire `.swamp/` directory, including SQLite
sidecar files, local vault keys, server tokens and paused schedule state.
Transfer secret files with scp, without displaying their contents. Copy the
matching Swamp binary, Deno/cache and user configuration; adjust path-only
`env.sh` for `/home/orbit`.
Load that file at the start of `~/.bashrc`, before its noninteractive return,
so SSH deployment commands inherit the API key path without exposing its value.
Preserve any untracked behavior definitions separately from the git bundle.

Reuse Process 281, or recreate a missing record with the definition above.
Verify the restored state, then start the Process through Orbit. Confirm that
authenticated `swamp run history --active --server` works before using
`bin/deploy-services` for subsequent updates from main.
Create the private Route with `orbit route:create swamp.orbit --node=17
--upstream=http://127.0.0.1:9417 --publication=private`. Restore only the schedules
that were active before cutover. Verify health and authenticated commands from
Beast and another WireGuard peer, run a validated read-only workflow, and inspect
scheduled run history. Keep Beast's unit disabled after success.

The historical `subtasks-driver` was already paused on October 22. Preserve
that pause. Its host workflows depend on `/fast/apps/orbit-swamp-scratch`, PHP,
Composer, Codex, Claude, GitHub access and the vault's token helper. They are not
the active external driver and must not be resumed on Services without moving
and checking those dependencies. Keep the original scratch tree on Beast.
`groups-driver` discovers active groups every five seconds and reconciles their
independent workflow owners; task VMs own task work. See the
[initial group-workflow cutover](services-group-cutover.md).
Full dependency acceptance also requires working task-group provisioning.

The preserved untracked `models/@swamp/git/git-wt-bump-apps-e2e.yaml` is an
`@swamp/git` model for the historical host worktree
`/fast/apps/orbit-swamp-scratch/wt2/bump-apps-e2e`. It is not Services project
configuration. The retained `orbit-subtasks` work item is still at `handoff`,
and its driver is paused. Keep the model with this historical state until the
work item is explicitly retired. Do not commit it as part of this deployment
or use it on Services. Any later removal needs operator approval; keep the
Beast copy with the historical state.

Before reverting a failed cutover, stop the Services Process and confirm no
workflow is active. Preserve its state before restarting Beast. If Services has
executed work, reconcile or copy that state back first; the retained Beast copy
is a cutover snapshot, not a current replica.
