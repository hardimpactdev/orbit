# Deploy independent group runs on Services

Use `bin/deploy-services` from Nick's separate Mac checkout. It snapshots and
pauses enabled schedules, waits for idle, deploys current main, and restores
the schedules it paused. After failure, it reconciles all attempted pauses. The
historical `subtasks-driver` pause stays intact. Process 281 on Node 17 remains
the only coordinator.

Before an initial migration, have the maintenance cancellation owner confirm
that old groups and worker cleanup are settled. Do not start manual or webhook
work during deployment. Include the native `group-fanout` workflow in the target
main: the original `5c74e42` driver cannot expand a collection produced by an
earlier step on the pinned Swamp version.

## Deploy

```sh
bin/deploy-services
```

The helper first takes a fixed deployment marker on Services, then fetches
`origin/main`. Concurrent or crashed deployments fail fast with the earlier
owner and snapshot path. Do not remove that marker before recovery.
Require `status: ok`, `node: 17`, `process: 281`,
and `authenticated: true` in its JSON result, with the expected main SHA. The
`scheduleSnapshot` field names the retained local snapshot, created with mode
`0600` before any pause. Keep that file until acceptance ends. A no-change
deployment checks readiness without pausing schedules and returns a null
snapshot. See [deployment](services.md#deploy-main) for the 180-second idle
limit, restoration behavior, and stop/restart failures.

The helper restores schedules before reporting success. If a staged cutover must
remain paused during inspection, pause schedules deliberately before invoking
the helper and retain your own protected snapshot. Those existing pauses stay
untouched; their later restoration is your responsibility.

## Check after deployment

SSH to Services as `orbit` and set these path-only variables:

```sh
ssh orbit@10.44.0.3
export PATH=/home/orbit/.local/bin:$PATH
export SWAMP_REPO_DIR=/home/orbit/apps/orbit-swamp/default
export SWAMP_SERVER_URL=ws://127.0.0.1:9417
export SWAMP_SERVER_TOKEN_FILE=/home/orbit/.config/swamp/serve-driver-token
git -C "$SWAMP_REPO_DIR" rev-parse HEAD
swamp workflow schedule list --json
swamp workflow validate groups-driver --json
swamp workflow validate group-fanout --json
swamp workflow validate group-supervise --json
swamp workflow validate group-run --json
swamp workflow validate maintenance-projects --json
swamp workflow history search --workflow groups-driver --json
swamp workflow history search --workflow maintenance-projects --json
swamp workflow history search --workflow maintenance-workers --json
```

Require the expected SHA and original schedule states. `groups-driver` uses
`*/5 * * * * *`, discovers groups, then passes the saved lists to `group-fanout`.
`maintenance-projects` calls only `project_work`, without routine, detect, or
package tick steps. The three coordinator workflows must succeed.

## Prove ownership

Record the deployment completion time. With an eligible active group, inspect:

```sh
swamp workflow history search --workflow group-supervise --json
swamp workflow history search --workflow group-run --json
swamp workflow history get RUN_ID --json
swamp data query 'modelName == "orbit-group-tg-ID" && (name == "owner" || name == "intent")' --select attributes --json
```

Replace `RUN_ID` and `ID` with observed values. Supervisor inputs name the work
item and factory. Owner and intent agree on work item, factory, generation, key
`tg-ID.gN`, and admitted run ID. A refused duplicate must never drive work.
Only the admitted owner's terminal outcome permits a successor. Empty discovery
needs no child runs; capture this proof on the next authorized group.

After more than one old minute interval, inspect recent runs:

```sh
swamp run history --json
```

Require no standalone `orbit-driver-groups.tick`, `orbit-driver-routine.tick`,
`orbit-driver-detect.tick`, or `orbit-driver-package.tick` started after cutover.
New work runs as `orbit-group-tg-ID.drive`. Historical ticks remain visible;
compare start times. Confirm existing operation keys survive and cancelled
groups do not reappear. Never reset factory state or switch to the old tick
driver while an admitted owner exists.

## Recover schedule restoration

A failed restoration reports the failed schedule IDs and local protected snapshot
path. The helper reconciles every attempted pause before returning that error;
it excludes untouched schedules. Check the Process and checkout first; an update failure can leave serve
stopped. Do not assume a failure means the code was unchanged.

SIGTERM and SIGHUP normally enter restoration; SIGHUP is ignored while resumes
run. A crash or failed restoration keeps the fixed in-progress marker and
blocks later deployments, including no-change runs. The marker names the owner
host/PID and that host's protected snapshot. Use that snapshot, not a new list
of already-paused schedules.

After serve is healthy, rerun only the saved enabled resumes from the deploying
host. Set the snapshot path to the file printed by the failed deployment:

```sh
python3 - /path/to/swamp-deploy-schedules-SNAPSHOT.json <<'PY'
import json, shlex, subprocess, sys
for s in json.load(open(sys.argv[1]))['schedules']:
    if not s['paused'] and not s['orphaned']:
        command = ['/home/orbit/.local/bin/swamp', 'workflow', 'schedule',
                   'resume', s['workflowId'], '--server', 'ws://127.0.0.1:9417',
                   '--token-file', '/home/orbit/.config/swamp/serve-driver-token', '--json']
        subprocess.run(['ssh', 'orbit@10.44.0.3', shlex.join(command)], check=True)
PY
```

If serve must stay stopped, use the native local form on Services instead:
`swamp workflow schedule resume WORKFLOW_ID --repo-dir "$SWAMP_REPO_DIR" --json`.
Unset `SWAMP_SERVER_URL` and `SWAMP_SERVE_URL` first, so the local command does
not connect to serve. Use only IDs whose snapshot has `paused: false` and
`orphaned: false`. This restores persisted state for the next start without
starting serve. Then inspect the schedule list and retain historical pauses.
After verifying restoration and confirming that the recorded deployer is no
longer running, remove
`/home/orbit/.local/state/orbit-swamp/services-deploy-in-progress.json` on
Services. Its mode is `0600`; do not delete it merely to bypass a busy deployment.
A force-killed deployment needs this recovery because cleanup cannot run after
SIGKILL or host loss.
