# Todo 765 — Blast Radius Inventory

Candidate: `208409f16517fb9556453d602842eb1ce7d7815c`
Base: `694f024f` (main incl. todo 764)

## Method

Explore sweep + diff review of the gateway's dead E2E facade vs the live external
apps/e2e harness. Removal touches only gateway composer/phpunit/Pest facade surface and
its asserting tests; the live harness, the App\E2E\Support remap, the reserved gateway
support classes, and the focused gateway E2E support tests are preserved.

## Result — complete

Diff `694f024f..208409f` — 6 files, +29 / −89 (net removal):

- **apps/gateway/composer.json** (−51): removed the dead E2E script block (`test:e2e`,
  `test:e2e:docker`, `test:e2e:docker:canary`, `test:e2e:incus`,
  `test:e2e:topology-contract`, `test:e2e:provision`, `e2e:preflight`, `e2e:reap-*`,
  `e2e:prepare-*`) that invoked gateway `artisan e2e:*` commands which do not exist, or
  the empty E2E suite. KEPT `test`, `test:slow`, and the `App\E2E\Support` autoload remap.
- **apps/gateway/phpunit.xml** (−3): removed the empty `<testsuite name="E2E">` pointing
  at `tests/E2E`.
- **apps/gateway/tests/Pest.php** (−17): removed the `->group('e2e')->in('E2E')` grouping
  and the dangling `orbitE2eRequiresEnvironment` reference to the non-existent
  `tests/E2E/Ephemeral/AgentNodeProvisioningTest.php`. KEPT the `tests/E2E/Support/Pest.php`
  require and the ORBIT_E2E handling used by live support.
- **apps/gateway/mago-linter-baseline.toml** (−6): dropped baseline entries for the
  removed facade code.
- **apps/gateway/tests/Feature/Architecture/GatewayComposerBoundaryTest.php** (+25/−…):
  inverted — now asserts the dead script keys are ABSENT (was asserting present).
- **apps/gateway/tests/Feature/E2ESupport/VerificationScriptsTest.php** (updated):
  adjusted references to the removed gateway E2E scripts.

## Preserved (verified intact — NOT in the diff)

- `composer.json:` the `App\E2E\Support` → `../e2e/app/E2E/Support/` autoload remap.
- The 6 reserved gateway `App\E2E\Support` classes (E2ENetwork, E2ENodeProbe,
  E2EReachability, IncusProvider, ProviderPool, ProviderSelection).
- All other `apps/gateway/tests/Feature/E2ESupport/*` tests.
- `apps/gateway/tests/E2E/Support/{Pest.php,SqliteDatabaseFixture.php}` (minimal path —
  `tests/E2E/` NOT deleted; E2EPestHelpersTest stays green).
- Root composer / RootGatewayForwardingShimTest / GatewayAppRelocationTest (the live
  external harness path — untouched).

## Out of scope (untouched)

The external apps/e2e harness; relocation of the 6 reserved support classes (later
batches); any root composer changes.

Result: complete — evidence=Explore facade sweep + diff review; gateway-pest 6924
passed/6 skipped exit 0; command discovery + root/apps/e2e ownership + gateway
unit-suite bootstrap intact.
