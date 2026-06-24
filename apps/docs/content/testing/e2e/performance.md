# E2E performance

Use this page for E2E timing baselines, SSH transport requirements, and resource
diagnostics.

## E2E Docker lane - benchmark protocol

Use the timing parser to summarize repeated Docker lane runs by `label` and
`event`:

The root `composer test:e2e*` scripts already preserve E2E timing summaries in
`.orbit/quality-gates/e2e-timings/` when `[orbit-e2e]` timing lines are emitted.
Use the explicit `tee | awk` form below when recording repeated benchmark logs
under stable `/tmp` names or when comparing phase summaries outside the local
quality-gate artifact directory.

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

### Topology Events

Current event names include `batch.copy-start`, `clone-ready.<role>`,
`command-ready.<role>`, `known-hosts.<role>`, `wireguard`,
`wireguard.install.<role>`, `gateway-ssh-access.<role>`, `retarget`,
`retarget.bake.<role>`, `network-ready.<role>`, `cleanup.bulk`, and `reset.*`.
Warm-snapshot and checkpoint paths still emit `agent-ready.<role>` and
`cleanup.<role>`. Output goes to STDERR with the prefix `[orbit-e2e]` so it
interleaves cleanly with Pest output.

### Checkout Events

Current-checkout events include `checkout.<role>.checkout.copy`,
`checkout.<role>.checkout.vendor`, `checkout.<role>.checkout.migrate`,
`checkout.<role>.checkout.host-keys`, and
`checkout.<role>.checkout.gateway-settings`. The clone/start batch
intentionally stays one remote SSH operation; split copy/start timing should
only be added if it can keep that single remote operation.

### Aggregation

Incus acquisition per-role readiness, WireGuard installs, gateway SSH
authorization, retarget bakes, and peer-route checks each run as one parallel
host invocation whose per-role durations are reported through those
`<role>`-suffixed events. `bin/e2e-timings.awk` aggregates nested timing labels
as well as single-word events, so checkout regressions should appear in the same
timing summaries as topology acquisition and cleanup.

Incus acquisition `incus.source-sync` skips full-tree ownership repair and
permission normalization when rsync reports an unchanged checkout; only
changed syncs pay the chown and chmod passes.

## Checkout archive cache

Prepared checkout archives are cached under
`ORBIT_E2E_CHECKOUT_ARCHIVE_CACHE_DIR` or the default temporary
`orbit-e2e-checkout-archives` directory. Each checkout build prunes cached
archives and orphaned lock files older than 24 hours, while preserving the
current archive and any fresh cache entry. Checkout builds also remove stale
temporary `orbit-current-*.tar.gz` archives older than 24 hours before creating
a new source archive. Custom cache paths must be dedicated to Orbit checkout
archives because stale `*.tar.gz` files in that directory are eligible for
pruning.

Generated CLI binaries such as `bin/orbit-binary-*` are ignored and excluded
from checkout archives. Release artifacts belong in the binary artifact lane,
not in every prepared-topology source checkout.

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

## Capacity guard diagnostics

When the Docker lane is configured for multiple runners, a broad E2E run is not
representative if runner probing leaves only a small subset reachable. In the
Docker plus Incus aggregate, the Docker lane first removes selected Incus hosts
from its runner plan; Beast must not be counted as Docker capacity while the
Incus lane is selected.

The runner fails execution when reachable Docker capacity is below
`ORBIT_E2E_DOCKER_MIN_PROCESSES`; when the variable is unset, the minimum is the
lower of eight workers and the planned Docker worker count.
Set `ORBIT_E2E_DOCKER_MIN_PROCESSES` to the reachable worker count only when the
goal is to run a degraded diagnostic pass rather than compare performance to the
baseline.

The June 13, 2026 slowdown investigation found `sidecar1`, `sidecar2`, and
`nmbp` unreachable over SSH, leaving only `beast:4` for Docker. A Docker canary
on that degraded pool took about `208s` wall time, while the May 19 canary
baseline was `47.55s` with eight workers on `sidecar1` and `sidecar2`.
Incus-only remained near the previous order of magnitude, but the aggregate run
stretched because Docker and Incus both contended for Beast. Restore the sidecar
Docker runners before treating aggregate wall time as an Orbit performance
regression.
