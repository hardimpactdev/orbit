# Deploy independent group runs on Services

Run this initial cutover from Nick's Mac. Process 281 on Node 17 is the only
coordinator. Wait for the maintenance cancellation owner to confirm all eight
detect groups in 2151..2165 are cancelled, their factory runs are closed, and
worker cleanup is settled. Deploy the follow-up containing `group-fanout` as
well as `5c74e42`: the original `groups-driver` tries to expand its collection
before discovery has run and cannot start its children on the pinned Swamp
version. Do not deploy `5c74e42` alone. Do not start manual workflows during
cutover.

## Pause and drain

SSH to Services as `orbit` and set these variables. Repeat these exports after
reconnecting; none prints or copies a credential.

```sh
ssh orbit@10.44.0.3
export PATH=/home/orbit/.local/bin:$PATH
export SWAMP_REPO_DIR=/home/orbit/apps/orbit-swamp/default
export SWAMP_SERVER_URL=ws://127.0.0.1:9417
export SWAMP_SERVER_TOKEN_FILE=/home/orbit/.config/swamp/serve-driver-token
swamp workflow schedule list --json > /tmp/swamp-cutover-schedules.json
python3 - <<'PY'
import json, subprocess
schedules = json.load(open('/tmp/swamp-cutover-schedules.json'))['schedules']
for s in schedules:
    if not s['paused'] and not s['orphaned']:
        subprocess.run(['swamp', 'workflow', 'schedule', 'pause', s['workflowId'],
                        '--reason', 'per-group workflow cutover', '--json'], check=True)
PY
swamp workflow schedule list --json
swamp run history --active --json
```

This pauses every enabled schedule, including `groups-driver`,
`maintenance-projects`, `maintenance-workers`, and all scheduled project routine
and detect workflows. Check that every non-orphaned schedule is paused. Preserve
the snapshot: it is the restoration list. Never overwrite it after pausing.
Historical pauses, especially `subtasks-driver`, must remain paused.

Pausing stops future scheduled starts, not active, manual, or webhook runs.
Let existing runs finish. Require `{"runs":[]}` before deployment. Investigate
any run that cannot finish; do not cancel or replay uncertain effects blindly.

## Deploy and check while paused

Exit SSH. In the separate Mac checkout run:

```sh
bin/deploy-services
```

The script deploys fetched `origin/main`, not an arbitrary requested commit.
Check the expected main SHA contains `5c74e42`. Require its JSON result to report
`status: ok`, `node: 17`, `process: 281`, and `authenticated: true`. Runtime data
and schedule pauses remain in place. See [deployment](services.md#deploy-main)
for failed-stop or restart handling.

Reconnect and repeat the exports above, then run:

```sh
git -C "$SWAMP_REPO_DIR" rev-parse HEAD
swamp workflow schedule list --json
swamp run history --active --json
swamp workflow validate groups-driver --json
swamp workflow validate group-fanout --json
swamp workflow validate group-supervise --json
swamp workflow validate group-run --json
swamp workflow validate maintenance-projects --json
swamp workflow get groups-driver --json
swamp workflow get maintenance-projects --json
```

Require the expected checkout SHA, all cutover pauses, no active runs, and valid
workflows. The effective `groups-driver` cron is `*/5 * * * * *`; it discovers
then passes the saved lists to `group-fanout`, which fans out `group-supervise`. `maintenance-projects` calls only `project_work`,
with no routine, detect, or package tick steps. If a schedule override changes
the effective cron, inspect and correct it before resuming.

## Resume and prove ownership

Resume `groups-driver` only if the snapshot says it was enabled:

```sh
python3 - <<'PY'
import json, subprocess
for s in json.load(open('/tmp/swamp-cutover-schedules.json'))['schedules']:
    if s['workflowName'] == 'groups-driver' and not s['paused'] and not s['orphaned']:
        subprocess.run(['swamp', 'workflow', 'schedule', 'resume', s['workflowId'], '--json'], check=True)
PY
date -u +%Y-%m-%dT%H:%M:%SZ
swamp workflow history search --workflow groups-driver --json
swamp workflow history search --workflow group-supervise --json
swamp workflow history search --workflow group-run --json
```

Record that UTC time. Require successful discovery. With an eligible active
group, `group-supervise` inputs name its work item and factory. Its `group-run`
inputs include key `tg-ID.gN`, generation `N`, and definition
`orbit-group-tg-ID`. Inspect a returned run ID and the saved ownership:

```sh
swamp workflow history get RUN_ID --json
swamp data query 'modelName == "orbit-group-tg-ID" && (name == "owner" || name == "intent")' --select attributes --json
```

Replace `RUN_ID` and `ID` with the observed values. Owner and intent must agree
on work item, factory, generation, key, and admitted run ID. A duplicate envelope
may finish with a refused claim; it must never drive work. The owner advances or
suspends at its durable wait, and only its terminal outcome permits a successor.
If discovery is empty, no child run is expected. Capture this proof on the next
authorized group; do not create work solely to make history nonempty.

After more than one old minute interval, inspect recent runs:

```sh
swamp run history --json
```

There must be no standalone `orbit-driver-groups.tick`,
`orbit-driver-routine.tick`, `orbit-driver-detect.tick`, or
`orbit-driver-package.tick` started after the recorded time. Stage work runs as
`orbit-group-tg-ID.drive`. Historical ticks remain visible; compare their start
times. Also inspect the first `maintenance-projects` run after restoring it.

Resume the two other coordinator schedules if previously enabled:

```sh
python3 - <<'PY'
import json, subprocess
schedules = json.load(open('/tmp/swamp-cutover-schedules.json'))['schedules']
for name in ('maintenance-workers', 'maintenance-projects'):
    for s in schedules:
        if s['workflowName'] == name and not s['paused'] and not s['orphaned']:
            subprocess.run(['swamp', 'workflow', 'schedule', 'resume', s['workflowId'], '--json'], check=True)
PY
swamp workflow history search --workflow maintenance-workers --json
swamp workflow history search --workflow maintenance-projects --json
```

After their first runs succeed, restore remaining previously enabled schedules:

```sh
python3 - <<'PY'
import json, subprocess
coordinators = {'groups-driver', 'maintenance-workers', 'maintenance-projects'}
for s in json.load(open('/tmp/swamp-cutover-schedules.json'))['schedules']:
    if not s['paused'] and not s['orphaned'] and s['workflowName'] not in coordinators | {'subtasks-driver'}:
        subprocess.run(['swamp', 'workflow', 'schedule', 'resume', s['workflowId'], '--json'], check=True)
PY
swamp workflow schedule list --json
swamp run history --json
```

Keep `subtasks-driver` paused. Confirm new owners retain existing operation keys,
no cancelled detect group reappears, and no standalone fleet ticks start.
If any check fails, keep admissions paused and inspect the saved intent, native
history, and operation receipts before proceeding. Never reset factory state or
switch back to the old tick driver while an admitted group owner exists.
