# Todo 771 — Retained-Incus Runtime Proof

## Identity
- Candidate: `5ebbe301a29db8afb0026f6c09679ddc285b049d`
- Venue: retained-incus
- Environment: dev-fixture
- Topology: `orbit-e2e-dev-40dc61` (operator_gateway_app-dev; roles operator, gateway, dev)
- Source binding: operator VM `/home/orbit/orbit` verified bound to the candidate by
  file-content sha256 (worktree == VM):
  - `apps/gateway/routes/api.php`
    `0950442bda3b17135e45f1a713a19289b260aa8628301ad759d56cde765aa20f`
  - VM `grep` confirms the Codex list route is `/codex/projects`.

## What was exercised
The Codex controller + external-project vocabulary guard Pest, executed inside the retained
operator VM against the candidate-bound source (freshly migrated SQLite DB):

- `tests/Feature/Http/Api/CodexAppControllerTest.php` — Codex list/add/remove behavior
  including the NEW negative test asserting the obsolete `GET /api/codex/apps` list path 404s.
- `tests/Feature/ExcludedExternalProjectVocabularyGuardTest.php` — the guard now PINS the GET
  list route line `Route::get('/codex/projects', …)` and confirms CLI `gatewayGet('/api/codex/projects')`.

## Observed
```
Tests: 11 passed (70 assertions)
Duration: 3.50s
```

## Receipt (structured)
- candidate=`5ebbe301a29db8afb0026f6c09679ddc285b049d`
- venue=retained-incus
- environment=dev-fixture
- expected=Codex list route restored to /codex/projects (mutations unchanged on /codex/apps/{project}); obsolete GET /api/codex/apps list path returns 404; guard pins the list route — all green in the retained operator VM
- observed=11 passed / 70 assertions, 0 failures
- result=passed
- command=`ssh beast incus exec orbit-e2e-dev-40dc61-operator -- sudo -u orbit bash -lc 'cd /home/orbit/orbit/apps/gateway && export HOME=/tmp XDG_CONFIG_HOME=/tmp APP_ENV=testing DB_DATABASE=/tmp/771-test.sqlite && touch /tmp/771-test.sqlite && php artisan migrate --force --no-interaction && php artisan test tests/Feature/Http/Api/CodexAppControllerTest.php tests/Feature/ExcludedExternalProjectVocabularyGuardTest.php --compact'`

Release: `composer e2e:incus -- --stop --id=dev-40dc61`
