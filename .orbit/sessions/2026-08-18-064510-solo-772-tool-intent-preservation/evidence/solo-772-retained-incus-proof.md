# Todo 772 — Retained-Incus Runtime Proof

## Identity
- Candidate: `68f6a228aa09d00b13b60e59b915b6d4b83700da`
- Venue: retained-incus
- Environment: dev-fixture
- Topology: `orbit-e2e-dev-eaeb74` (operator_gateway_app-dev; roles operator, gateway, dev)
- Source binding: operator VM `/home/orbit/orbit` verified bound to the candidate by
  file-content sha256 (worktree == VM):
  - `apps/gateway/app/Services/Tools/ToolInstaller.php`
    `5e2d0a6a67ffdeb642a38c8c969859edd1a6250653803cf1701327a8cc8400a3`
  - VM `grep -c row->delete ToolInstaller.php` → **0** (transport-branch delete removed).

## What was exercised
The ToolInstaller post-write-failure intent matrix + install-controller Pest, executed
inside the retained operator VM against the candidate-bound source (freshly migrated SQLite):

- `tests/Unit/Services/Tools/ToolInstallerPostWriteFailureIntentTest.php` — NEW row +
  transport failure, PRE-EXISTING row + transport failure (prior intent unchanged), NEW row +
  script(nonzero-exit) failure, PRE-EXISTING row + script failure, and reconciliation
  observing identical `expected_version`/`expected_state`.
- `tests/Feature/Http/Api/ToolInstallControllerTest.php` — install API boundary incl. the
  preserve-on-failure behavior.

## Observed
```
Tests: 42 passed (298 assertions)
Duration: 6.81s
```

## Receipt (structured)
- candidate=`68f6a228aa09d00b13b60e59b915b6d4b83700da`
- venue=retained-incus
- environment=dev-fixture
- expected=every post-write install failure (transport + script, new + pre-existing rows)
  preserves the expected-intent NodeTool row observed green in the retained operator VM
- observed=42 passed / 298 assertions, 0 failures
- result=passed
- command=`ssh beast incus exec orbit-e2e-dev-eaeb74-operator -- sudo -u orbit bash -lc 'cd /home/orbit/orbit/apps/gateway && export HOME=/tmp XDG_CONFIG_HOME=/tmp APP_ENV=testing DB_DATABASE=/tmp/772-test.sqlite && touch /tmp/772-test.sqlite && php artisan migrate --force --no-interaction && php artisan test tests/Unit/Services/Tools/ToolInstallerPostWriteFailureIntentTest.php tests/Feature/Http/Api/ToolInstallControllerTest.php --compact'`

Release: `composer e2e:incus -- --stop --id=dev-eaeb74`
