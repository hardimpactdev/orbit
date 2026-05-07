# Gateway Caddy Runtime Cert Fix Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make gateway runtime Caddy bootstrap additive and idempotent, while ensuring Caddy can read Orbit-managed TLS keys without weakening CA-issued private key defaults.

**Architecture:** Keep `OrbitCaService::issueLeaf()` as a runtime-private issuer that writes local keys as `0600`. Move Caddy layout ownership into a small helper that preserves existing global Caddy config, ensures shared snippets and both include trees, and writes the gateway API as an internal Orbit include under `/etc/caddy/orbit/orbit-api.caddy`. Consumers that install leaf keys for Caddy must explicitly set Caddy-readable destination permissions.

**Tech Stack:** Laravel 13, PHP 8.5, Pest 4, Laravel Process fake, Caddy config files, Linux filesystem permissions.

---

## Implementation Contract

The global `/etc/caddy/Caddyfile` imports both managed include trees:

- `/etc/caddy/orbit/*.caddy` for Orbit platform surfaces internal to the Orbit/WireGuard network.
- `/etc/caddy/sites/*.caddy` for public app, workspace, and custom proxy site routes.

The gateway API belongs in `/etc/caddy/orbit/orbit-api.caddy` and must match the gateway WireGuard address, for example `https://10.6.0.2:443`. It must not create a broad public virtual host.

The helper must not blindly append Caddy global options to existing files. Global options are valid only at the top of a Caddyfile. For existing files, preserve the existing global block when present and append only missing snippets/imports.

## File Map

- Create: `app/Services/Gateway/CaddyGlobalConfig.php` - owns global Caddyfile snippets and imports.
- Modify: `app/Services/Gateway/GatewayApiRuntimeInstaller.php` - writes gateway API include, preserves global Caddyfile.
- Modify: `app/Services/Proxy/ProxyRouteFixer.php` - installs repaired TLS keys as Caddy-readable.
- Modify: `tests/Feature/Services/Gateway/GatewayApiRuntimeInstallerTest.php` - covers split Caddy output and additive global handling.
- Modify: `tests/Unit/Services/Proxy/ProxyRouteFixerTest.php` - covers Caddy-readable repaired key permissions.
- Modify: `tests/Feature/Services/Ca/OrbitCaServiceTest.php` - clarifies `issueLeaf()` key remains runtime-private.

## Phase 1: Failing Tests

### Task 1: Gateway Installer Writes Internal Orbit Include Additively

**Files:**
- Modify: `tests/Feature/Services/Gateway/GatewayApiRuntimeInstallerTest.php`

- [ ] Replace the current single `$writtenCaddyfile` capture with captures for:
  - `/etc/caddy/Caddyfile`
  - `/etc/caddy/orbit/orbit-api.caddy`
  - `/etc/php/8.5/fpm/pool.d/orbit-api.conf`

- [ ] Update the existing installer test to assert:
  - global Caddyfile includes `(security_headers)`, `(profiling_headers)`, `(path_blocking_public_root)`, `(path_blocking_project_root)`, `(security_txt)`, `(cache_headers)`;
  - global Caddyfile includes `import /etc/caddy/orbit/*.caddy`;
  - global Caddyfile includes `import /etc/caddy/sites/*.caddy`;
  - gateway API block is written to `/etc/caddy/orbit/orbit-api.caddy`;
  - gateway API block contains `https://10.6.0.2:443`;
  - gateway API block does not contain `bind 10.6.0.2`;
  - gateway API block points at the issued leaf cert and key;
  - PHP-FPM pool still uses the `orbit-api` socket and `listen.group = caddy`.

- [ ] Add a regression test for an existing populated Caddyfile. Fake this read command:

```php
$readExistingCaddyfileCommand = 'sudo test -f /etc/caddy/Caddyfile && sudo cat /etc/caddy/Caddyfile || true';
```

Return:

```caddy
{
    admin off
}

import /etc/caddy/sites/*.caddy
import /etc/caddy/orbit/orbit-web.caddy
import /etc/caddy/orbit/tld-proxies.caddy
```

Assert the rewritten global Caddyfile:
  - keeps `admin off`;
  - keeps `import /etc/caddy/sites/*.caddy`;
  - keeps the specific existing Orbit imports;
  - adds `import /etc/caddy/orbit/*.caddy`;
  - does not duplicate `import /etc/caddy/sites/*.caddy`;
  - does not duplicate `import /etc/caddy/orbit/*.caddy`;
  - does not add a second global options block.

- [ ] Run:

```bash
php artisan test --compact tests/Feature/Services/Gateway/GatewayApiRuntimeInstallerTest.php
```

Expected: FAIL because current code overwrites `/etc/caddy/Caddyfile` with the API block and does not write `/etc/caddy/orbit/orbit-api.caddy`.

### Task 2: Proxy TLS Repair Installs Caddy-Readable Keys

**Files:**
- Modify: `tests/Unit/Services/Proxy/ProxyRouteFixerTest.php`

- [ ] Extend the TLS repair test to assert:

```php
->and($shell->scripts[0])->toContain('sudo chgrp caddy /etc/orbit/certs/vite.docs.test.key')
->and($shell->scripts[0])->toContain('sudo chmod 0640 /etc/orbit/certs/vite.docs.test.key')
->and($shell->scripts[0])->toContain('else')
->and($shell->scripts[0])->toContain('sudo chmod 0600 /etc/orbit/certs/vite.docs.test.key')
```

- [ ] Run:

```bash
php artisan test --compact tests/Unit/Services/Proxy/ProxyRouteFixerTest.php
```

Expected: FAIL because current code only writes `sudo chmod 0600`.

### Task 3: CA Leaf Contract Remains Runtime-Private

**Files:**
- Modify: `tests/Feature/Services/Ca/OrbitCaServiceTest.php`

- [ ] Rename the DNS leaf test to:

```php
it('issues a runtime-private leaf cert for a DNS host and returns correct paths', function () {
```

- [ ] Keep the key permission assertion:

```php
expect(decoct(fileperms($paths['key']) & 0777))->toBe('600');
```

- [ ] Run:

```bash
php artisan test --compact tests/Feature/Services/Ca/OrbitCaServiceTest.php
```

Expected: PASS.

## Phase 2: Add Caddy Global Config Helper

### Task 4: Create `CaddyGlobalConfig`

**Files:**
- Create: `app/Services/Gateway/CaddyGlobalConfig.php`

- [ ] Create a final readonly helper with:
  - `fresh(): string`
  - `ensure(string $contents): string`
  - private `globalOptions(): string`
  - private `ensureSnippets(string $contents): string`
  - private `ensureImports(string $contents): string`
  - private `snippetBlocks(): array`

- [ ] `fresh()` must return global options, all snippets, and both imports:

```caddy
{
    local_certs
    admin off
}

(security_headers) {
    header {
        X-Content-Type-Options "nosniff"
        X-XSS-Protection "1; mode=block"
        Referrer-Policy "strict-origin-when-cross-origin"
        Permissions-Policy "camera=(), microphone=(), geolocation=()"
        -Server
    }
}

...

import /etc/caddy/orbit/*.caddy
import /etc/caddy/sites/*.caddy
```

- [ ] `ensure()` must preserve existing content and append only missing snippets/imports. It must not prepend or append a global options block to non-empty existing content.

## Phase 3: Implement Gateway Runtime Additive Caddy Writes

### Task 5: Update `GatewayApiRuntimeInstaller`

**Files:**
- Modify: `app/Services/Gateway/GatewayApiRuntimeInstaller.php`

- [ ] Inject `CaddyGlobalConfig`.

- [ ] Replace the Caddy write block with:
  - `sudo install -d -m 0755 /etc/caddy /etc/caddy/orbit /etc/caddy/sites`
  - read optional `/etc/caddy/Caddyfile`
  - write global Caddyfile only when helper output differs from current contents
  - write gateway API block to `/etc/caddy/orbit/orbit-api.caddy`

- [ ] Rename `caddyfile()` to `gatewayApiCaddyfile()`.

- [ ] Add:

```php
private function ensureGlobalCaddyfile(): void
```

and:

```php
private function readOptional(string $path): string
```

using:

```sh
sudo test -f /etc/caddy/Caddyfile && sudo cat /etc/caddy/Caddyfile || true
```

- [ ] Run:

```bash
php artisan test --compact tests/Feature/Services/Gateway/GatewayApiRuntimeInstallerTest.php
```

Expected: PASS.

## Phase 4: Fix Proxy TLS Key Installation

### Task 6: Update Proxy TLS Repair Permissions

**Files:**
- Modify: `app/Services/Proxy/ProxyRouteFixer.php`

- [ ] In `tlsInstallScript()`, keep cert mode `0644`.

- [ ] Replace unconditional key `0600` with:

```sh
if getent group caddy >/dev/null 2>&1; then
    sudo chgrp caddy %s
    sudo chmod 0640 %s
else
    sudo chmod 0600 %s
fi
```

- [ ] Run:

```bash
php artisan test --compact tests/Unit/Services/Proxy/ProxyRouteFixerTest.php
```

Expected: PASS.

## Phase 5: Verification

- [ ] Run focused tests:

```bash
php artisan test --compact tests/Feature/Services/Gateway/GatewayApiRuntimeInstallerTest.php tests/Feature/Services/Ca/OrbitCaServiceTest.php tests/Unit/Services/Proxy/ProxyRouteFixerTest.php
```

- [ ] Run formatting:

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] Run the broad gate:

```bash
composer quality-check
```

## Live Verification Notes

Before mutating a real populated gateway, inspect:

```bash
ssh gateway 'sed -n "1,220p" /etc/caddy/Caddyfile; find /etc/caddy -maxdepth 2 -type f -name "*.caddy" -print; id caddy'
```

After deployment, validate before restart/reload where possible:

```bash
ssh gateway 'sudo caddy validate --config /etc/caddy/Caddyfile'
```

Verify Caddy can read the gateway API key:

```bash
ssh gateway 'sudo -n -u caddy test -r /home/orbit/orbit/storage/app/orbit/certs/10.6.0.2.key && echo readable'
```
