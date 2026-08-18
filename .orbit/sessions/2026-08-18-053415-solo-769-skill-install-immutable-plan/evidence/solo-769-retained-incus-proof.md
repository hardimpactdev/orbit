# Todo 769 — Retained-Incus Runtime Proof

## Identity
- Candidate: `89b3dff3f4cced1aef2e0a838640329fbef6a99a`
- Venue: retained-incus
- Environment: dev-fixture
- Topology: `orbit-e2e-dev-acba42` (operator_gateway_app-dev; roles operator, gateway, dev)
- Source binding: operator VM `/home/orbit/orbit` verified bound to the candidate by
  file-content sha256 (worktree == VM):
  - `apps/cli/app/Services/Skill/SkillInstallPlan.php`
    `8c42ad2f334f364f68a0aadd45cd4aff4a9f9420ad576b840d8dd8a3548c2297`
  - `apps/cli/app/Services/Skill/SkillInstallActions.php`
    `5e56279d26fdab72d69a76f18e99a32b18d85ff5cd8708fe56c1d6cec2b5a239`
  - `apps/cli/app/Commands/Skill/SkillInstallCommand.php`
    `89bf48905bd136c6fe94fae98993085d54233f9d0aaef15b674d5104b152b204`
  - `apps/cli/app/Services/Skill/SkillTargetResolution.php`: absent in VM (removed).

## What was exercised
The skill-install command + actions Pest, executed inside the retained operator VM against
the candidate-bound source (the CLI's own runtime environment):

- `tests/Feature/Commands/Skill/SkillInstallCommandTest.php` — command envelope/data,
  consent, force, missing-target/source paths.
- `tests/Unit/Services/Skill/SkillInstallActionsTest.php` — plan-built-once (resolve()
  called exactly once), install-time race revalidation, missing-source-at-install.

## Observed
```
Tests: 24 passed (103 assertions)
Duration: 2.06s
```
(pest-mutate cache mkdir warning is a harmless vendor-cache notice; all tests passed.)

## Receipt (structured)
- candidate=`89b3dff3f4cced1aef2e0a838640329fbef6a99a`
- venue=retained-incus
- environment=dev-fixture
- expected=single-resolution SkillInstallPlan flow (plan() once, install() revalidation-only,
  consent/force/error-envelope preserved) observed green in the retained operator VM
- observed=24 passed / 103 assertions, 0 failures
- result=passed
- command=`ssh beast incus exec orbit-e2e-dev-acba42-operator -- sudo -u orbit bash -lc 'cd /home/orbit/orbit/apps/cli && HOME=/tmp XDG_CONFIG_HOME=/tmp php vendor/bin/pest tests/Feature/Commands/Skill/SkillInstallCommandTest.php tests/Unit/Services/Skill/SkillInstallActionsTest.php --compact'`

Release: `composer e2e:incus -- --stop --id=dev-acba42`
