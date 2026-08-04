# Retained Incus runtime proof — concurrent soft wake + aggregate readiness

## Result

**PASSED** soft-wake HTTP proof on post-main-merge candidate with concurrent
systemd process starts and aggregate readiness before awake.

Negative readiness disposable run was not executed (positive + ordering
evidence is complete; optional negative left for a later bounded pass if
needed).

## Checkout identity

| Field | Value |
| --- | --- |
| Feature branch | `codex-runtime-wake-parallel` |
| Feature HEAD (proof candidate) | `02b2b43c024e5e168d0104e77933daa021b2b171` |
| Includes pre-merge candidate | `2a81034cebe64ca66dfd242ee44f27e8d3f572a3` |
| Merged main tip | `64aab716cea3523c0b84d59992410de148dff0f3` |
| Worktree | `/Users/nckrtl/orbit/.worktrees/codex-runtime-wake-parallel` |

Source identity verified after `composer e2e:incus -- --sync --id=dev-bea81b` on
operator, gateway, and dev:

| File | SHA-256 |
| --- | --- |
| `ProcessRuntimeWakeConcurrentRunner.php` | `444264a92d63f68d408b53cc83e388f53bfea77520bbe6c4161f4fc623260c2d` |
| `RuntimeHibernation.php` | `e43f5f23c608296f65a5ccfb58efbfec5a7383b0adaa8214894c2f8a11fe0bbe` |
| `RuntimeActivationPage.php` | `415878b204a57f67f338238f7f7fc29f88c606810c8ec99374be870577e32f0b` |
| `RuntimeWakeProcessStarter.php` | `d90c0595433954a49fd7571f6538192a626d40ab2f50b630a26767adf6468787` |

Hashes matched local worktree on all three roles. Launchers resolve to
source-mounted `apps/cli/orbit` (`/home/orbit/.local/bin/orbit` → runtime
checkout).

## Topology (retained)

| Field | Value |
| --- | --- |
| id | `dev-bea81b` |
| kind | `operator_gateway_app-dev` |
| provider | `incus` |
| host | `beast` |
| source path | `/tmp/orbit-e2e-sources/codex-runtime-wake-parallel-incus-6dbec05e6e37/retained/dev-bea81b` |
| checkout | `/home/orbit/orbit-run` (operator, gateway, dev) |
| gateway WG | `10.6.0.2` |
| app-dev WG | `10.6.0.4` |
| instances | `orbit-e2e-dev-bea81b-{operator,gateway,dev}` |
| release | `composer e2e:incus -- --stop --id=dev-bea81b` (**not run**) |

## Disposable fixture (topology only)

- App: **wake-proof** at `https://wake-proof.test` on `app-dev-1`
- Path: `/home/orbit/apps/wake-proof` (minimal PHP `public/index.php` body
  `Wake Proof OK`, registered via `orbit instance:register`)
- Lifecycle processes (4 total; concurrent proof focuses on three systemd
  long-running units plus frankenphp docker):
  - `frankenphp-wake-proof` (docker, app runtime)
  - `web` → `orbit_wake-proof_development_main_web.service` (`sleep infinity`)
  - `queue` → `orbit_wake-proof_development_main_queue.service`
  - `worker` → `orbit_wake-proof_development_main_worker.service`

### Disposable instrumentation (not committed)

1. **Gateway image env** on gateway host:
   `ORBIT_GATEWAY_IMAGE=orbit-gateway@sha256:c6c0f86ba04c04b279c42ef484bb2d3bc766e6a7b7c7b861ded1775b6f5240cf`
   (required so `RuntimeActivationRunnerLauncher` can resolve a digest-pinned
   bootstrap image on this source-mounted topology).

2. **RuntimeActivationRunnerLauncher** temporary bind mounts (topology
   `/home/orbit/orbit-run` only) so the detached runner executes candidate
   source rather than the baked image tree:
   - `source=/home/orbit/orbit-run,target=/srv/orbit`
   - `source=/var/run/docker.sock,target=/var/run/docker.sock`
   Marked in-file as `DISPOSABLE retained-proof only`.

3. **ExecStartPre=/bin/sleep 2** on each of the three systemd units (after
   `daemon-reload`) so wall-clock start distinguishes concurrent (~2s) from
   serial (~6s).

4. FrankenPHP image retag on app-dev:
   `orbit-frankenphp-source-artisan:prepared-current` →
   `ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm` (GHCR pull
   unauthorized).

Committed product code was not modified for this proof.

## Soft-wake HTTP sequence (retry after prior launch failure)

Prior fixture attempt failed once with
`runtime_activation_runner_launch_failed` /
gateway bootstrap image unresolved (run
`5c425331-7701-41c8-ab83-341d4e87ea8d` at 21:14:29Z). After disposable image
env + launcher mounts, proof used `orbit-wake-retry=1`.

**Request** (operator → app-dev via Caddy):

`GET https://wake-proof.test/?wake=soft-concurrent-proof&deep=1&orbit-wake-retry=1`
(`--resolve wake-proof.test:443:10.6.0.4`)

| Step | Observation |
| --- | --- |
| First response | **HTTP/2 503**, **`Retry-After: 1`**, **`X-Orbit-Runtime-Activation-State: pending`**, `Cache-Control: no-store, private`, activation HTML (nonce script, no meta-refresh) |
| Probes | Non-overlapping 1s cadence; polls 1–12 still **503 pending** with **`Retry-After: 1`** |
| Healthy handoff | **poll 13**: **HTTP 200**, **no** activation-state header, body contains **`Wake Proof OK`**, title `Wake Proof` |

## Concurrent start timing (deterministic ExecStartPre=2s)

Journal (precise) on app-dev for the successful wake:

| Unit | Starting | Started | Δ |
| --- | --- | --- | --- |
| worker | `2026-08-04T21:21:12.478785Z` | `2026-08-04T21:21:14.500535Z` | ≈2.02s |
| queue | `2026-08-04T21:21:12.729004Z` | `2026-08-04T21:21:14.762518Z` | ≈2.03s |
| web | `2026-08-04T21:21:12.773874Z` | `2026-08-04T21:21:14.797684Z` | ≈2.02s |

- All three **Starting** within **≈0.30s** of each other.
- Each duration matches the disposable **2s** `ExecStartPre` (not 6s serial
  chain).
- Wall clock from first Starting → last Started ≈ **2.32s** (vs ≈6s if
  sequential with the same pre-start delay).
- `ActiveEnterTimestamp` for all three units: **Tue 2026-08-04 21:21:14 UTC**.

Parent-side process events (gateway DB): all four lifecycle processes
`starting` at **21:21:05**, all `started` at **21:21:14** (parent records after
concurrent workers resolve).

## Aggregate readiness / awake ordering

0.2s state sampler on app-dev (`/tmp/wake-parallel-evidence/states.tsv`):

| Event | Time (UTC) |
| --- | --- |
| Pre-wake | web/queue/worker **inactive**, franken **false**, awake **no** |
| All three systemd **active** + franken **true**, awake still **no** | `2026-08-04T21:21:14.862730Z` |
| Awake marker present | `2026-08-04T21:21:19.698397554Z` |

Observed **≈4.8s** with all expected units active **before**
`/dev/shm/orbit/hibernation/app-instance-1.awake` was created (aggregate
readiness gate; start exit alone insufficient). Marker still present after
healthy handoff:

`docker exec orbit-caddy ls /dev/shm/orbit/hibernation/app-instance-1.awake`

## Durable operation

| Field | Value |
| --- | --- |
| Operation run id | `d813fe7d-c507-4d9a-9750-86cbe11193b0` |
| operation_id | `runtime-activation:app-instance-1` |
| status | **succeeded** |
| cold | **false** (soft) |
| created_at | `2026-08-04 21:21:01` |
| started_at | `2026-08-04 21:21:03` |
| finished_at | `2026-08-04 21:21:19` |
| result | `{"runtime_activation":{"scope":"app-instance-1","cold":false}}` |

**Events:**

1. `tree` — steps process:2 frankenphp, process:3 web, process:4 queue, process:5 worker  
2. All four process steps `active` at 21:21:03  
3. All four process steps `done` at 21:21:19  
4. `complete` exit_code=0 at 21:21:19  

## Automated verification (same merge tip)

| Check | Result |
| --- | --- |
| Focused gateway Pest (wake + hibernation + process labels) | 60 passed / 821 assertions |
| Focused CLI Pest (process + is-active filters) | 73 passed / 282 assertions |
| `composer quality-check` | passed at clean `02b2b43c024e5e168d0104e77933daa021b2b171` |
| Profile | `.orbit/quality-gates/profiles/2026-08-04T21-10-21Z-02b2b43c024e` |

## Boundary

This is **retained disposable Incus** proof (`dev-bea81b`), not a production or
standing live-fleet deployment. Disposable topology instrumentation is fully
disclosed above. Topology remains **retained** for independent review /
acceptance; release command not executed.
