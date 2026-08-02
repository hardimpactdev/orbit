# Orphan proxy:remove contract proof

## Candidate

- Branch: `codex/orphaned-workspace-route-repair`
- Feature tip: `db1a60cc73f9847ed96c8ae56c540536926b6303`
- Main tip at proof: `9e4d7e8d6dd8b13a8912735590f740e11ab2f1ff`
- Hermes landing on main: `ded4d3296` (`Archive hermes managed dashboard session`) is an ancestor of main

## Explicit exclusions

- Live topology mutation forbidden by LAND instruction (no Beast/craft-starterkit-react repairs in this land).
- No release.

## Executable proof (diff-derived real surface)

Gateway intent and DELETE API (living deny + orphan allow):

```bash
bin/orbit-gateway-pest --compact \
  tests/Unit/Services/Proxy/ProxyRouteIntentTest.php \
  tests/Feature/Http/Api/ProxyRouteMutationControllerTest.php
# 17 passed
```

CLI human orphan safety prose:

```bash
cd apps/cli && ./vendor/bin/pest --compact tests/Feature/Commands/Proxy/ProxyWriteCommandTest.php
# 14 passed
```

Focused docs lint:

```bash
bin/orbit-docs-artisan librarian:lint --format=agent --path=domains/8_proxy/3_proxy-remove
# passed, 0 issues
```

Broader gate (external / orchestrator):

- artifact: `.orbit/quality-gates/quality-check-2026-08-02T205838Z-9c78c3e2945e.json`
- exit_code: 0
- commit: `db1a60cc73f9847ed96c8ae56c540536926b6303`
- dirty: false
- all subgates integer 0

## Contract asserted

1. Living workspace/app owners still denied with `proxy.owned_route_denied` under destructive consent.
2. Missing FK-backed owners (`workspace` / `app` family) allow removal with `removal_reason=orphan_owner` and public `owner_type`.
3. Human CLI output names orphan owner and safety reason.
4. `--force` is not a general ownership bypass.

## Review

Independent review PASS (orchestrator-attested) with human-judgment=not-required on exact feature tip above.
