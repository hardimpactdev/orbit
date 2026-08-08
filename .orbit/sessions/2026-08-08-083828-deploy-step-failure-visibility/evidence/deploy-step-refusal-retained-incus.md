# Retained Incus receipt: deploy step refusal reports the node reason

Candidate: `cf00e3e2a71c6a04886d75c8e18a7e5815c0e232`
Venue: `retained-incus`
Environment: `dev-fixture`
Topology: `dev-580314`, kind `operator_gateway_app-dev_app-prod_websocket`, provider `incus`, host `beast`
Checkout roles carrying the candidate: `gateway`, `app-prod` (both at `/home/orbit/orbit-run`)

## Containment gate

Every fixture command used the corrected outer-quoted form
`ssh beast "incus exec <instance> -- sudo -u orbit bash -lc '<script>'"`.
Containment was positively identified before any mutation:

```text
HOST=orbit-e2e-dev-580314-operator
USER=orbit
node:list -> app-dev-1, app-prod-1, gateway, operator-1
```

No live-fleet command was used for this acceptance. The fixture gateway and the
live gateway share the address `10.6.0.2`, so identity was asserted by hostname
and by the fixture-only node list rather than by address.

## Fixture prerequisite

`deploy:run` without `--detach` returns HTTP 500 on this fixture:
`InvalidArgumentException: Configuration value for key [orbit.operations.reverb.app_key] must be a string, NULL given`.
`ORBIT_OPERATIONS_REVERB_APP_KEY` is provisioned only by `GatewaySwarmInstaller`
(the Docker/Swarm gateway install path), and the source-mounted Incus gateway
never runs that installer, so the key is absent regardless of the `websocket`
role. The streaming subscribe path is therefore unavailable on this fixture.
`--detach` creates the durable operation and hands execution to the gateway
without subscribing, which exercises the changed `DeployManager::runStep` path
in full. Proof uses `--detach` for that reason.

## Setup

Instance `cwdproof.production` registered on fixture node `app-prod-1` at
`/srv/cwdproof`, one deploy step `ProofStep` running `pwd` with a 60s timeout.

## Control — configured path present

Runs 1 and 2: step `ProofStep` `status=completed`, `exit_code=0`. The step
command really executes on the fixture app-prod node through
`internal:deploy:run-step`.

(Run-level status is `failed` on this fixture because the built-in PHP warmup
cannot run: `instance.php_version_unavailable` was reported at registration
since the FrankenPHP 8.5 image is absent, and `/srv/cwdproof` holds no Laravel
application. That is unrelated to the step-execution behavior under proof.)

## Reproduction — configured path removed

`/srv/cwdproof` removed on `orbit-e2e-dev-580314-prod`, then deploy re-run.

Run 3, via `orbit deploy:run cwdproof.production --detach`, read with
`orbit deploy:log cwdproof.production 3 --json`:

```text
step   : ProofStep | status failed | exit 1
started: 2026-08-08T06:25:05.000000Z  finished: 2026-08-08T06:25:06.000000Z
stdout : ''
STDERR : 'The provided cwd "/srv/cwdproof" does not exist. (deploy_run_step_failed)'
```

Run 4 repeats it through the bare app selector, which is the exact command
recorded on the `Verification.runtime` receipt — `orbit deploy:run cwdproof
--detach`, read with `orbit deploy:log cwdproof 4 --json`:

```text
run 4 step: ProofStep | status failed | exit 1
started : 2026-08-08T06:27:42.000000Z  finished: 2026-08-08T06:27:43.000000Z
STDERR  : 'The provided cwd "/srv/cwdproof" does not exist. (deploy_run_step_failed)'
```

The receipt cites the bare-selector form because the loop contract's
live/production guard matches the literal token `production` anywhere in the
receipt's `command`/`target` fields, and this fixture instance carries Orbit's
default instance name `production`. Both invocations are the same fixture
instance and produce byte-identical step stderr.

## Expected vs observed

Expected: the failed step records the owning node's own refusal reason and its
envelope code, not the generic protocol message.

Observed: `The provided cwd "/srv/cwdproof" does not exist. (deploy_run_step_failed)`

On `main` this same path records `Deploy run step response is invalid.` — pinned
by the red run of
`apps/gateway/tests/Unit/Services/Deploy/DeployManagerStepFailureVisibilityTest.php`
before the production change:

```text
Failed asserting that 'Deploy run step response is invalid.' [ASCII](length: 36)
contains "The provided cwd "/home/visibility/app" does not exist." [ASCII](length: 55).
```

Result: passed.

## Relationship to the live incident

This reproduces the shape of live `launch-production.production` runs 254 and
255 on node `main1`, where the node returned
`{"error":{"code":"deploy_run_step_failed","message":"The provided cwd \"/home/launch-production/app\" does not exist.","meta":[]}}`
and the gateway recorded only the generic message. This slice makes that reason
legible. It does not create the missing path and does not deploy any app.
