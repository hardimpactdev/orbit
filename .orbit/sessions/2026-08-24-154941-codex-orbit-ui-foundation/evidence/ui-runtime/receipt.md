# Orbit UI local runtime proof

- Candidate: `c2b598568db0e3cc8023d2beb1e29380f7f2df80`
- URL: `https://orbit.nmbp/`
- Instance: `orbit.development` on `NMBP`
- Source: `/Users/nckrtl/orbit/.worktrees/codex-orbit-ui-foundation/apps/ui`
- Gateway configuration: `ORBIT_GATEWAY_URL=https://10.6.0.2`
- HTTP: `200`, trusted local TLS
- Browser title: `Ship your next idea in minutes - Orbit`
- Vite assets: `https://orbit.nmbp:5173/@vite/client` and `https://orbit.nmbp:5173/resources/js/app.tsx`
- Browser console: Vite connected; no error entries after reloading
- Runtime DOM: `agentation-root`, `toolbar-agentation-root`, and `laravel-toolbar-shadow-host` present
- Orbit runtimes: `orbit-app-orbit-development` and `orbit_orbit_development_main_vite` both running
- Screenshot: `.orbit/evidence/ui-runtime/orbit-nmbp-c2b5985.png`

The final candidate reload returned HTTP 200. Vite connected without browser errors. The Agentation and Laravel toolbar roots were present, and all Vite assets used the reachable `orbit.nmbp:5173` TLS host.
