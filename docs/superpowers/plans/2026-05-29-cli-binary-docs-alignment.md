# Plan: Align docs — the Orbit CLI becomes a self-contained binary

## Goal

The Orbit CLI/local-executor artifact becomes a **self-contained native binary
with PHP 8.5 embedded** — not source, not a PHAR. The end-user outcome: Orbit
installs and runs as a single downloaded binary with **no host PHP to install or
manage**.

This is a **docs-led** change. Per the repo rule that product docs describe
correct behavior *before* implementation, **this pass rewrites the product docs**
(`apps/docs/content/`) to describe the binary; the binary build itself is the
downstream todo `…-02`. Today the docs assert the opposite — the CLI "runs from
source" (`tech-stack.md:97`) on host PHP 8.5 — so the docs must change first.

Host PHP is removed only as an **end-user** runtime/prerequisite. Developers and
CI still use PHP to build and test from source; the normal development loop is
unchanged.

## Status

Unblocked. The prerequisite —
`docs/superpowers/plans/2026-05-29-layered-e2e-live-topology-workflow.md` — has
landed: `apps/e2e` is the external E2E runner, root `test:e2e` runs
`bin/orbit-e2e-artisan e2e:test`, the retained dev-topology lane
(`composer e2e:dev-topology` / `…:release`) exists, and the layered-E2E lane
contract defines a **binary candidate lane** ("after source-checkout E2E passes,
a downstream binary plan builds the CLI once and runs targeted binary acceptance
against the built artifact"). The downstream build (`…-02`) feeds that lane.

Development-speed invariant: normal development and feature E2E keep using
source-checkout overlay. Binary builds happen only after source-checkout E2E has
passed; the `apps/e2e` binary candidate lane then proves the built artifact.

## The contract to encode

1. The CLI/local-executor artifact is a **self-contained native binary** embedding
   PHP 8.5 and the extensions the CLI requires: `pdo_sqlite`, `openssl`, `curl`,
   `mbstring`, `tokenizer`, `ctype`, `filter`, `fileinfo`, `json`, `phar` (plus the
   core `proc_open` function for `Symfony\Process`). Not source, not a PHAR.
   PHPacker's stock runtime already provides this set, so no custom runtime is
   required; `posix` is absent but Orbit's `posix_*` calls are
   `function_exists`-guarded, and `pcntl` is unused. The binary smoke gate fails if
   a required extension is missing.
2. Targets **macOS arm64 + Ubuntu x86_64**, downloaded by the installer, linked as
   the host `orbit` launcher target. On the primary build path (PHPacker) both
   targets are emitted from a single CI machine with no PHP compilation.
3. Host PHP 8.5 is not a prerequisite or runtime for the CLI; gateway PHP runs in
   `orbit-runtime`, app/workspace PHP in FrankenPHP.
4. Gateway is unchanged: it still runs from source mounted into `orbit-runtime`;
   maintenance is via `bin/orbit-gateway-artisan` / `php apps/gateway/artisan`.
5. A self-contained binary is **not** zero host dependencies. It embeds PHP, so it
   removes *host PHP* — not host *tooling*. The CLI shells out via `Symfony\Process`
   to `git`, `brew`/`dnsmasq`, `wg`, macOS/Linux trust-store tools, and `sudo`. The
   host prerequisite list still keeps Git, Docker, the launcher, WireGuard/SSH, and
   `gh`; only host PHP is dropped. Docs must not imply the binary needs nothing on
   the host.

## Scope

**Host-PHP statements split into two kinds — only the first changes:**

- **Change** — statements that host PHP 8.5 is the CLI/local-executor prerequisite
  or runtime: "host PHP 8.5 CLI for the CLI/local-executor artifact", "Host PHP is
  allowed for the CLI/local-executor artifact", "runs through host PHP 8.5 CLI … in
  the source-checkout distribution". The binary embeds its PHP; host PHP is not
  required to run the CLI.
- **Keep** — statements that host PHP / PHP-FPM is **not an app/workspace runtime
  fallback**, and that apps/workspaces do not install host PHP. These stay true and
  must not be touched.

**Unchanged by this pass:**

- Gateway distribution: still source mounted into `orbit-runtime`. Gateway bundling
  is a later, separate phase.
- Monorepo source on non-gateway nodes / migrate-everywhere.
- The binary build toolchain, CI, and `install-orbit`/E2E code — downstream
  implementation passes that these docs lead.

## Pre-flight — enumerate before editing

Per-file line numbers in this plan are **indicative**; docs drift as `apps/cli` and
the content tree evolve. Build the authoritative work-list by grep first, then
reconcile against the tiers:

```bash
rg -n "runs from source|There is no PHAR" apps/docs/content
rg -n "apps/cli/orbit" apps/docs/content
rg -n "host PHP" apps/docs/content        # classify each: change vs keep per Scope
rg -n "source-checkout|host-installed" apps/docs/content
```

Any hit not covered by a Tier 1 / Tier 2 / exclusion entry is a missing target —
add it before proceeding. `apps/cli/orbit` currently appears **15 times across 11
files**; confirm the live count before and after.

**ConceptIndexRule handling.** The CLI-path references live inside concept
*definition bodies* (`concepts.md` **Orbit launcher** L42, **RemoteLocalExecutor**
L47, **Host cwd context** L58), not bold term *names*, so no term name changes and
the concept-index name-sync does not fire. Edit the `concepts.md` index definition
**and** its source `*-concepts.md` mirror (node-concepts, execution-lanes,
workspace-concepts) consistently, keeping each bold term name byte-for-byte
identical.

## Tier 1 — product-authority docs (this pass)

### `apps/docs/content/tech-stack.md`
- **L97-99** ("Orbit runs from source. There is no PHAR…") → the CLI artifact is a
  self-contained binary with embedded PHP 8.5, built per OS/arch and downloaded by
  the installer; the gateway app still runs from source mounted into
  `orbit-runtime`.
- **L101-103** ("Host PHP CLI is required … source-checkout distribution") → the
  CLI binary embeds its PHP; host PHP is not required to run it.
- **L66** prereq row → drop "host PHP 8.5 CLI for the CLI/local-executor artifact
  with …"; add "the prebuilt Orbit CLI binary (embedded PHP 8.5 +
  pdo_sqlite/openssl/curl/mbstring/tokenizer/ctype/filter/fileinfo/json/phar)". This
  matches the extension set in contract §1; `proc_open` is a core function, not an
  extension, so it stays out of the enumeration. Keep Git, Docker, launcher,
  WireGuard/SSH, `gh` — the binary still shells out to these. The same widened list
  applies to the Installation paragraph at **L358**, which repeats the old five.
- **L84-95** (Application) → launches the installed Orbit CLI binary on every role;
  keep "never dispatches to gateway Artisan" + gateway-maintenance lines.
- **L148-152** (RemoteLocalExecutor lane) → runs in the binary's embedded PHP.
- **L201-213** (PHP runtime) → the CLI artifact runs in the binary's embedded PHP
  8.5; keep the FrankenPHP / "host PHP not an app/workspace fallback" lines.
- **L343-351** (Installation) → the installer downloads the prebuilt CLI binary and
  links it; remove host-PHP install and the "apps/cli Composer dependencies" step;
  keep the gateway-source / orbit-runtime / migrate steps. Separate "installed CLI
  binary" from "gateway source mounted into orbit-runtime"; do not imply gateway
  bundling or non-gateway source removal lands here.
- **The standalone "Orbit runs from source." sentence** (≈L350, opening the
  Installation section — distinct from the install-steps paragraph) is a blanket
  distribution claim that is now false for the CLI. Scope it to the **gateway**
  (gateway runs from source mounted into `orbit-runtime`; the CLI is the downloaded
  binary). Do not leave it as a bare "Orbit runs from source."
- **L363-374** (Platform) → state targets **macOS arm64 + Ubuntu x86_64**; "Public
  CLI calls … enter `apps/cli/orbit`" → "enter the Orbit CLI binary".

### `apps/docs/content/architecture.md`
- **L57** and **L164** ("CLI/local-executor artifact from the source checkout" /
  `apps/cli/orbit` source path) → "the installed Orbit CLI binary". L14/L129 are
  wording-only checks.

### `apps/docs/content/concepts.md`
- **L42, L47, L58** — rewrite source-path / `apps/cli/orbit` launcher language to
  "installed Orbit CLI binary" / "host `orbit` entrypoint" (per the ConceptIndexRule
  handling above).

### `apps/docs/content/execution-lanes.md`
- **L16-18** (host PHP only for source-checkout artifact) and **L31, L103, L108** →
  RemoteLocalExecutor invokes the installed binary's internal command surface with
  embedded PHP/PDO, not a host-installed `apps/cli`. **L225** ("host-installed
  CLI/local-executor artifact") → "installed Orbit CLI binary".

### `apps/docs/content/domains/1_node/`
- `node-concepts.md` **L43, L241-246, L273** and `README.md` **L57, L261-265, L413**
  → prerequisites: drop host PHP for the CLI, "download the Orbit CLI binary";
  separate the CLI binary from gateway-source-in-runtime; rewrite source-path
  launcher references.
- `9_node-default/technical/3_node-default_on-gateway-node.md` **L19** → "the public
  `apps/cli/orbit` command" → "the public Orbit CLI binary command". Launcher-path
  reword; behavior unchanged.

### `apps/docs/content/domains/15_agent-ide/`
- `README.md` **L48** and `agent-ide-concepts.md` **L86** → rewrite `apps/cli/orbit`
  source-path references to "installed Orbit CLI binary".

### `apps/docs/content/domains/14_php/`
- `2_php-use/php-use.md` **L29**, `…/technical/1_php-use.md` **L86**, `README.md`
  **L41** → reword "host CLI/local-executor" PHP-version cross-refs to "the Orbit CLI
  binary's embedded PHP 8.5". App/workspace version behavior unchanged.

## Tier 2 — testing / porting / provisioning (land with the E2E-harness impl)

These describe harness behavior, not the product contract, so they change alongside
(or just before) the downstream E2E implementation rather than in this product-docs
pass:

- `testing/README.md:23-24`, `testing/e2e/prepared-topologies.md:32,180`,
  `testing/e2e/provisioning.md:38,47`, `porting/testing-infrastructure.md:61,86`:
  "host PHP CLI for the CLI/local-executor artifact" + source-template /
  checkout-launcher language → "the installed Orbit CLI binary".
- `porting/testing-infrastructure.md:73` ("non-gateway nodes seed `apps/cli/.env`")
  → a binary-only node has no source tree, so the CLI binary reads executor/gateway
  config from `~/.config/orbit/config.json` (the `OrbitConfigStore` JSON layer) with
  process `env` overlaid on top (the precedence in `GatewayApiServiceProvider`). It
  does not read a source-tree `.env` (`apps/cli/.env` or `apps/gateway/.env`). State
  this single source of truth once; drop the `apps/cli/.env` seeding language.

## Exclusions

- **`update` / versioning docs** — not rewritten (versioning is out of scope).
  `domains/11_operation/operation-concepts.md:20`, `…/1_update/update.md:5`,
  `…/1_update/technical/1_update.md:10`, `…/2_update-all/update-all.md:5`, and
  `execution-lanes.md:277` keep describing source-checkout + `git pull` updates. Do
  not half-rewrite them; the binary-update/versioning contract is a separate
  follow-up.
- **"Runtime PHP binary"** (`concepts.md:493`, `php-concepts.md:61-62`) is the PHP
  binary inside app/workspace/gateway runtime containers, not the CLI — leave
  unchanged. Also leave `domains/3_tool/catalog/php-cli.md` (runtime-container PHP
  capability).
- **`execution-lanes.md` current-consumer inventory table (≈L300-366)** — rows whose
  notes say "current host PHP helper must be rewritten as host-substrate shell"
  describe the **gateway's** Docker-first execution-lane model
  (`apps/gateway/app/Services/**`), not the CLI/local-executor artifact's runtime.
  Their "host PHP" hits are a third category, distinct from both the change and keep
  buckets in Scope; the Pre-flight `host PHP` sweep surfaces them — classify them as
  exclusions and leave them unchanged.
- Gateway/`orbit-runtime` baking; removing monorepo source from non-gateway nodes;
  migrate-everywhere; binary build toolchain/CI; `install-orbit`/E2E code.

## Docs-lint authoring constraints (gate: `composer docs-lint`)

- **NoLegacyNarrativeRule:** do not introduce banned narrative terms — `legacy`,
  `previously`, `historical`, `no longer`, `old` — in `domains/` and `testing/`.
  Write the new state declaratively, not as a migration story.
- **ConceptIndexRule:** if a bold concept term in a `*-concepts.md` changes, update
  the matching `concepts.md` concept-index block; otherwise keep term names
  unchanged. (This pass changes definition bodies only — see Pre-flight.)
- **MarkdownLinkIntegrityRule:** keep cross-refs/anchors valid when rewording
  heading or link text.

## Decisions

- **Binary targets:** macOS arm64 + Ubuntu x86_64 only.
- **Build mechanism:** PHPacker primary (the path Laravel Zero documents) — it
  bundles a prebuilt, statically-linked PHP (built upstream with static-php-cli,
  redistributed as `phpacker/php-bin`) with the app PHAR, so the Orbit build does
  zero PHP compilation and emits all targets from one machine. PHPacker's stock
  extension set covers Orbit's needs (matching `php-bin/php-extensions.txt`), so no
  custom runtime is required. `static-php-cli` is the documented fallback for a
  custom extension set or full supply-chain control. The deployed artifact is a true
  native binary, not a PHAR. Product docs stay contract-first; recipes are in Build
  mechanism below.
- **Executor config source:** the CLI binary reads `~/.config/orbit/config.json`
  (the `OrbitConfigStore` JSON layer) with `env` overlaid on top. A binary node has
  no source tree, so there is no source `.env`.
- **Versioning / update flow:** out of scope. `update`/`update-all` docs stay as
  source-checkout contracts. The binary-update contract is a follow-up:
  `app/Services/Updates/LocalCheckoutUpdater.php` runs `git pull` in the checkout — a
  binary cannot self-update that way — and `CheckoutPathResolver.php:11` uses
  `base_path()` to locate the checkout, which is invalid from inside the binary. The
  follow-up defines a download-and-relink update path and fixes both.

## Build mechanism (implementation note for `…-02`)

Targets `apps/cli` (Laravel Zero v12 + `illuminate/http` + `orbit-core`). Two paths,
primary and fallback. Product docs stay contract-first; this scopes the build todo.

### Primary: PHPacker (no PHP compilation)
PHPacker bundles a prebuilt, statically-linked PHP (built upstream with
static-php-cli, redistributed as `phpacker/php-bin`, refreshed weekly) with the app
PHAR, so the Orbit build does zero PHP compilation and emits every target from a
single machine.

1. `composer install --no-dev --optimize-autoloader` in `apps/cli`.
2. **PHAR:** `php orbit app:build orbit` (Laravel Zero's `BuildCommand` shells
   `bin/box compile`). Add the missing `apps/cli/box.json` — the only build artifact
   not yet present.
3. **Bundle:** `phpacker build all --src=./builds/orbit.phar` (or `phpacker build mac
   arm` / `… linux x64`) → standalone binaries for both targets.
4. `install-orbit` downloads the matching artifact and links it as the host `orbit`
   launcher target.

PHPacker's stock `php-bin` set (`pdo_sqlite, sqlite3, curl, openssl, mbstring,
tokenizer, ctype, filter, fileinfo, phar, opcache, …`) contains Orbit's runtime set,
so no custom runtime is needed. `posix` is absent but `posix_*` is guarded; `pcntl`
is unused. If a future extension falls outside the stock set, point PHPacker at a
custom `php-bin` repo built with static-php-cli (the fallback below).

### Fallback: static-php-cli (`spc`) — custom extensions / full control
Per OS/arch on a native runner (`spc` does not cross-compile):

1. PHAR as above.
2. **Runtime (slow, cacheable):** `spc download --with-php=8.5 --for-extensions="<set>"`
   then `spc build "<set>" --build-micro`. Cache `buildroot/` + `downloads/` keyed on
   PHP version + extension-list hash — a cache hit skips the only slow step.
3. **Combine (fast, every change):** `spc micro:combine builds/orbit.phar -O orbit`.
   Combine is seconds; only the cached compile is expensive.
4. **CI matrix:** `macos-14` → macOS arm64; `ubuntu-latest` → Linux x86_64 (musl).

### Runtime facts and gotchas
- **`proc_open` is available and unrestricted.** PHPacker's stock runtime
  (`php-bin` 0.4.0, macOS arm64, PHP 8.5 `micro` SAPI) leaves `disable_functions`
  empty and runs `proc_open` spawns successfully. `Symfony\Process` underpins all of
  Orbit's shellouts (see below). `php-bin` 0.4.0 ships PHP **8.5.0RC5** — pin the GA
  build before release.
- **`PHP_BINARY` re-invocation breaks in the binary.**
  `app/Services/Node/NodeGatewayBootstrapper.php` assumes host PHP on a source
  checkout; in the binary that is invalid. Fix in the code-adaptation section below.
  This failure mode is caught only by the real binary smoke gate — a `php
  orbit.phar` shift-left check resolves `PHP_BINARY` to host PHP and passes.
- Inside the binary, `__DIR__` resolves into the `phar://` stream; the launcher's
  `vendor/autoload.php` check and the `NativeCommandNormalizer` require must resolve
  phar-relative (Box handles this; assert it in the binary smoke gate).
- macOS binaries link system libs (not fully static); Linux is musl-static.

### What uses `proc_open` (all via `Symfony\Process`; no raw `shell_exec`/`exec`)
- **`update`** (`LocalCheckoutUpdater`): `git pull --ff-only`; `docker exec
  orbit-runtime composer … install`; `docker exec orbit-runtime php
  apps/gateway/artisan migrate --force`.
- **CA trust** (`MacOsTrustStoreInstaller` / `LinuxTrustStoreInstaller`): macOS
  `security` keychain add; Linux ca-trust command.
- **WireGuard** (`WireGuardGatewayAddressResolver`): `wg` query for the gateway peer
  address.
- **Local DNS** (`Dns/LocalResolver`): `which dnsmasq`, `brew --prefix`, `brew
  services restart dnsmasq`, `/etc/resolver/<tld>` checks + `sudo rm`.
- **Node bootstrap** (`NodeGatewayBootstrapper`): gateway-artisan invocation.

## Code adaptations for `…-02`

A grep of `apps/cli/app` for Laravel path helpers (`base_path(`, `storage_path(`)
finds exactly three sites that assume a filesystem app root; inside the binary each
resolves into the `phar://` stream. Re-run this grep in `…-02` to confirm no new
write sites have appeared.

### `NodeGatewayBootstrapper` — route gateway artisan through `orbit-runtime`
Fires only for `node:new --template=gateway` when no gateway is configured yet
(`NodeNewCommand.php:82`) — the bootstrap chicken-and-egg, so there is no gateway API
to call and the artisan must run locally. Three assumptions break in the binary:

| Site | Source model | In the binary |
| --- | --- | --- |
| `PHP_BINARY` | host `php` interpreter | the orbit binary itself — re-runs orbit with a stray argv |
| `dirname(base_path(), 2)` | monorepo root on disk | resolves into the `phar://` stream — not a host path |
| `is_file($artisan)` | stats the host checkout | no host artisan file to stat |

Fix: do what `LocalCheckoutUpdater` already does — run gateway artisan inside
`orbit-runtime` via `docker exec` (honors the gateway-unchanged contract; gateway PHP
lives in the runtime container). Rewritten method:

```php
<?php

declare(strict_types=1);

namespace App\Services\Node;

use Illuminate\Support\Facades\Process;

class NodeGatewayBootstrapper
{
    /**
     * @param  list<string>  $arguments
     * @return array{exit_code: int, output: string}
     */
    public function run(array $arguments): array
    {
        if (! $this->gatewayRuntimeAvailable()) {
            return [
                'exit_code' => 1,
                'output' => json_encode([
                    'error' => [
                        'code' => 'gateway_bootstrap_unavailable',
                        'message' => 'Gateway artisan entry point is not available.',
                        'meta' => ['container' => 'orbit-runtime'],
                    ],
                ], JSON_THROW_ON_ERROR),
            ];
        }

        $result = Process::forever()->run(
            ['docker', 'exec', 'orbit-runtime', 'php', 'apps/gateway/artisan', ...$arguments],
        );

        $output = trim($result->output());

        if ($output === '') {
            $output = trim($result->errorOutput());
        }

        return [
            'exit_code' => $result->exitCode() ?? 1,
            'output' => $output,
        ];
    }

    private function gatewayRuntimeAvailable(): bool
    {
        return Process::run(
            ['docker', 'exec', 'orbit-runtime', 'test', '-f', 'apps/gateway/artisan'],
        )->successful();
    }
}
```

- Drops `PHP_BINARY`, `base_path()`/`repositoryRoot()`/`gatewayArtisanPath()`, and the
  host `is_file()` check; the container-side `test -f` precheck preserves the
  `gateway_bootstrap_unavailable` envelope.
- Relative `apps/gateway/artisan` resolves against the container workdir (mounted
  monorepo root) — the same assumption `LocalCheckoutUpdater::runMigrations()` relies
  on; no host cwd needed.
- Switches `Symfony\…\Process` → the Laravel `Process` facade so the test uses
  `Process::fake()` to assert the `docker exec … php apps/gateway/artisan …` command
  shape with no binary. Update `tests/Feature/Commands/Node/NodeWriteCommandTest.php`.
- Land this with the test update + gateway-bootstrap E2E. It changes behavior
  (host-PHP-on-source → container exec) and could surprise dev/test setups where
  `orbit-runtime` is not running during `node:new`.

### `Dns/LocalResolver` — move resolver state off `storage_path()`
`configDir()` (L49) returns `storage_path('app/orbit/dnsmasq.d')`; `resolve()` then
`ensureDirectoryExists` + `File::put` there (L126-132). In the binary that write
target resolves inside the package. Root resolver state at a host-writable Orbit
state path (e.g. under `~/.config/orbit/`), not `storage_path()`, and add a binary
smoke covering a local-DNS resolve. macOS-only path, but the macOS binary hits it.

### `Updates/CheckoutPathResolver` — checkout path, not from the binary
`base_path()` (L11) to locate the source checkout is invalid from inside the binary.
Fixed as part of the binary update/versioning follow-up (the `update` flow is already
deferred — see Decisions).

## Binary test strategy

The same PHP runs whether interpreted from a checkout or bundled in the binary, so
host PHP stays a developer/CI tool; only the end-user runtime/prerequisite is
removed. Functional behavior is proven against source; the binary is tested only for
packaging-specific risk (extension presence, `phar://` paths, `proc_open`,
SAPI/`php.ini`) — the standard test-pyramid + build-verification pattern for compiled
CLIs: full logic in the fast layer, a thin smoke gate on the artifact. The ladder,
cheap → expensive:

1. **Source unit/feature Pest** — every change, host PHP. Unchanged.
2. **Source E2E, checkout overlay in `apps/e2e`** — every change/PR. Unchanged; the
   normal development loop.
3. **Shift-left binary checks (no binary):** run the Pest suite under a PHP CLI
   carrying exactly the prod extension set (catches missing-extension bugs), and run
   `php builds/orbit.phar <cmd>` under normal PHP (catches `phar://`
   path/autoload/`NativeCommandNormalizer` bugs). Catches nearly all binary-specific
   failure classes without bundling.
4. **Binary build + acceptance — a dedicated `apps/e2e` lane, gated behind source
   E2E, never the per-PR feature loop.** The build itself is a tested step: a failed
   build (bad `box.json`, phar packaging, missing extension) fails the lane. Two
   tiers:
   - **4a — build + binary self-test (no topology; cheap, per-merge):** build the
     artifact (PHPacker → seconds; or cached `spc`), then run it with no real nodes —
     `--version`, extension-presence assertions, a `pdo_sqlite` command against a
     temp DB, a `phar://` path/autoload check, a `proc_open` echo. Catches build
     failures + most binary-only runtime breakage.
   - **4b — acceptance on a prepared topology (merge/nightly/tag):** drop the built
     binary onto retained `apps/e2e` topology roles and exercise the
     binary-only-reachable paths against real nodes — `NodeGatewayBootstrapper`
     docker-exec, `wg`, gateway HTTPS (curl+openssl), node bootstrap end-to-end. The
     retained topology is reused across runs, so neither it nor (with PHPacker) the
     binary is a per-change rebuild.

   This is the layered-E2E binary candidate lane made concrete; it runs only after
   source-checkout E2E passes. Cadence is tunable — default: 4a on merge-to-main, 4b
   nightly + release tags.

## Verification

- `composer docs-lint` passes.
- Grep sweep: no residual "host PHP … for the CLI/local-executor artifact", "runs
  from source", or "There is no PHAR" outside the gateway-runtime context; confirm
  app/workspace "no host PHP fallback" lines are still present.
- Grep sweep `apps/cli/orbit`: zero residual in Tier 1 files (each becomes "installed
  Orbit CLI binary" / "host `orbit` entrypoint"). Tier 2 testing/porting references
  (`testing/README.md`, `porting/testing-infrastructure.md`) remain until the
  E2E-harness impl — confirm only those remain.
- Grep sweep for the old five-extension list (`pdo_sqlite, openssl, curl, mbstring,
  and json`): it appears at `tech-stack.md:66` and `:358` and must be replaced by the
  widened set in both.
- Re-grep anchor phrases at execution time rather than trusting the cited line
  numbers.

## Follow-on todos

- `ORBIT-CLI-BINARY-01` (this plan): align docs for the self-contained CLI binary.
  Blocks `…-02`.
- `ORBIT-CLI-BINARY-02`: build the CLI binary (PHPacker primary) per Build mechanism.
  Adds `apps/cli/box.json` + a one-machine PHPacker build (static-php-cli fallback
  only if a custom extension set is needed), the `NodeGatewayBootstrapper` and
  `Dns/LocalResolver` code adaptations, and the dedicated `apps/e2e` binary
  build+acceptance lane (self-test tier + topology acceptance tier, gated behind
  source E2E). Satisfies #537 (gateway `orbit` → CLI app).
- Binary update/versioning contract for the `update` family: replace
  `LocalCheckoutUpdater`'s `git pull` with download-and-relink and fix
  `CheckoutPathResolver`'s `base_path()` checkout lookup.
