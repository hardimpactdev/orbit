# Review: Orbit Desktop/Web Unification

Reviewed: 2026-01-17
PRD: docs/plans/orbit-unification/prd.md
Reviewer: plan-reviewer (GPT-5.2)

## Summary

Needs Work

The core idea (single codebase, mode-gated routing, and “implicit local environment” injection) is feasible in Laravel, but the PRD under-specifies several critical integration points that are very likely to cause breakage: route names/URLs, stateless API endpoints, NativePHP-only routes/services, and bootstrapping the single local environment.

## Strengths

- Clear top-level goal: one codebase with explicit mode flags.
- Good constraint: preserve existing `Environment $environment` controller/service signatures.
- Middleware injection is a reasonable way to keep signatures while offering “flat” web URLs.

## Concerns

### High Priority

1. **Middleware injection is feasible, but ordering and binding behavior is underspecified**
   - Problem: The PRD shows `ImplicitEnvironment` doing `$request->route()->setParameter('environment', $environment)`. This can work even when `{environment}` is not present in the URI, but only if the middleware runs after routing and before controller dispatch, and you consistently inject the actual `Environment` model instance (not an ID). Middleware order also matters relative to `SubstituteBindings` and any auth middleware that expects route params.
   - Impact: Controllers may receive `null`/missing environment, or route model binding may behave differently between modes, causing hard-to-debug 404/500s.
   - Suggestion:
     - Specify the middleware stack placement explicitly (e.g., within the `web` group, before/after `SubstituteBindings`).
     - Decide whether you want to inject a full model instance vs. an ID and let `SubstituteBindings` resolve it.
     - Add a dedicated test that hits a flat route and asserts the controller receives the correct `Environment`.

2. **Route naming and redirection conflicts are not addressed (and the PRD examples don’t match current reality)**
   - Problem: Current routes are heavily namespaced under `environments.*` and many redirects/controllers reference `environments.show`, `environments.create`, etc. Example: `app/Http/Controllers/DashboardController.php:15` always redirects to `environments.show` (desktop-style).
   - Impact: In web mode, “flat routes” will break existing redirects/tests unless you either:
     - keep route names stable across modes, or
     - update all route() usage and Inertia links conditionally.
   - Suggestion:
     - Define a compatibility strategy:
       - **Option A (recommended):** keep existing route *names* (`environments.projects`, etc.) in both modes, but change the *paths* to be flat in web mode.
       - **Option B:** introduce new flat route names (e.g. `projects.index`) and update all consumers (PHP + Vue + tests) behind a mode-aware URL helper.
     - Explicitly list which route names must remain stable for desktop and which are allowed to change.

3. **Stateless API route structure is a major gap (web mode likely needs flat API endpoints too)**
   - Problem: `routes/api.php` currently defines stateless endpoints under `api/environments/{environment}/...` (no session locking). The PRD only discusses web *page* routes (`/projects`, `/services`, etc.), but most Vue screens rely on API calls for data. In a unified web deployment, you likely want `/api/status`, `/api/projects`, etc. without `/{environment}`.
   - Impact: Web mode will either:
     - require leaking an environment ID into the frontend (breaking the “single env, no env UI” intent), or
     - force awkward rewrites of the frontend fetch layer, or
     - lose the stateless/parallel-request behavior.
   - Suggestion:
     - Extend the PRD to include API routing for both modes:
       - Desktop: keep `api/environments/{environment}/...`
       - Web: add flat equivalents `api/...` with implicit environment injection (and preserve stateless behavior)
     - Clarify which endpoints must remain stateless and ensure middleware is compatible with the `api` middleware group.

4. **Desktop-only capabilities present security and runtime risks in web mode**
   - Problem: Current `routes/web.php` includes desktop-only endpoints like `/open-external` and `/open-terminal` and imports NativePHP’s `Shell` facade unconditionally (`routes/web.php:10`). In a web deployment, these endpoints are either non-functional or actively dangerous.
   - Impact: Accidental exposure of “open terminal / open URL” endpoints on a server is a serious security risk; unguarded NativePHP references may crash web mode.
   - Suggestion:
     - Explicitly define which routes/features are desktop-only and must not be registered in web mode.
     - Gate these routes behind `ORBIT_MODE=desktop` and add authorization hardening assumptions (at minimum auth + CSRF + additional checks).
     - Add a test asserting these routes are not present in web mode.

### Medium Priority

5. **Bootstrap behavior for the single local environment is unclear and could be racy**
   - Problem: The PRD proposes `php artisan orbit:init` to create the local environment record. But the failure mode when it’s not run is undefined, and `ImplicitEnvironment` uses `firstOrFail()`.
   - Suggestion:
     - Specify web-mode startup expectations: does deploy always run `orbit:init`? If not, should requests show a setup page instead of 404?
     - Specify idempotency and concurrency behavior (two deploy processes running init).

6. **Edge case: “multiple local environments” is controlled in controller UI only**
   - Problem: The PRD says web mode “always uses single `is_local=true` environment, ignores others”. But the codebase currently prevents multiple locals only via controller logic (`EnvironmentController::store()`), not via a DB constraint.
   - Suggestion:
     - Define how web mode behaves if database already contains multiple `is_local=true` rows (pick newest? default? error?).
     - Consider adding a DB-level uniqueness constraint (even partial) or a cleanup strategy (even if just in `orbit:init`).

### Low Priority

7. **Feature flag semantics: `ORBIT_MODE` vs `MULTI_ENVIRONMENT_MANAGEMENT` overlaps**
   - Note: The PRD uses both flags; one implies the other. This isn’t wrong, but it needs a source-of-truth rule (e.g., `ORBIT_MODE=desktop` forces multi-environment true regardless of the second flag, or vice versa).

## Missing Edge Cases

- [ ] No local environment exists yet in web mode: what does `/` do (setup flow vs 404)?
- [ ] Local environment exists but `status != active`: should web mode proceed, auto-fix, or block?
- [ ] Multiple `is_local=true` rows in DB: deterministic selection and logging.
- [ ] Route caching enabled (`php artisan route:cache`): ensure conditional route registration and middleware injection still behave.
- [ ] API calls in web mode still need parallelism (session locking avoidance) without `/api/environments/{id}`.
- [ ] Desktop-only endpoints (`/open-terminal`, `/open-external`) accidentally exposed in web mode.
- [ ] Existing deep links/bookmarks: `/environments/{id}/projects` in web mode, and `/projects` in desktop mode (decide whether to redirect or 404).

## Questions for DoD Interview

1. In web mode, must route *names* remain identical to desktop mode (e.g. `environments.projects`), or are name changes acceptable if the paths are flat?
2. Should web mode have flat stateless API endpoints (e.g. `/api/projects`) in addition to flat page routes, or is it acceptable for the frontend to keep using `/api/environments/{id}/...`?
3. What is the required behavior if `orbit:init` hasn’t been run yet (setup UI, error page, or hard fail)?
4. Which desktop-only capabilities must be completely absent in web mode (e.g. `/open-terminal`, provisioning, SSH key management, DNS resolver), and what’s the expected auth model in web mode?

## Verification Suggestions

### Phase 1
- [ ] Feature flag config: boolean casting works for env strings (`"false"`, `"0"`, unset).
- [ ] Middleware injection test: a flat route hits controller and receives an `Environment` instance.
- [ ] Negative test: missing local environment returns the intended response (not an opaque 404).

### Phase 2
- [ ] `route:list` snapshot assertions per mode (key routes present/absent).
- [ ] Route name stability test (whatever DoD decides) to prevent regressions.
- [ ] API endpoints: verify stateless endpoints still exist and do not serialize requests.

### Phase 3
- [ ] Vue smoke test (or feature test rendering) that environment switcher and env CRUD links are hidden in web mode.
- [ ] Ensure any hardcoded paths like `/environments/{id}/...` are not used in web mode (or are redirected).

### Phase 4
- [ ] Feature tests for both modes that cover: dashboard redirect behavior, projects/services pages, and a representative API call.

## Recommendation

Address concerns first
