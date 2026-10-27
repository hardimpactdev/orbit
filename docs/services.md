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
authenticated server to be reachable for their active-run checks.

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

The command resolves Node 17 through Orbit, fetches `origin/main`, and sends a
git bundle over SSH. Services needs no GitHub credential. After transfer, it
checks active runs through the authenticated server and fetches the bundle
into `refs/remotes/bundle/main`. Before stopping the Process, it verifies the
requested commit, the `main` branch, no tracked edits, a fast-forward from
an existing `HEAD`, and no untracked path that main adds or replaces. The script
requires an existing checkout and running authenticated serve; use the manual
restore below to bootstrap them.
A bad bundle, divergent checkout or conflicting local file leaves serve running.
An unrelated untracked model remains in place. If `HEAD` already equals the
requested commit, deployment checks readiness without stopping or restarting.

For a changed commit, it checks active runs again immediately before stopping
the Process. Serve's default 30-second shutdown drain is the backstop for a run
that starts in the remaining gap: it waits for in-flight runs before aborting
them at the deadline. The command then advances the checkout with
`git merge --ff-only <validated-sha>` and restarts through Orbit. The update
uses the commit SHA even if another deploy changes the bundle ref. An update
failure after the stop leaves the Process stopped for inspection. A restart or
readiness failure also needs inspection; check the Process state before retrying.

Readiness uses an authenticated loopback Swamp command, retrying timeouts and
invalid JSON during startup. The command prints one JSON result with the
commit, Process ID and whether the checkout changed.
Failure to remove the temporary bundle is logged to stderr without replacing
the deployment result or its original error.

Python 3, git, SSH and scp are required on the deploying host. SSH uses the
normal host configuration; `SWAMP_SSH_IDENTITY` can select an existing identity
file. It never copies an SSH key. The binary and runtime state are provisioned
separately; a normal deployment never copies or replaces them.

The Services deploy CI job runs the deployment tests on the shared
`hardimpactdev` runners with labels `self-hosted` and `hardimpact`.

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
`groups-driver` uses the Gateway and runs once a minute; task VMs own task work.
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
