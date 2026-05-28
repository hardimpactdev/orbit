# Phase 4 E2E gateway-host `orbit <Artisan>` retarget audit

**Date:** 2026-05-28
**Cross-link:** Pre-sweep baseline at `docs/superpowers/notes/phase4-pre-sweep-inventory-2026-05-28.md`

---

## Section 1: Removed gateway-host `orbit <Artisan>` invocations

**Files touched: 22**
**Substitutions made: ~135**

Summary by file type:

### E2E support classes (implementation)
| File | Changes |
|------|---------|
| `apps/gateway/app/E2E/Support/E2EGatewayApi.php` | 5 substitutions: lines 48, 191, 270, 326, 390 — `orbit tinker` → `php apps/gateway/artisan tinker` |
| `apps/gateway/app/E2E/Support/DockerTopologyBuilder.php` | 7 substitutions: lines 659 (`orbit migrate`), 694 (`orbit orbit:internal:bootstrap-gateway-local`), 742 (`orbit orbit:internal:bake-app-node`), 748 (`orbit tinker`), 757 (`orbit orbit:internal:bake-ingress-node`), 774/790 (`orbit orbit:internal:bake-ingress-node/app-node prod`), 805 (`orbit orbit:internal:bake-agent-node`), 911 (`orbit tinker`) |
| `apps/gateway/app/E2E/Support/DockerTopologyProvider.php` | 7 substitutions: lines 702, 708, 713, 725-726, 741-742, 752-753 (all `orbit orbit:internal:bake-*` and `orbit tinker`) + 932 (`orbit tinker`) |
| `apps/gateway/tests/E2E/Support/Pest.php` | 2 substitutions: lines 200, 486 (`orbit tinker`) |

### NodeNewCommand.php
| File | Changes |
|------|---------|
| `apps/gateway/app/Console/Commands/NodeNewCommand.php` | 2 substitutions: lines 2336 (`orbit orbit:internal:bootstrap-gateway-local`), 2880 (`orbit orbit:internal:detect-platform`) |

### E2E test files (bulk sed)
63 E2E test files under `apps/gateway/tests/E2E/` had `&& orbit tinker --execute=` → `&& php apps/gateway/artisan tinker --execute=` (approximately 120 occurrences across all files).

### Feature test assertions updated
| File | Changes |
|------|---------|
| `apps/gateway/tests/Feature/E2ESupport/DockerTopologyBuilderTest.php` | 5 substitutions: strpos assertions for migrate/bootstrap-gateway-local, toContain assertions |
| `apps/gateway/tests/Feature/E2ESupport/DockerTopologyProviderTest.php` | 2 substitutions: `orbit tinker --execute=` in toContain assertions |
| `apps/gateway/tests/Feature/E2ESupport/E2EGatewayApiTest.php` | 3 substitutions: `orbit tinker --execute=` in collect/str_contains and toContain |
| `apps/gateway/tests/Feature/E2ESupport/E2EOperatorIdentityTest.php` | 1 substitution: negative guard updated from `orbit tinker` to `php artisan tinker` |
| `apps/gateway/tests/Feature/E2ESupport/E2EPestHelpersTest.php` | 5 substitutions: lines 572, 621, 625, 677, 681 |
| `apps/gateway/tests/Feature/Commands/NodeNewCommandTest.php` | 2 substitutions: Process::assertRan guards for bootstrap-gateway-local and detect-platform |
| `apps/gateway/tests/Feature/Runtime/FirstGatewayProvisioningContractTest.php` | Updated test description + inverted positive/negative assertions to expect `php apps/gateway/artisan orbit:internal:...` |

---

## Section 2: Remaining `orbit ...` references — classified

All remaining references from the post-retarget sweeps are intentional KEEPs.

### Public CLI surface docs

| Path:Line | KEEP reason |
|-----------|-------------|
| `apps/docs/content/domains/9_schedule/5_schedule-run/technical/1_schedule-run.md:16` | KEEP — public `orbit schedule:run` CLI usage doc |
| `apps/docs/content/domains/9_schedule/5_schedule-run/schedule-run.md:12,18,19` | KEEP — public `orbit schedule:run` CLI usage docs |

### `processes` table fixture rows

| Path:Line | KEEP reason |
|-----------|-------------|
| `apps/gateway/tests/E2E/ProcessListTest.php:62` | KEEP — `'command' => 'orbit queue:work'` is a database row inserted into a `processes` table fixture, not a shell invocation |

### Public CLI surface in E2E test commands (operator node)

| Path:Line | KEEP reason |
|-----------|-------------|
| `apps/gateway/tests/E2E/ScheduleRunTest.php:76` | KEEP — `orbit schedule:run` on operator node, public CLI surface |
| `apps/gateway/tests/E2E/ScheduleAddTest.php:62` | KEEP — `orbit schedule:run` as `--command=` argument value passed to `schedule:add`, public CLI surface |

### `orbit --version` smoke check

| Path:Line | KEEP reason |
|-----------|-------------|
| `apps/gateway/tests/E2E/PreparedTopologyContractTest.php:237` | KEEP — `&& orbit --version` verifies the public CLI binary responds after Phase 4B; do not change |

### Existing gateway-maintenance entry points (already correct)

| Path:Line | KEEP reason |
|-----------|-------------|
| `bin/_orbit-gateway-paths.sh:24,38` | KEEP — gateway-Artisan path resolution helper, intentional |
| `bin/install-orbit:877` | KEEP — explicit `php /opt/orbit/apps/gateway/artisan migrate --force`, already correct |
| `bin/orbit:147` | KEEP — launcher fallback line, owned by ORBIT-CLI-04B |
| `bin/quality-check.sh:67` | KEEP — host-side `bin/orbit-gateway-artisan` invocation, not container-side |
| `docker/orbit-runtime/entrypoint.sh:20,37` | KEEP — runtime image entrypoint, references artisan file location |
| `apps/gateway/tests/E2E/RegistryPromptInputModeTest.php:156` | KEEP — already uses explicit `php {$checkout}/apps/gateway/artisan` with ORBIT_IS_GATEWAY=1 |

### Already-correct `php apps/gateway/artisan` references (no change needed)

All references in:
- `apps/gateway/tests/E2E/ToolLifecycleHostInitTest.php` — already used `php apps/gateway/artisan` before this sweep
- `apps/gateway/tests/E2E/FirewallDoctorAdoptTest.php` — already used `php apps/gateway/artisan`
- `apps/gateway/tests/E2E/NodeUpdatesDoctorTest.php` — already used `php apps/gateway/artisan`
- `apps/gateway/tests/E2E/NodeListAgentTopologyTest.php` — already used `php apps/gateway/artisan`
- `apps/gateway/app/Console/Commands/VpnCommandSupport.php:122` — already used `php apps/gateway/artisan`
- `apps/gateway/tests/Unit/Console/Commands/VpnCommandSupportTest.php` — already correct
- `apps/gateway/tests/Unit/Services/RemoteShell/RemoteOrbitRuntimeExecutorTest.php` — already uses `php apps/gateway/artisan` for `docker exec orbit-runtime` patterns (RemoteOrbitRuntimeExecutor internal API)
- `apps/gateway/tests/Feature/E2ESupport/E2ECurrentCheckoutTest.php` — already uses `php apps/gateway/artisan`
- `apps/gateway/tests/Feature/E2ESupport/IncusTopologyBuilderTest.php` — already uses `php apps/gateway/artisan`
- `apps/gateway/tests/Feature/E2ESupport/IncusTopologyTemplateTest.php` — already uses `php apps/gateway/artisan`

### Docs that correctly describe the gateway-maintenance entry point

| Path | KEEP reason |
|------|-------------|
| `apps/docs/content/concepts.md:42` | KEEP — correctly states `bin/orbit-gateway-artisan` or `php apps/gateway/artisan` for maintenance |
| `apps/docs/content/tech-stack.md:89-91` | KEEP — correctly documents gateway maintenance entry points |
| `apps/docs/content/porting/testing-infrastructure.md:79` | KEEP — correctly describes `bin/orbit-gateway-artisan` usage |
| `apps/docs/content/domains/1_node/README.md:64` | KEEP — correctly documents gateway-maintenance entry |
| `apps/docs/content/domains/1_node/node-concepts.md:49-51,279-280` | KEEP — correctly documents gateway maintenance |
| `apps/docs/content/architecture.md:170` | KEEP — correctly documents gateway-maintenance entry |
| `apps/docs/content/execution-lanes.md:266,301` | KEEP — describes `docker exec orbit-runtime php apps/gateway/artisan migrate` in execution lane table |
| `apps/docs/content/testing/e2e/README.md:27` | KEEP — describes `bin/orbit-gateway-artisan e2e:test` for test execution |
| `apps/docs/content/testing/e2e/environment.md:59` | KEEP — describes `bin/orbit-gateway-artisan e2e:test` for test execution |
| `apps/docs/content/domains/authorization-matrix.md:135` | KEEP — describes `orbit:internal:*` command surface (concept, not invocation) |
| `apps/docs/content/domains/3_tool/dns-bootstrap-contract.md:10` | KEEP — references `orbit:internal:bootstrap-gateway-local` conceptually |

### `orbit:internal:*` command signatures (PHP class attributes)

All `#[Signature('orbit:internal:...')]` in `apps/gateway/app/Console/Commands/Internal/*.php` — KEEP, these are artisan command registration signatures.

### PHPStan build cache

| Path | KEEP reason |
|------|-------------|
| `apps/gateway/build/phpstan/resultCache.php:55391+` | KEEP — generated artifact, do not touch |
| `apps/gateway/build/phpstan/cache/PHPStan/**` | KEEP — generated cache, do not touch |

### `bin/orbit` binary references in E2E container setup

| Path:Line | KEEP reason |
|-----------|-------------|
| `docker/orbit-runtime/Dockerfile:33` | KEEP — `ln -s ... /usr/local/bin/orbit` runtime image setup |
| `docker/e2e/topology/Dockerfile:58-63` | KEEP — E2E container entrypoint setup |
| `apps/gateway/app/E2E/Support/DockerTopologyBuilder.php:521-522` | KEEP — installs `bin/orbit` symlink in container during topology build |
| `apps/gateway/app/E2E/Support/DockerTopologyProvider.php:575` | KEEP — reads source path from orbit binary for container inspection |
| `apps/gateway/app/E2E/Support/E2ECurrentCheckout.php:122,231` | KEEP — sets up orbit binary on container for current-checkout mode |
| `apps/gateway/app/E2E/Support/E2ENodeProbe.php:12` | KEEP — `orbit --version` probe to detect orbit presence on node |

### InstallOrbitLauncherTest / RootGatewayForwardingShimTest

Owned by ORBIT-CLI-04D. Not touched.

### `apps/cli/orbit` references

Owned by ORBIT-CLI-04C. Not touched.

---

## Section 3: Phase 4 matrix owner cross-reference

Each KEEP entry above maps to a row in the command-classification matrix at
`docs/superpowers/notes/cli-command-classification-2026-05-28.md`:

- Public CLI surface docs (`orbit schedule:run`, etc.) → **Public gateway-routed commands** section
- `processes` table fixture rows → **Fixture/test data** (not execution paths)
- `orbit --version` smoke → **CLI binary verification** row
- Launcher fallback (`bin/orbit:147`) → **Phase 4B: launcher rewrite** row
- Gateway-maintenance entry points already using `bin/orbit-gateway-artisan` or `php apps/gateway/artisan` → **Gateway maintenance** family
- `orbit:internal:*` command signatures → **Internal gateway commands** family
- PHPStan/cache artifacts → **Generated build artifacts**
- `bin/orbit` container symlink setup → **Container bootstrap** family
- InstallOrbitLauncherTest / RootGatewayForwardingShimTest → **Phase 4D** rows

---

## Section 4: Next steps

The following Phase 4 sub-tasks follow this retarget:

- **ORBIT-CLI-04B** — Launcher rewrite: updates `bin/orbit` to always enter `apps/cli/orbit` (removes the gateway-role host dispatch at `bin/orbit:147`)
- **ORBIT-CLI-04C** — Allow-list: updates `apps/cli/orbit` `isNativeCliCommand`/`passthruCommand`/`fallbackToGatewayArtisanWhenCommandIsUnported` for Phase 4 command set
- **ORBIT-CLI-04D** — Test inversion: updates `apps/gateway/tests/Feature/InstallOrbitLauncherTest.php` and `RootGatewayForwardingShimTest.php` to assert the new launcher shape
- **ORBIT-CLI-04E** — Verification gate: runs the full E2E suite against prepared topologies to confirm no gateway-host invocations fail with "orbit: command not found" style errors after launcher switch
