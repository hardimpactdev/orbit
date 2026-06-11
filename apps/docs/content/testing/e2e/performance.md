# E2E performance

Use this page for E2E timing baselines, SSH transport requirements, and resource
diagnostics.

## E2E Docker lane - benchmark protocol

Use the timing parser to summarize repeated Docker lane runs by `label` and
`event`:

```bash
ORBIT_E2E_TIMINGS=1 \
  composer test:e2e:docker:canary \
  2>&1 | tee /tmp/e2e-canary.log | awk -f bin/e2e-timings.awk

ORBIT_E2E_TIMINGS=1 \
  composer test:e2e:docker \
  2>&1 | tee /tmp/e2e-full.log | awk -f bin/e2e-timings.awk
```

To record a Docker lane baseline, run three consecutive full-lane passes under
identical conditions with unique `/tmp` log names and a wall-clock timer:

```bash
/usr/bin/time -p -o /tmp/e2e-full-run1.time \
  env ORBIT_E2E_TIMINGS=1 \
  composer test:e2e:docker \
  2>&1 | tee /tmp/e2e-full-run1.log | awk -f bin/e2e-timings.awk

/usr/bin/time -p -o /tmp/e2e-full-run2.time \
  env ORBIT_E2E_TIMINGS=1 \
  composer test:e2e:docker \
  2>&1 | tee /tmp/e2e-full-run2.log | awk -f bin/e2e-timings.awk

/usr/bin/time -p -o /tmp/e2e-full-run3.time \
  env ORBIT_E2E_TIMINGS=1 \
  composer test:e2e:docker \
  2>&1 | tee /tmp/e2e-full-run3.log | awk -f bin/e2e-timings.awk
```

Commit a `## Docker lane baseline (YYYY-MM-DD)` section only when all three runs
pass with unchanged exit status, test count, and assertion count. Record the
three wall times, the wall mean plus sample standard deviation, and per-run
`n / p50 / p95` summaries for `docker.start`, `docker.prune`,
`docker.primeGatewayApi`, `reset.delete.*`, `reset.start`, `reset.prune`,
`reset.primeGatewayApi`, and `checkout.*` when those event groups are present.
Downstream phases must beat the recorded wall baseline by more than
`2 x stdev`.

## Required SSH multiplexing for measured Docker baselines

Operator-applied only. Orbit does not configure this automatically. The Docker
lane opens many short Docker CLI connections through `DOCKER_HOST=ssh://...`.
Those connections are backed by SSH. Without SSH multiplexing, connection setup
dominates the run. The full Docker lane can regress from the expected 120-150s
range to roughly 300s even when `.env.e2e` is otherwise identical.

```sshconfig
Host sidecar1 sidecar2 beast
    HostName %h
    User nckrtl
    ControlMaster auto
    ControlPath ~/.ssh/cm-%r@%h:%p.sock
    ControlPersist 10m
    ServerAliveInterval 30
```

Check the effective SSH config and connection reuse before recording or
comparing Docker E2E baselines:

```bash
ssh -G sidecar1 | grep -E '^(controlmaster|controlpath|controlpersist)'
time ssh -o BatchMode=yes sidecar1 true
time ssh -o BatchMode=yes sidecar1 true
```

The config should report `controlmaster auto`, a stable `controlpath`, and
`controlpersist` in seconds. The second `ssh true` call should be around
10-20 ms on the local LAN. If it remains in the hundreds of milliseconds, fix
local SSH config, identity selection, DNS/address selection, or network routing
before treating Docker E2E timing as an Orbit regression. Keep
`ORBIT_E2E_DOCKER_PARALLEL_STARTS=0` unless SSH multiplexing is in place and
sidecar sshd capacity has been verified under load.

## Current timing events

Set `ORBIT_E2E_TIMINGS=1` to surface per-phase durations from the topology
factory and lease. Forced `e2e:prepare-topology` already streams checkpoints by
default; the environment flag also covers topology acquisition, cleanup, and
reset paths.

Current event names include `availability`, `batch.copy-start`,
`agent-ready.<role>`, `command-ready.<role>`, `wireguard`, `cleanup.<role>`, and
`reset.*`. Output goes to STDERR with the prefix `[orbit-e2e]` so it interleaves
cleanly with Pest output. The clone/start batch intentionally stays one remote
SSH operation; split copy/start timing should only be added if it can keep that
single remote operation.

Prepared Docker feature lanes should not emit `docker.source-sync` or
`reset.source-sync`; those events belong to retained development topologies with
a source mount. If a normal `composer test:e2e:docker` or
`composer test:e2e:docker:canary` run spends time in source sync, the lane is
paying for rsync/hydration work that should be limited to active development
topologies.

## Recent baselines

Latest Beast prepared-topology measurement from May 21, 2026:

- Full `operator_gateway_app-dev_app-prod_agent` rebuild completed in
  `real 607.63s`. This is an explicit preparation/provisioning command and is
  not part of `composer test:e2e`.
- The rebuild used `ORBIT_E2E_INCUS_STORAGE_POOL=orbit-e2e`; all
  `orbit-template-*` instances must have root disk `pool: orbit-e2e` on Beast.
- After the rebuild, `composer test:e2e:incus` passed with 10 tests /
  85 assertions in `real 100.50s`, with two cached five-node workers and
  `batch.copy-start` around 2s per worker.

Docker canary measurement on May 19, 2026 passed with eight Docker workers
across `sidecar1:4,sidecar2:4` and a 16 container cap in `47.55s` real time.
The current runtime needs a 28 container cap for that pool.

The full Docker lane passed three consecutive runs with 81 tests / 727
assertions in `113.45s`, `112.49s`, and `114.88s` real time (`113.61s` mean,
`1.20s` sample stdev). A post-regression repair spot-check on May 21, 2026,
passed with 94 tests / 779 assertions in `137.24s` real time.
