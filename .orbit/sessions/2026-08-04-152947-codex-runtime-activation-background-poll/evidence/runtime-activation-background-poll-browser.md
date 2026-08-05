# Browser runtime proof — runtime activation background poll

## Checkout identity

| Field | Value |
| --- | --- |
| Feature branch | `codex/runtime-activation-background-poll` |
| Browser-proof candidate HEAD | `ac766f4be2a3e4e1c02be53aa04f4a3c99d0c743` |
| Post-main-merge feature tip | `eb30aa9ce5dfc2bd00c9cb783b9f241d26a3b7f1` |
| Merged main tip | `eec2d757646f73c112fada2cf4ca363deb50aac3` |
| Worktree | `/Users/nckrtl/orbit/.worktrees/codex-runtime-activation-background-poll` |

### Main-merge carry-forward boundary

After browser proof, main advanced with three unrelated ADE packaging/archive
commits. Feature branch merged current main at `eb30aa9ce5df`. Activation
implementation, view, tests, and product docs blobs are **byte-identical**
between `ac766f4be2a3` and `eb30aa9ce5df` (see
`.orbit/evidence/runtime-activation-post-main-merge-blobs.txt`,
`ACTIVATION_BLOBS_UNCHANGED_BY_MAIN_MERGE=yes`). Browser runtime proof is
therefore carried forward to the merge tip without re-running the retained
browser venue. Fresh focused soft/cold suites and `composer quality-check` were
re-run on `eb30aa9ce5df`.

## Topology

| Field | Value |
| --- | --- |
| id | `dev-e8821e` |
| kind | `operator_gateway_app-dev` |
| provider | `incus` |
| host | `beast` |
| release | `composer e2e:incus -- --stop --id=dev-e8821e` |

### Instances

| Role | Instance |
| --- | --- |
| operator | `orbit-e2e-dev-e8821e-operator` |
| gateway | `orbit-e2e-dev-e8821e-gateway` |
| dev | `orbit-e2e-dev-e8821e-dev` |

## Access path (Mac browser)

| Item | Value |
| --- | --- |
| Fixture domain | `activation-proof.test` |
| Browser base | `https://activation-proof.test:8443` |
| Transport | local SSH forward **PID 56732** listening on `127.0.0.1:8443` → beast → dev socat `10.232.1.162:18443` → retained Caddy `10.6.0.4:443` |
| Host resolution | browser host-resolver rule only (`activation-proof.test` → `127.0.0.1`); **no `/etc/hosts` edit** for the final Codex browser session |
| TLS | Orbit CA / self-signed; browser proof used ignore-HTTPS-errors as needed |

## Boundary (do not overclaim)

- This run proves **page-only** continuous mount, five-second non-overlapping same-origin background fetch polling, pending/failed header handling, and one terminal navigation on application handoff (missing Orbit activation-state header).
- Backend **detached runner** convergence was already retained-proven on a prior feature and is **unchanged** by this candidate. Prepared runner/gateway artifacts on this topology were stale; the final handoff used the same normal product effects the cold runner applies after process readiness: `runtime-warm` + `runtime-awake` for `app-instance-1`, with FrankenPHP healthy. **Do not claim runner image/launch proof from this run.**

## Preparation limitations (disclose)

1. Live Mac operator mint failed due to unrelated empty `wireguard_peers` intent in the prepared topology DB. Actual gateway↔dev WireGuard data plane was healthy (ping `10.6.0.2` ↔ `10.6.0.4`).
2. Browser access used SSH forward through retained Caddy rather than live Mac WireGuard identity.
3. Prepared FrankenPHP/gateway artifacts were stale and received **disposable local** tags/overlay only (not pushed/deployed).
4. Nothing was pushed or deployed to standing fleets.

---

## Codex browser observations

Browser session id: **orbitpoll**. Presentation remained the approved black Orbit-mark-only page while pending; screenshots were visually inspected.

### 1) Initial pending document (long-lived mount)

| Field | Value |
| --- | --- |
| URL path/query | `/?wake=browser-poll-proof&deep=1` |
| token | `03c8adb4-e2d9-4dcd-af09-0b673e00d4a0` |
| timeOrigin | `1785848947126.4` |
| navigationEntries | `1` |
| logo | `true` |
| metaRefresh | `false` |
| scriptCount | `1` |

Wrapped real `window.fetch` without changing outcomes. Trace showed **13+** real GETs returning **status 503**, **type basic**, **state pending**.

Representative probe timing (ms):

| # | start | end |
| --- | --- | --- |
| 1 | 70619 | 71627 |
| 2 | 76628 | 77779 |
| 3 | 82781 | 83942 |
| 4 | 88944 | 89926 |
| 5 | 94927 | 95970 |

- Each next start is about **5000 ms** after the prior end.
- **maxInflight = 1** (non-overlapping).
- Same token / timeOrigin / navigationEntries throughout.
- Pending screenshot: `.orbit/evidence/runtime-activation-background-poll-pending.png`

### 2) Failed activation terminal navigation

One explicit **failed** Orbit activation-state header caused **exactly one** terminal navigation to a new failed document:

| Field | Value |
| --- | --- |
| token | absent / new document |
| timeOrigin | `1785849207061.6` |
| logo | `true` |
| retry | `true` |
| retry href | original query **plus** `orbit-wake-retry=1` |
| scriptCount | `0` |
| metaRefresh | `false` |

Failed screenshot: `.orbit/evidence/runtime-activation-background-poll-failed.png`

### 3) Fresh queued pending + handoff (final proof)

| Field | Value |
| --- | --- |
| Operation run | `803aba54-7a51-4e85-817e-33d264521dd7` (queued; no runner launch) |
| Retry URL | `/?wake=browser-poll-proof&deep=1&orbit-wake-retry=1` |
| token (before handoff) | `be5eb5bd-7a2a-4eb0-aa86-42155f6e89d9` |
| timeOrigin | `1785849531049.8` |
| navigationEntries | `1` |

Three pending probes (maxInflight=1):

| # | start | end | state |
| --- | --- | --- | --- |
| 1 | 6358 | 8110 | pending |
| 2 | 13111 | 14283 | pending |
| 3 | 19285 | 20405 | pending |

Worker then applied **`runtime-warm` + `runtime-awake` only** (no browser URL hit) while FrankenPHP was healthy.

- Next scheduled browser probe saw **application handoff / no Orbit activation-state header** and automatically used **`location.replace`**.
- Final document: same exact retry path/query URL; **title Laravel**; body **"Let's get started..."**; logo=false; retry=false; token absent; timeOrigin `1785849581932.3`; navigationEntries=1.
- After another **10 s**, timeOrigin remained identical.
- After clearing historical expected-503 console entries, another **6 s** produced **no console or page errors**.
- Ready screenshot: `.orbit/evidence/runtime-activation-background-poll-ready.png`

---

## Cleanup requirements

Leave topology/forward only until any remaining human acceptance is finished; then:

1. **SSH forward (Mac)**  
   - PID **56732** (listens on `127.0.0.1:8443` / `[::1]:8443`)  
   - Stop: `kill 56732` (or clear ControlMaster local forward to beast if reused)

2. **Dev VM socat** (if still running)  
   - On `orbit-e2e-dev-e8821e-dev`: `sudo pkill -f 'socat TCP-LISTEN:18443'` (or equivalent)

3. **Retained Incus topology**  
   ```bash
   cd /Users/nckrtl/orbit/.worktrees/codex-runtime-activation-background-poll
   composer e2e:incus -- --stop --id=dev-e8821e
   ```

4. **Disposable gateway env** (only if topology kept alive and not stopped):  
   - `ORBIT_RUNTIME_ACTIVATION_QUEUED_TIMEOUT_SECONDS=3600` and any `ORBIT_GATEWAY_IMAGE=` pin on gateway `~/.config/orbit/.env` are topology-local and disappear with stop.

5. **Do not** push disposable image tags/overlays; do not deploy.

## Related evidence files

- `.orbit/evidence/runtime-activation-background-poll-pending.png`
- `.orbit/evidence/runtime-activation-background-poll-failed.png`
- `.orbit/evidence/runtime-activation-background-poll-ready.png`
- `.orbit/evidence/runtime-activation-pending-ready-gateway.txt`
- `.orbit/evidence/runtime-activation-awake-ready.txt`
- `.orbit/evidence/runtime-activation-background-poll-red.txt` (TDD RED)
- `.orbit/evidence/runtime-activation-background-poll-green-focused.txt`
- `.orbit/quality-gates/quality-check-2026-08-04T124103Z-309355ddb99e.json`
