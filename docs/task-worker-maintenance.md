# Dependency maintenance on the group worker

Project 20 runs Composer and JavaScript maintenance in its task VM. The official
software factory and Gateway keep provision, handoff checks and commits, repair
turns, publication, merge observation and cleanup. Projects without `worker`
configuration still run their tracked dependency script. A worker failure never
selects that script path.

The driver uses Swamp's built-in `swamp/enrollment-token` methods on serve. Each
worker start has a named enrollment with a one-machine limit and a two-hour
hard deadline, plus a server access token for the same worker and deadline.
Token-authenticated serve checks the server token at the WebSocket upgrade,
before enrollment. The server token uses a `worker:` principal with no
control-plane grants. The enrollment name binds workflow placement. Only Swamp's
vault stores either plaintext secret. `enrollmentVault` selects
an existing Swamp vault (default `default`). The triggering principal needs read/write access
to that vault; reserved control-plane vaults are not used. Driver resources
contain the enrollment name, worker operation ID and public settings. Both
credentials travel to
`tasks.worker` in memory and through the Gateway transport's stdin. They are absent
from stored requests, fingerprints, logs and method arguments.

`tasks.worker` resumes a parked VM. A replay uses the same key and the same
vault credentials. A disconnected or expired worker needs a fresh start key and
token. Dispatch waits up to five minutes after worker startup succeeds for Swamp
to confirm enrollment, then fails the job with a reason that raises assistance.
Ended groups have their enrollment and server tokens revoked by the dependency
tick and the minute worker cleanup workflow; the Gateway stops the worker before group cleanup. Expiry bounds an outage of
the driver. No second daemon or token store is needed.

The `maintenance-worker` Swamp workflow places every filesystem step on
the enrollment-bound worker name, such as `task-group-7-1`, and declares external
writes. Labels remain useful evidence but cannot select a group's worker: a VM
can change its own labels. It calls the shared maintenance extensions with the
VM Instance's `checkout_path`, configured paths and policy.
It never uses a host checkout. Package runs select one configured path and run
detection afterwards. Summary uses the provision starting commit. Gateway
handoff checks still commit the tree and govern publication.

A workflow start intent is saved before dispatch. On a lost response, the driver
finds the run by its exact key and inputs in Swamp history and adopts it. It
never launches a second run for an uncertain start. If no run can be found within
five minutes of saving the intent, the job fails and raises assistance with a
clear reason. The worker name is saved in that intent, and existing intents are
reconciled before worker health checks. A worker is never rotated while its
workflow is in flight. Interrupted write steps need investigation,
not automatic redispatch. Successful run snapshots supply the existing factory's
script artifact, including the complete working-tree hash and change flag.

The [remote execution manual](https://swamp-club.com/manual/explanation/remote-execution)
and [worker fleet manual](https://swamp-club.com/manual/explanation/worker-fleets)
own placement, vault-backed enrollment, the one-machine limit, and failure
semantics. The factory package stays official and unmodified.

## Deployment and proof

Merge and deploy this change from Nick's Mac with `bin/deploy-services`. Beast
cannot deploy to Services as `orbit`. Before live use, verify the Gateway release
contains Orbit #1526 and [the server-token transport change](https://github.com/hardimpactdev/orbit/pull/1528) and the task-VM image contains the pinned Swamp binary and
Orbit root CA. Do not use an older image or bypass TLS verification.

After deployment, start one ordinary external Project 20 group and the
`dependency-routine` factory. Record provision, worker, handoff and publish
operation IDs, the enrolled worker name, Swamp workflow run ID and PR URL (or clean
no-change completion). Compare its lockfile changes and summary with the former
script contract. The implementation PR contains no live-run evidence.

## Local verification

CI runs the affected Deno contracts in `.github/workflows/maintenance-worker.yml`.
The native workflow and factory tests require an authenticated Swamp account,
including for pulling the official software-factory extension. The repository
currently exposes no Actions secrets for `SWAMP_API_KEY` and `SWAMP_SIGNIN_TOKEN`;
an unauthenticated pull fails at Swamp's account check. These integration tests
run locally until CI has those credentials. Run
`tests/maintenance_factory_test.ts` with the installed official extension. Run
`python3 -B -m unittest discover -s tests -p maintenance_workflow_test.py -v`
for the real token-authenticated serve/worker test. It verifies that enrollment
alone gets HTTP 401 and that the worker server credential cannot start workflows,
list workers or tokens, search models, or query data. It uses two workers, including one from another
group with a forged label, and fixture package commands. It keeps test enrollment
in Swamp vault/state and process memory, and stops serve and both workers on exit. Existing routine/package/publish routing tests cover the script
fallback. No test touches production state or installs website dependencies.
