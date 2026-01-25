# Review: orbit-web-migration

Reviewed: January 17, 2026
PRD: docs/plans/orbit-web-migration/prd.md
Reviewer: plan-reviewer (GPT-5.2)

## Summary

**Major Issues**

The PRD has a solid direction (single-environment web dashboard that delegates all business logic to `orbit-cli/web`), but several parts are internally inconsistent with “standard Laravel web app” constraints and with the existing code patterns in this repo (notably the current Saloon connector and the environment-scoped routing model). The biggest risks are (1) migrating desktop-only “local” storage/SSH key concepts into a server-deployed app with no auth, and (2) copying service/request layers that appear to be environment/TLD-shaped while simultaneously removing the `Environment` model.

## Strengths

- Clear scope cuts: no provisioning, no env switching, no auth.
- Correct architectural north star: orbit-web should be “thin” and call `orbit-cli/web` for all operations.
- Good call to reuse the existing Saloon request set (keeps API surface consistent).
- Simplified route sketch is a helpful target for the UX.

## Concerns

### High Priority

1. **“Local SQLite” + SSH keys don’t map to a web-deployed app**
   - Problem: The PRD repeatedly treats SQLite + settings/SSH keys as “local” (PRD:42-43, 10-16), but orbit-web is a server-deployed Laravel app at `orbit-web.<tld>`. Any SQLite DB will live on the server and be shared by all visitors.
   - Impact: With “no authentication” (PRD:23, 502-507), *anyone with the URL* can modify global settings and potentially upload/manage SSH keys. This is both a security hazard and a conceptual mismatch: a web app cannot use a visitor’s private SSH keys to SSH from the server to the visitor’s machine.
   - Suggestion:
     - Re-scope persistence into two buckets:
       - **Server-global config** (safe to store server-side): API base URL overrides, UI prefs that are OK to be shared.
       - **User-local prefs** (should be client-side): editor preference, terminal preference, “favorites” (if multi-user ever happens).
     - Drop (or heavily constrain) migrating `SshKey` and any UI that implies key material is managed in orbit-web. If SSH keys are still needed for some server-side operation, explicitly describe why and add at least a minimal access gate.

2. **Service/request layer is likely environment-shaped, but PRD removes `Environment`**
   - Problem: The PRD says “DO NOT MIGRATE: Environment.php” (PRD:158-160) but also says “COPY OrbitCli services” and “COPY OrbitConnector + 35 Request classes” (PRD:108-116). In this repo, the connector includes `OrbitConnector::forEnvironment(string $tld)` and hardcodes `https://orbit.{tld}/api` (see `app/Http/Integrations/Orbit/OrbitConnector.php:57-60`). That strongly suggests the integration layer currently expects a per-environment TLD.
   - Impact: Copying these classes into orbit-web without an `Environment` concept will either force awkward shims (which contradicts “single environment”) or cause pervasive refactors later. It also risks PRD/implementation drift: PRD says update base URL to `http://orbit.<tld>/api`, but the existing connector uses HTTPS and derives URL from a TLD.
   - Suggestion:
     - Decide on a single source of truth for the API base URL in orbit-web: `ORBIT_API_URL` env var is the cleanest.
     - Plan a small, explicit refactor step: change the connector to resolve base URL from config/env (and stop using `forEnvironment($tld)`), then adapt request classes/services accordingly.
     - Update the PRD’s “COPY” sections to call out what will actually change (connector constructor signature, config wiring, any request path differences).

3. **WebSocket/Echo integration is underspecified for a cross-subdomain setup**
   - Problem: The PRD says “connect to orbit-cli’s Reverb instance” (PRD:14-15, 41-43) and lists `laravel-echo` + `pusher-js` (PRD:299-304), but does not specify:
     - Which channels/events orbit-web must subscribe to.
     - Whether those are public vs private channels.
     - Required Reverb/Caddy allowed-origin settings for `orbit-web.<tld>` → `orbit.<tld>`.
     - How the frontend gets the Reverb config (PRD proposes `GET /reverb-config` on orbit-web, but orbit-web itself “doesn’t own” Reverb).
   - Impact: Very easy to “wire Echo” but still never receive events in production (origin blocked, wrong host/port, wrong key, private channel auth missing, etc.). Also, Caddy reloads are known to drop WS connections (see `CLAUDE.md:744-751`), and the UI must tolerate missed events.
   - Suggestion:
     - Add a concrete contract section:
       - Channel names, event names, and payload shape expected.
       - Public channels only (recommended given no auth), or if private channels are required, specify how `/broadcasting/auth` will work.
     - Add explicit deployment requirements:
       - Reverb allowed origins include `https://orbit-web.<tld>`.
       - Caddy routes/proxy for Reverb websocket support.
     - Add a fallback strategy: if WS is disconnected/missed, poll `GET /projects/{slug}/provision-status` to reconcile state.

### Medium Priority

4. **API base URL scheme conflicts with existing connector and likely deployment reality**
   - Problem: PRD says `ORBIT_API_URL=http://orbit.<tld>/api` (PRD:315-324) and “update base URL to http://orbit.<tld>/api” (PRD:113-116), but the existing connector’s helper uses HTTPS (`OrbitConnector.php:59`) and disables TLS verification globally (`OrbitConnector.php:45-50`).
   - Suggestion:
     - Decide whether the server-to-server call should be `https://orbit.<tld>/api` (preferred) or `http://` (only if you can guarantee it’s isolated/internal).
     - Avoid blanket `verify=false` in a production web app unless you have a very specific reason.

5. **Route sketch has likely collisions and unclear identifiers**
   - Problem: The PRD mixes `{name}` and `{slug}` for projects (PRD:210-213) and uses unbounded string params in places that will conflict with fixed routes like `/projects/create` and `/workspaces/create`.
   - Impact: Subtle routing bugs and security issues (e.g., allowing arbitrary path-ish strings).
   - Suggestion:
     - Standardize on one identifier (slug strongly preferred if orbit-cli/web uses slug).
     - Add route constraints (e.g., `where('project', '[A-Za-z0-9._-]+')`) and reserve keywords (`create`, `available`, etc.).

6. **“Open terminal” and editor URL logic is inconsistent with how the desktop app works**
   - Problem: PRD says for “local environments” return `cursor://file{path}` (PRD:330-336). In practice, editor integration for remote SSH uses vscode-remote style URLs (see `CLAUDE.md:247-250`). Also, clipboard fallback needs to be implemented in the browser UI, not on the server.
   - Suggestion:
     - Reframe this feature as “Generate connection URLs” (SSH + editor URLs) and implement copy-to-clipboard + clickable links in Vue.
     - Treat OrbitSSH.app as optional client tooling; document that it only applies to macOS clients.

7. **Filament scaffold vs Inertia/Vue stack not reconciled**
   - Problem: PRD says orbit-web already exists as “Laravel 12 + Filament scaffold” (PRD:343-349) and also mandates “Set up Inertia.js with Vue 3 + TypeScript” (PRD:347-349).
   - Impact: Extra complexity/duplication in frontend tooling, routing, auth assumptions, layouts.
   - Suggestion: Explicitly decide whether Filament remains (admin-only, separate) or is removed/unused for this dashboard.

### Low Priority

8. **Doctor integration details are fuzzy**
   - Note: PRD says “DoctorService.php (refactor to use `orbit doctor --json`)” (PRD:121-124, 256-258). In orbit-web, you likely should call an existing `orbit-cli/web` API endpoint rather than running the CLI directly. If orbit-cli/web doesn’t expose it yet, the PRD should say that dependency exists.

## Missing Edge Cases

- [ ] Shared-state issues with no auth: multiple visitors overwriting settings/favorites.
- [ ] Orbit API returns non-200 / invalid JSON / slow responses: consistent error UI + retry/backoff.
- [ ] WebSocket drops during Caddy reload: event missed → UI must reconcile via polling.
- [ ] Cross-origin WS connection blocked by Reverb/Caddy: clear diagnostics and “connection status” UI.
- [ ] Route param injection: project/workspace names containing `/`, spaces, unicode; define allowed charset.
- [ ] orbit-cli/web API version drift: how orbit-web detects incompatible API responses.

## Questions for DoD Interview

1. Should any persistence (settings/favorites) be **per-user** (browser localStorage) vs **server-global** (DB), given “no auth”?
2. Are Reverb channels/events **public** (no auth) or **private** (requires `/broadcasting/auth`)? Which exact channel names/events must orbit-web subscribe to?
3. What is the canonical identifier for projects across the UI and API: **slug** or **name**?
4. Is `ORBIT_API_URL` expected to be `https://orbit.<tld>/api` in production, and if so can we remove `verify=false` from the connector?

## Verification Suggestions

### Phase 1
- [ ] Confirm Filament + Inertia/Vite build coexist cleanly (or decide to remove Filament).
- [ ] Confirm `ORBIT_API_URL` and Reverb config are injected to the frontend (likely `VITE_` vars).

### Phase 2
- [ ] Validate migrations create tables on the intended DB (server-side SQLite path).
- [ ] Confirm no feature depends on storing private SSH keys server-side.

### Phase 3
- [ ] Add a smoke test request using Saloon against `GET /api/status` (or equivalent) and verify error handling on failure.
- [ ] Ensure request classes don’t require `Environment`/TLD inputs after the single-env refactor.

### Phase 4
- [ ] Ensure all service methods return consistent `{success,data,error}` structures for controllers to render.

### Phase 5
- [ ] Run route list and verify no collisions (`/projects/create` vs `/projects/{project}` etc.).
- [ ] Confirm controllers are stateless where Vue will parallel-fetch to avoid session lock issues.

### Phase 6
- [ ] Verify Echo connects to `orbit.<tld>` via WSS from `orbit-web.<tld>`.
- [ ] Validate reconnect + polling reconciliation when WS disconnects.

## Recommendation

**Major revision needed**: address the “local storage/SSH keys” mismatch, clarify the single-env API base URL approach (and refactor plan), and specify the WebSocket channel/event contract + deployment config requirements.
