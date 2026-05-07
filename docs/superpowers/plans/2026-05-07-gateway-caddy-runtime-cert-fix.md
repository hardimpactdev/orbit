# Gateway Caddy Runtime Cert Fix Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make gateway runtime bootstrap additive and idempotent, while ensuring Orbit-managed TLS keys used by Caddy are readable by Caddy without weakening root CA key permissions.

**Architecture:** Introduce a focused Caddy layout helper used by `GatewayApiRuntimeInstaller` to maintain the global Caddyfile contract and write the gateway API as its own include file. Keep `OrbitCaService::issueLeaf()` as a private runtime issuer with `0600` local keys; each consumer that installs keys for Caddy must explicitly set Caddy-readable ownership/mode at the destination.

**Tech Stack:** Laravel 13, PHP 8.5, Pest 4, Laravel Process fake, Caddy config files, Linux filesystem permissions.

---

## Complexity

Files: 5-7 | Modules: Gateway runtime, CA consumers, tests | Risk: Medium

Primary risk is remote host mutation. Unit/feature tests should prove script content and idempotent file-write behavior before any live-node verification.

## File Map

- Create: `app/Services/Gateway/CaddyGlobalConfig.php` - returns the canonical shared snippets plus import line.
- Modify: `app/Services/Gateway/GatewayApiRuntimeInstaller.php` - stop overwriting populated `/etc/caddy/Caddyfile`; write `/etc/caddy/orbit/orbit-api.caddy`; ensure global Caddyfile exists/imports the include path.
- Modify: `app/Services/Proxy/ProxyRouteFixer.php` - install proxy TLS keys with Caddy-readable ownership/mode.
- Modify: `tests/Feature/Services/Gateway/GatewayApiRuntimeInstallerTest.php` - assert additive Caddy behavior and no direct destructive write.
- Modify: `tests/Unit/Services/Proxy/ProxyRouteFixerTest.php` - assert repaired TLS key permissions are readable by Caddy.
- Modify: `tests/Feature/Services/Ca/OrbitCaServiceTest.php` - document `issueLeaf()` local key contract remains `0600`.

## Phase 1: Lock Current Bugs With Failing Tests

### Task 1: Gateway Installer Must Be Additive

**Files:**
- Modify: `tests/Feature/Services/Gateway/GatewayApiRuntimeInstallerTest.php`

- [ ] **Step 1: Replace the Caddyfile capture with command-specific captures**

In the existing gateway installer test, change the fake callback to capture:

```php
$writtenGlobalCaddyfile = null;
$writtenGatewayApiCaddyfile = null;
$writtenFpmPool = null;

Process::fake(function ($process) use (&$writtenGlobalCaddyfile, &$writtenGatewayApiCaddyfile, &$writtenFpmPool) {
    if (str_contains($process->command, 'tee /etc/php/8.5/fpm/pool.d/orbit-api.conf')) {
        $writtenFpmPool = (string) $process->input;
    }

    if (str_contains($process->command, 'tee /etc/caddy/Caddyfile')) {
        $writtenGlobalCaddyfile = (string) $process->input;
    }

    if (str_contains($process->command, 'tee /etc/caddy/orbit/orbit-api.caddy')) {
        $writtenGatewayApiCaddyfile = (string) $process->input;
    }

    return Process::result();
});
```

- [ ] **Step 2: Update expectations for split Caddy config**

Replace `$writtenCaddyfile` assertions with:

```php
expect($writtenGlobalCaddyfile)->toContain('(security_headers)')
    ->and($writtenGlobalCaddyfile)->toContain('(profiling_headers)')
    ->and($writtenGlobalCaddyfile)->toContain('(path_blocking_public_root)')
    ->and($writtenGlobalCaddyfile)->toContain('(path_blocking_project_root)')
    ->and($writtenGlobalCaddyfile)->toContain('(security_txt)')
    ->and($writtenGlobalCaddyfile)->toContain('(cache_headers)')
    ->and($writtenGlobalCaddyfile)->toContain('import /etc/caddy/orbit/*.caddy')
    ->and($writtenGatewayApiCaddyfile)->toContain('https://10.6.0.2')
    ->and($writtenGatewayApiCaddyfile)->not->toContain('bind 10.6.0.2')
    ->and($writtenGatewayApiCaddyfile)->toContain('tls '.$this->tempStorage.'/app/orbit/certs/10.6.0.2.crt '.$this->tempStorage.'/app/orbit/certs/10.6.0.2.key')
    ->and($writtenGatewayApiCaddyfile)->toContain('root * /home/orbit/orbit/public')
    ->and($writtenGatewayApiCaddyfile)->toContain('php_fastcgi unix//run/php/orbit-api.sock')
    ->and($writtenFpmPool)->toContain('[orbit-api]')
    ->and($writtenFpmPool)->toContain('user = orbit')
    ->and($writtenFpmPool)->toContain('listen.group = caddy')
    ->and($writtenFpmPool)->toContain('chdir = /home/orbit/orbit');
```

- [ ] **Step 3: Update process assertions**

Expect the new directory and site-file writes:

```php
Process::assertRan('sudo install -d -m 0755 /etc/caddy /etc/caddy/orbit');
Process::assertRan('sudo tee /etc/caddy/orbit/orbit-api.caddy > /dev/null');
```

Keep the global Caddyfile assertion initially:

```php
Process::assertRan('sudo tee /etc/caddy/Caddyfile > /dev/null');
```

- [ ] **Step 4: Add a regression test for existing populated Caddyfile**

Add this test below the existing installer test:

```php
it('preserves an existing global Caddyfile and only ensures the orbit import', function (): void {
    $readExistingCaddyfileCommand = 'sudo test -f /etc/caddy/Caddyfile && sudo cat /etc/caddy/Caddyfile || true';
    $writtenGlobalCaddyfile = null;
    $writtenGatewayApiCaddyfile = null;

    $caDir = storage_path('app/orbit/ca');
    $certsDir = storage_path('app/orbit/certs');

    File::ensureDirectoryExists($caDir);
    File::ensureDirectoryExists($certsDir);
    File::put("{$caDir}/root.key", 'test-root-key');
    File::put("{$caDir}/root.crt", "-----BEGIN CERTIFICATE-----\ntest-root-cert\n-----END CERTIFICATE-----\n");
    File::put("{$certsDir}/10.6.0.2.crt", "-----BEGIN CERTIFICATE-----\ntest-leaf-cert\n-----END CERTIFICATE-----\n");
    File::put("{$certsDir}/10.6.0.2.key", 'test-leaf-key');

    Process::fake(function ($process) use ($readExistingCaddyfileCommand, &$writtenGlobalCaddyfile, &$writtenGatewayApiCaddyfile) {
        if ($process->command === $readExistingCaddyfileCommand) {
            return Process::result(<<<'CADDY'
{
    admin off
}

import /etc/caddy/sites/*.caddy
import /etc/caddy/orbit/orbit-web.caddy
import /etc/caddy/orbit/tld-proxies.caddy
CADDY);
        }

        if (str_contains($process->command, 'tee /etc/caddy/Caddyfile')) {
            $writtenGlobalCaddyfile = (string) $process->input;
        }

        if (str_contains($process->command, 'tee /etc/caddy/orbit/orbit-api.caddy')) {
            $writtenGatewayApiCaddyfile = (string) $process->input;
        }

        return Process::result();
    });
    Process::preventStrayProcesses();

    app(GatewayApiRuntimeInstaller::class)->install('10.6.0.2', orbitPath: '/home/orbit/orbit');

    expect($writtenGlobalCaddyfile)->toContain('import /etc/caddy/sites/*.caddy')
        ->and($writtenGlobalCaddyfile)->toContain('import /etc/caddy/orbit/orbit-web.caddy')
        ->and($writtenGlobalCaddyfile)->toContain('import /etc/caddy/orbit/tld-proxies.caddy')
        ->and($writtenGlobalCaddyfile)->toContain('import /etc/caddy/orbit/*.caddy')
        ->and(substr_count($writtenGlobalCaddyfile, 'import /etc/caddy/orbit/*.caddy'))->toBe(1)
        ->and($writtenGatewayApiCaddyfile)->toContain('https://10.6.0.2:443');
});
```

- [ ] **Step 5: Run the gateway installer test and confirm it fails**

Run:

```bash
php artisan test --compact tests/Feature/Services/Gateway/GatewayApiRuntimeInstallerTest.php
```

Expected: FAIL because current implementation writes only `/etc/caddy/Caddyfile` and never writes `/etc/caddy/orbit/orbit-api.caddy`.

### Task 2: Proxy TLS Repair Must Install Caddy-Readable Keys

**Files:**
- Modify: `tests/Unit/Services/Proxy/ProxyRouteFixerTest.php`

- [ ] **Step 1: Extend TLS repair assertions**

In `it('repairs missing Orbit-managed TLS material for custom proxy routes'...)`, extend the chain:

```php
->and($shell->scripts[0])->toContain('sudo chgrp caddy /etc/orbit/certs/vite.docs.test.key')
->and($shell->scripts[0])->toContain('sudo chmod 0640 /etc/orbit/certs/vite.docs.test.key')
->and($shell->scripts[0])->not->toContain('sudo chmod 0600 /etc/orbit/certs/vite.docs.test.key');
```

- [ ] **Step 2: Run the proxy fixer test and confirm it fails**

Run:

```bash
php artisan test --compact tests/Unit/Services/Proxy/ProxyRouteFixerTest.php
```

Expected: FAIL because current script does `sudo chmod 0600`.

### Task 3: Keep CA Service Contract Narrow

**Files:**
- Modify: `tests/Feature/Services/Ca/OrbitCaServiceTest.php`

- [ ] **Step 1: Rename the permission expectation to clarify the contract**

Rename the test:

```php
it('issues a runtime-private leaf cert for a DNS host and returns correct paths', function () {
```

Keep:

```php
expect(decoct(fileperms($paths['key']) & 0777))->toBe('600');
```

- [ ] **Step 2: Run the CA test**

Run:

```bash
php artisan test --compact tests/Feature/Services/Ca/OrbitCaServiceTest.php
```

Expected: PASS. This proves the fix should live in Caddy consumers, not by globally weakening generated private keys.

## Phase 2: Implement Additive Gateway Caddy Bootstrap

### Task 4: Add Shared Caddy Global Config Helper

**Files:**
- Create: `app/Services/Gateway/CaddyGlobalConfig.php`

- [ ] **Step 1: Create the helper**

Create:

```php
<?php

declare(strict_types=1);

namespace App\Services\Gateway;

final readonly class CaddyGlobalConfig
{
    public function fresh(string $importPattern = '/etc/caddy/orbit/*.caddy'): string
    {
        return rtrim($this->snippets())."\n\nimport {$importPattern}\n";
    }

    public function ensureImport(string $contents, string $importPattern = '/etc/caddy/orbit/*.caddy'): string
    {
        $line = "import {$importPattern}";

        if (str_contains($contents, $line)) {
            return $this->ensureSnippets($contents);
        }

        return rtrim($this->ensureSnippets($contents))."\n\n{$line}\n";
    }

    private function ensureSnippets(string $contents): string
    {
        $updated = rtrim($contents);

        foreach ($this->snippetBlocks() as $name => $block) {
            if (str_contains($updated, "({$name})")) {
                continue;
            }

            $updated .= "\n\n{$block}";
        }

        return $updated."\n";
    }

    private function snippets(): string
    {
        return implode("\n\n", $this->snippetBlocks())."\n";
    }

    /**
     * @return array<string, string>
     */
    private function snippetBlocks(): array
    {
        return [
            'security_headers' => <<<'CADDY'
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
CADDY,
            'path_blocking_public_root' => <<<'CADDY'
(path_blocking_public_root) {
    @blocked path /.env /.env.* /.git/* /artisan
    respond @blocked 404
}
CADDY,
            'path_blocking_project_root' => <<<'CADDY'
(path_blocking_project_root) {
    @blocked path /.env /.env.* /.git/* /vendor/* /storage/* /config/* /database/* /node_modules/* /artisan
    respond @blocked 404
}
CADDY,
            'security_txt' => <<<'CADDY'
(security_txt) {
}
CADDY,
            'profiling_headers' => <<<'CADDY'
(profiling_headers) {
    request_header X-Caddy-Start "{time.now.unix_ms}"
    header {
        X-Caddy-End "{time.now.unix_ms}"
        defer
    }
}
CADDY,
            'cache_headers' => <<<'CADDY'
(cache_headers) {
    @static {
        path /build/*
    }
    header @static Cache-Control "public, max-age=31536000, immutable"
}
CADDY,
        ];
    }
}
```

- [ ] **Step 2: Run Pint on the new helper**

Run:

```bash
vendor/bin/pint --dirty --format agent
```

Expected: PASS or style changes applied.

### Task 5: Update Gateway Runtime Installer

**Files:**
- Modify: `app/Services/Gateway/GatewayApiRuntimeInstaller.php`

- [ ] **Step 1: Inject the helper**

Change constructor:

```php
public function __construct(
    private readonly OrbitCaService $caService,
    private readonly CaddyGlobalConfig $caddyGlobalConfig,
) {}
```

- [ ] **Step 2: Replace Caddy install block**

Replace:

```php
$this->runRequired('sudo install -d -m 0755 /etc/caddy', 'prepare Caddy config directory');
$this->runRequiredWithInput('sudo tee /etc/caddy/Caddyfile > /dev/null', $this->caddyfile(...), 'write Caddy config');
```

with:

```php
$this->runRequired('sudo install -d -m 0755 /etc/caddy /etc/caddy/orbit', 'prepare Caddy config directories');
$this->ensureGlobalCaddyfileImportsOrbit();
$this->runRequiredWithInput('sudo tee /etc/caddy/orbit/orbit-api.caddy > /dev/null', $this->gatewayApiCaddyfile(
    wireguardAddress: $wireguardAddress,
    orbitPath: $orbitPath,
    certPath: $leaf['cert'],
    keyPath: $leaf['key'],
), 'write Orbit API Caddy config');
```

- [ ] **Step 3: Rename `caddyfile()`**

Rename `private function caddyfile(...)` to:

```php
private function gatewayApiCaddyfile(
    string $wireguardAddress,
    string $orbitPath,
    string $certPath,
    string $keyPath,
): string {
```

Keep the block body unchanged.

- [ ] **Step 4: Add global Caddyfile methods**

Add:

```php
private function ensureGlobalCaddyfileImportsOrbit(): void
{
    $contents = $this->readOptional('/etc/caddy/Caddyfile');

    $updated = $contents === ''
        ? $this->caddyGlobalConfig->fresh()
        : $this->caddyGlobalConfig->ensureImport($contents);

    if ($updated === $contents) {
        return;
    }

    $this->runRequiredWithInput('sudo tee /etc/caddy/Caddyfile > /dev/null', $updated, 'write global Caddy config');
}

private function readOptional(string $path): string
{
    $command = 'sudo test -f '.escapeshellarg($path).' && sudo cat '.escapeshellarg($path).' || true';
    $result = Process::timeout(30)->run($command);

    if ($result->successful()) {
        return $result->output();
    }

    throw new RuntimeException("Failed to read {$path}: ".$this->output($result->errorOutput(), $result->output()));
}
```

- [ ] **Step 5: Run the gateway installer test**

Run:

```bash
php artisan test --compact tests/Feature/Services/Gateway/GatewayApiRuntimeInstallerTest.php
```

Expected: PASS.

## Phase 3: Fix Proxy TLS Key Installation

### Task 6: Make ProxyRouteFixer Destination Key Caddy-Readable

**Files:**
- Modify: `app/Services/Proxy/ProxyRouteFixer.php`

- [ ] **Step 1: Change key permissions in `tlsInstallScript()`**

Replace:

```sh
sudo chmod 0644 %s
sudo chmod 0600 %s
sudo systemctl reload caddy
```

with:

```sh
sudo chmod 0644 %s
if getent group caddy >/dev/null 2>&1; then
    sudo chgrp caddy %s
    sudo chmod 0640 %s
else
    sudo chmod 0600 %s
fi
sudo systemctl reload caddy
```

Update `sprintf()` arguments so the key path is passed three times after cert path:

```php
escapeshellarg($certPath),
escapeshellarg($keyPath),
escapeshellarg($keyPath),
escapeshellarg($keyPath),
```

- [ ] **Step 2: Run proxy fixer test**

Run:

```bash
php artisan test --compact tests/Unit/Services/Proxy/ProxyRouteFixerTest.php
```

Expected: PASS.

## Phase 4: Regression Coverage And Formatting

### Task 7: Run Focused Tests

**Files:**
- Test: `tests/Feature/Services/Gateway/GatewayApiRuntimeInstallerTest.php`
- Test: `tests/Feature/Services/Ca/OrbitCaServiceTest.php`
- Test: `tests/Unit/Services/Proxy/ProxyRouteFixerTest.php`

- [ ] **Step 1: Run focused tests**

Run:

```bash
php artisan test --compact tests/Feature/Services/Gateway/GatewayApiRuntimeInstallerTest.php tests/Feature/Services/Ca/OrbitCaServiceTest.php tests/Unit/Services/Proxy/ProxyRouteFixerTest.php
```

Expected: PASS.

- [ ] **Step 2: Run Pint**

Run:

```bash
vendor/bin/pint --dirty --format agent
```

Expected: PASS or style changes applied.

- [ ] **Step 3: Run broad quality check if focused tests pass**

Run:

```bash
composer quality-check
```

Expected: PASS.

## Phase 5: Read-Only Live Verification

### Task 8: Verify Gateway State Before Any Mutation

**Files:** None

- [ ] **Step 1: Inspect gateway Caddy layout**

Run:

```bash
ssh gateway 'sed -n "1,180p" /etc/caddy/Caddyfile; ls -la /etc/caddy/orbit; id caddy'
```

Expected:
- Caddyfile still contains existing non-API imports.
- Caddyfile contains or can safely gain `import /etc/caddy/orbit/*.caddy`.
- `caddy` exists.

- [ ] **Step 2: After deployment to gateway, verify config without restarting**

Run:

```bash
ssh gateway 'sudo caddy validate --config /etc/caddy/Caddyfile'
```

Expected: success.

- [ ] **Step 3: Verify Caddy can read gateway API key**

Run:

```bash
ssh gateway 'sudo -n -u caddy test -r /home/gateway/orbit/storage/app/orbit/certs/10.6.0.2.key && echo readable'
```

Expected: `readable`.

## Open Questions

1. Should the canonical include path be `/etc/caddy/orbit/*.caddy` for gateways and `/etc/caddy/sites/*.caddy` for app nodes, or should the gateway global Caddyfile import both for compatibility?
2. Should the gateway API Caddy block keep the current clean-rebuild socket `/run/php/orbit-api.sock`, or intentionally preserve the older `orbit-exec`/`orbit-stream` split for streaming endpoints?
3. Should proxy TLS destination certs live under `/etc/orbit/certs` as they do now, or move under `/etc/caddy/orbit/certs` to make Caddy ownership explicit?

