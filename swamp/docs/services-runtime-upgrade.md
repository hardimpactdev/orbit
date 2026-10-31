# Upgrade the Services Swamp runtime

This is a maintenance window for the orchestrator. Production applications keep
serving. Nick runs it from his existing deployment access. Do not wait for worker
provisioning or placement: deploy current main, including #60, #61, #62, #64 and
#65, immediately after the runtime upgrade.

## Prepare

Resolve Services with `orbit node:show 17 --json` and confirm its address, user
`orbit`, and the single `swamp-serve` Process with `orbit process:list --node=17`.
The examples below run on Services as `orbit`, except Orbit lifecycle commands,
which run from the deploying host. Set the Process ID to the observed value.
Do not start manual or webhook work during this window.

Download and verify the binary before interrupting serve:

```sh
set -eu
umask 077
upgrade_dir=$(mktemp -d /tmp/swamp-upgrade.XXXXXXXX)
curl --fail --location --retry 3 \
  https://github.com/swamp-club/swamp/releases/download/v20261028.205235.0-sha.1602a617/swamp-linux-x86_64 \
  --output "$upgrade_dir/swamp"
printf '%s  %s\n' 38b81986c7203f1e93806fc7b6d38e560a86456ea755a4369a89b17b64d4fbe3 "$upgrade_dir/swamp" | sha256sum --check
chmod 755 "$upgrade_dir/swamp"
"$upgrade_dir/swamp" --version
```

Retain any earlier deployment marker and its original snapshot until recovery
is complete. If an old snapshot names `schedulerGuard`, revoke that exact name,
using native commands with `--repo-dir /home/orbit/apps/orbit-swamp/default` and
remote URL variables unset:

```sh
unset SWAMP_SERVER_URL SWAMP_SERVE_URL
swamp behavior run EXACT_RECORDED_GUARD revoke --repo-dir /home/orbit/apps/orbit-swamp/default --json
```

If serve is running, use the authenticated server form instead, then run
`swamp access reload` and `swamp access check --subject service:scheduler
--action run --on 'workflow:*'` with the same server and token-file arguments.
Require the old guard to be absent from `matchingGrants`. Offline revocation
takes effect on restart. Do not remove an unrelated access grant.

## Stop, back up and replace

Save the schedule list using the verified new binary's native local commands:

```sh
unset SWAMP_SERVER_URL SWAMP_SERVE_URL
app=/home/orbit/apps/orbit-swamp/default
"$upgrade_dir/swamp" workflow schedule list --repo-dir "$app" --json > "$upgrade_dir/schedules.json"
```

Pause only schedules with `paused: false` and `orphaned: false`, by their
`workflowId`, using `workflow schedule pause ID --repo-dir "$app" --json`.
Preserve this protected snapshot. While the old serve is still running, wait
up to ten minutes for both conditions:

- `"$upgrade_dir/swamp" run history --active --repo-dir "$app" --json` has an
  empty `runs` array.
- The authenticated `GET /api/v1/health` response on loopback has a
  `scheduling.schedules` array and every entry has `queued: 0`. Consume the
  existing token file in the HTTP client; never print it or put it in argv.

The old serve can still start fires queued before the pause. Empty active runs
alone are insufficient. If either condition cannot be established, restore the
saved schedules and defer to an idle window. Do not stop active work to force
an upgrade. Once both are proven, stop promptly with
`orbit process:stop PROCESS_ID` from the deploying host. Confirm its systemd
unit has `MainPID=0` and `ActiveState=inactive` or `failed`. Recheck native active
runs after stopping and require no other datastore writers. If work remains,
restore schedules and restart the old Process without replacing anything.
Do not clear receipts or repair runs merely to pass this check.

Back up while every writer is stopped, then replace the binary atomically:

```sh
backup_dir=/home/orbit/backups/swamp-upgrade-$(date -u +%Y%m%dT%H%M%SZ)
mkdir -m 700 "$backup_dir"
cp /home/orbit/.local/bin/swamp "$backup_dir/swamp"
cp "$upgrade_dir/schedules.json" "$backup_dir/schedules.json"
tar -C "$app" -I zstd -cf "$backup_dir/datastore.tar.zst" .swamp
install -m 755 "$upgrade_dir/swamp" /home/orbit/.local/bin/swamp.next
mv /home/orbit/.local/bin/swamp.next /home/orbit/.local/bin/swamp
/home/orbit/.local/bin/swamp --version
```

Restore every saved enabled, non-orphaned schedule with native `workflow
schedule resume ID --repo-dir "$app" --json`, even if an earlier command fails.
Verify all saved states. Restart the Process through Orbit and check
authenticated `workflow schedule list`. On any failure after pausing, restore
from the original snapshot before exiting; if restoration fails, print
`SCHEDULES MAY STILL BE PAUSED`, the snapshot path and each exact resume command.
Keep the backup and snapshot. If a prior deploy marker exists, remove it only
after its owner is gone, schedules are restored and its guard is revoked.

Do not restore the datastore backup over newer live writes. A failed runtime
start can use the saved old binary while serve remains stopped; inspect run
state before retrying.

## Deploy main and prove observation

From Nick's updated checkout, run `bin/deploy-services`. Require `status: ok`,
the current main SHA including #65, and `authenticated: true`. The helper
pauses, drains and restores independently of the websocket, and creates no
scheduler guard. New schedules from #65 are enabled by their definitions;
existing historical pauses remain unchanged.

Using `--server wss://swamp.orbit --token-file EXISTING_TOKEN_FILE`, list
schedules and validate the four workflows. Run each once with its normal
`workflow run NAME` command, or inspect its first successful scheduled run:

| Workflow | Schedule (UTC) |
| --- | --- |
| `observation-collection` | second 15, every ten minutes |
| `observation-horizon` | second 45, every ten minutes |
| `observation-maintenance` | second 25, every five minutes |
| `observation-retention` | 02:17:10 daily |

Record the schedule IDs, run IDs and successful terminal outcomes using
`workflow history get RUN_ID`. Report all four run IDs to the orchestrator.
This acceptance does not depend on the worker rollout.
