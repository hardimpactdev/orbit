# Retained Incus runtime proof — soft + cold activation progress

## Checkout identity

- Feature branch: `codex/runtime-activation-progress`
- Feature HEAD / reviewed tip: `3a1371e2e56987a344a7708f0875e8de48adcd68`
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-runtime-activation-progress`

## Topology

| Field | Value |
| --- | --- |
| id | `dev-b9f618` |
| kind | `operator_gateway_app-dev` |
| provider | `incus` |
| host | `beast` |
| source root | `/tmp/orbit-e2e-sources/codex-runtime-activation-progress-incus-036aa22350bc/retained/dev-b9f618` |
| Solo terminal | agent-owned process **1326** |

### Instances

| Role | Instance | Runtime checkout |
| --- | --- | --- |
| operator | `orbit-e2e-dev-b9f618-operator` | `/home/orbit/orbit-run` |
| gateway | `orbit-e2e-dev-b9f618-gateway` | `/home/orbit/orbit-run` |
| dev | `orbit-e2e-dev-b9f618-dev` | `/home/orbit/orbit-run` |

App-dev WireGuard address used for serving: `10.6.0.4`.

## Candidate image / code overlay (disposable topology only)

Exact candidate app/core code was overlaid into the prepared gateway image because the retained prepared artifact predated the hidden runtime-activation runner command. The candidate image was published only inside the disposable gateway through `localhost:5000` as:

`localhost:5000/orbit-gateway@sha256:ef0ebc34c76a07857547ea86998489b01d5af3027edfe0b466616a2e8be20dc2`

The zero-replica Swarm descriptor resolved this digest for the unchanged production launcher. No live topology, deploy, or push.

Recorded candidate source SHA-256 prefixes:

| Path | SHA-256 prefix |
| --- | --- |
| `RuntimeActivationService` | `1d6cc16c` |
| `RuntimeActivationRunner` | `effce770` |
| `runtime-activation.blade.php` (unchanged) | `47306da9` |

## Disposable setup

- Fixture app: **activation-proof** at `https://activation-proof.test` on app-dev `10.6.0.4`
- Source material: `apps/docs` Laravel app
- Prepared overlay initially lacked `vendor` and root write permission; corrected only inside the disposable topology
- Installed locked Composer dependencies inside the disposable topology
- Baseline root response before wake experiments: **200**, **70403** bytes, `<title>Laravel</title>`

## Soft wake

**Setup:** real hibernator with synthetic **+2h** clock; cold marker **false**; Composer dependency **present**.

**Request** (from operator): `GET /?wake=soft-final-proof&deep=1`

| Observation | Result |
| --- | --- |
| First response status / time | **503** in **1.643362s** |
| Meta refresh URI | exact `/?wake=soft-final-proof&deep=1` |
| Visual chrome | existing `svg.logo` and `role=progressbar` |
| Retry link | **none** (no `.retry`) |
| First poll (no manual action between request and poll) | **200**, **70403** bytes, title Laravel |

Proves automatic detached runner completion and exact original path/query continuation for soft activation.

## Cold wake

**Setup:** two real hibernator sweeps with synthetic **+8d** clock; state `awake=false`, `hibernated=true`, `cold=true`; Composer `present=false` / `reconstructable=true`.

**Request:** `GET /?wake=cold-final-proof&deep=1`

| Observation | Result |
| --- | --- |
| First response status / time | **503** in **2.312824s** |
| Meta refresh URI | exact `/?wake=cold-final-proof&deep=1` |
| Visual chrome | existing `svg.logo` and `role=progressbar` |
| Retry link | **none** (no `.retry`) |
| Poll sequence | **503**, **503**, **200** |
| Final body | **70403** bytes, title Laravel |

## Durable operation state (cold)

| Field | Value |
| --- | --- |
| Operation run id | `aef2602e-7a3c-459b-962c-553cdedcff00` |
| Status | `succeeded` |
| Result | `runtime_activation` scope `app-instance-1`, `cold=true` |

**Events sequence:**

1. `tree` with steps `dependency:composer` and `process:2`
2. dependency `active` → `done`
3. process `active` → `done`
4. `complete` with `exit_code=0`

**After convergence:** `cold_after=false`, `deps_ready=true`.

## Negative / visual contract

Both successful first responses presented only the existing logo + progress-bar surface (no `.retry`). Hidden `sr-only` step markup is pre-existing and visually unchanged; this proof does **not** claim step markup is absent from the HTML.

## Prior automated verification (same feature tip)

| Check | Result |
| --- | --- |
| Focused Pest | 48 passed / 234 assertions |
| Docs lint | passed (errors=0) |
| Full quality-check | receipt `.orbit/quality-gates/quality-check-2026-08-03T074841Z-05bcfaf3fd3a.json` at exact HEAD |
| Independent review | PASS; blast-radius complete; human-judgment=not-required |

## Boundary

This is **retained disposable Incus** proof (`dev-b9f618`), not a production or standing live-fleet deployment. Prepared artifact lag required the isolated candidate-image overlay and localhost:5000 digest pin described above. End-to-end exercise still covered: browser-style request, digest-pinned launcher, detached runner, dependency restore (cold), process start, and exact original path/query continuation after readiness.
