# Orbit CLI Auto-Provisioning Plan

> **Status**: Planned (not yet implemented)
> **Created**: 2026-01-12
> **Branch**: To be created when implementation starts

## Overview

Add a unified `launchpad setup` command to the CLI that auto-detects the platform (Mac/Linux) and provisions the full Orbit stack. The desktop app remains a "dumb UI shell" that simply invokes this CLI command and displays progress.

## Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│  Desktop App (orbit-desktop)                                │
│  - Invokes: launchpad setup --json                              │
│  - Parses JSON progress output                                  │
│  - Displays progress in UI                                      │
└───────────────────────┬─────────────────────────────────────────┘
                        │ Process::run() (local)
                        │ or SSH (remote)
                        ▼
┌─────────────────────────────────────────────────────────────────┐
│  Orbit CLI (~/projects/orbit-cli/)                      │
│                                                                 │
│  launchpad setup [--tld=test] [--json]                          │
│      │                                                          │
│      ├─► Detect platform (PHP_OS_FAMILY)                        │
│      │                                                          │
│      ├─► Darwin (Mac)                                           │
│      │   ├── Install Homebrew (if missing)                      │
│      │   ├── Install OrbStack (if missing)                      │
│      │   ├── Install PHP via shivammathur/php                   │
│      │   ├── Install Caddy via Homebrew                         │
│      │   ├── Configure PHP-FPM pools                            │
│      │   ├── Configure Caddy                                    │
│      │   ├── Configure DNS resolver (/etc/resolver/)            │
│      │   ├── Start services (brew services)                     │
│      │   ├── Start Docker containers                            │
│      │   └── Install Horizon (launchd)                          │
│      │                                                          │
│      └─► Linux                                                  │
│          ├── Install Docker (if missing)                        │
│          ├── Install PHP-FPM via apt                            │
│          ├── Install Caddy via apt                              │
│          ├── Configure PHP-FPM pools                            │
│          ├── Configure Caddy                                    │
│          ├── Start services (systemctl)                         │
│          ├── Start Docker containers                            │
│          └── Install Horizon (systemd)                          │
└─────────────────────────────────────────────────────────────────┘
```

---

## CLI Implementation (orbit-cli)

### Location

All CLI changes in: `ssh launchpad@ai:~/projects/orbit-cli/`

### New Command: `SetupCommand.php`

**File**: `app/Commands/SetupCommand.php`

```php
protected $signature = 'setup
    {--tld=test : TLD for local development sites}
    {--php-versions=8.4,8.5 : PHP versions to install (comma-separated)}
    {--skip-docker : Skip Docker/OrbStack installation}
    {--json : Output progress as JSON for programmatic consumption}';

protected $description = 'Set up Launchpad on this machine (auto-detects Mac/Linux)';
```

### Platform Detection

```php
public function handle(): int
{
    $platform = PHP_OS_FAMILY; // 'Darwin' or 'Linux'

    if ($platform === 'Darwin') {
        return $this->setupMac();
    } elseif ($platform === 'Linux') {
        return $this->setupLinux();
    } else {
        $this->error("Unsupported platform: {$platform}");
        return Command::FAILURE;
    }
}
```

### Mac Setup Steps (15 steps)

| Step | Name                   | Description                                             |
| ---- | ---------------------- | ------------------------------------------------------- |
| 1    | Detect system          | Verify macOS, check architecture (Apple Silicon)        |
| 2    | Check/Install Homebrew | Install if missing via official script                  |
| 3    | Check/Install OrbStack | Install via `brew install --cask orbstack`              |
| 4    | Add PHP tap            | `brew tap shivammathur/php`                             |
| 5    | Install PHP versions   | `brew install shivammathur/php/php@8.4` etc             |
| 6    | Install Caddy          | `brew install caddy`                                    |
| 7    | Install support tools  | Bun, Composer if missing                                |
| 8    | Create directories     | `~/.config/orbit/`, `~/projects/`                       |
| 9    | Configure PHP-FPM      | Generate pool configs, create symlinks                  |
| 10   | Configure Caddy        | Generate Caddyfile, setup system import                 |
| 11   | Configure DNS          | Smart detection, create `/etc/resolver/{tld}`           |
| 12   | Start PHP-FPM          | `brew services start php@{version}`                     |
| 13   | Start Caddy            | `brew services start caddy`                             |
| 14   | Init Docker services   | Create network, start postgres/redis/mailpit/reverb/dns |
| 15   | Install Horizon        | Generate launchd plist, load service                    |

### Linux Setup Steps (12 steps)

| Step | Name                  | Description                              |
| ---- | --------------------- | ---------------------------------------- |
| 1    | Detect system         | Verify Linux, check distro               |
| 2    | Check/Install Docker  | Install via official script if missing   |
| 3    | Add Ondřej PPA        | `add-apt-repository ppa:ondrej/php`      |
| 4    | Install PHP-FPM       | `apt install php8.x-fpm` with extensions |
| 5    | Install Caddy         | Add repo, `apt install caddy`            |
| 6    | Install support tools | Bun, Composer if missing                 |
| 7    | Create directories    | `~/.config/orbit/`, `~/projects/`        |
| 8    | Configure PHP-FPM     | Generate pool configs, symlink           |
| 9    | Configure Caddy       | Generate Caddyfile                       |
| 10   | Start PHP-FPM         | `systemctl start php8.x-fpm`             |
| 11   | Start Caddy           | `systemctl start caddy`                  |
| 12   | Install Horizon       | Generate systemd unit, enable service    |

### JSON Progress Output

When `--json` flag is used, output one JSON object per line:

```json
{"type":"step","step":1,"total":15,"name":"Detecting system","status":"running"}
{"type":"info","message":"Detected macOS 14.0 on Apple Silicon"}
{"type":"step","step":1,"total":15,"name":"Detecting system","status":"completed"}
{"type":"step","step":2,"total":15,"name":"Checking Homebrew","status":"running"}
{"type":"info","message":"Homebrew already installed at /opt/homebrew/bin/brew"}
{"type":"step","step":2,"total":15,"name":"Checking Homebrew","status":"completed"}
{"type":"step","step":3,"total":15,"name":"Checking OrbStack","status":"running"}
{"type":"info","message":"Installing OrbStack via Homebrew..."}
{"type":"step","step":3,"total":15,"name":"Checking OrbStack","status":"completed"}
...
{"type":"complete","success":true,"message":"Launchpad setup complete!"}
```

Error output:

```json
{"type":"step","step":5,"total":15,"name":"Installing PHP","status":"error","error":"Failed to install PHP 8.5: brew returned exit code 1"}
{"type":"complete","success":false,"error":"Setup failed at step 5"}
```

### Smart DNS Detection (Mac)

```php
protected function configureDnsMac(string $tld): bool
{
    $resolverFile = "/etc/resolver/{$tld}";

    // Check if already configured correctly
    if (file_exists($resolverFile)) {
        $content = file_get_contents($resolverFile);
        if (str_contains($content, 'nameserver 127.0.0.1')) {
            $this->progress('info', "DNS resolver for .{$tld} already configured");
            return true;
        }
    }

        $this->progress('info', 'Consider using --tld=lp to avoid conflicts');
        // Continue anyway - user can re-run with different TLD
    }

    // Create resolver (requires sudo)
    $this->progress('info', 'Creating DNS resolver (sudo required)...');

    $result = Process::run("sudo mkdir -p /etc/resolver && echo 'nameserver 127.0.0.1' | sudo tee {$resolverFile}");

    return $result->successful();
}

{
    ];

        if (is_dir($path)) return true;
    }

}
```

### OrbStack Installation (Mac)

```php
protected function checkOrInstallOrbStack(): bool
{
    // Check if OrbStack is running
    if (Process::run('orbctl status 2>/dev/null')->successful()) {
        $this->progress('info', 'OrbStack is running');
        return true;
    }

    // Check if Docker is available (OrbStack or Docker Desktop)
    if (Process::run('docker info 2>/dev/null')->successful()) {
        $this->progress('info', 'Docker runtime available');
        return true;
    }

    // Install OrbStack
    $this->progress('info', 'Installing OrbStack...');

    $result = Process::timeout(300)->run('brew install --cask orbstack');
    if (!$result->successful()) {
        $this->progress('error', 'Failed to install OrbStack');
        return false;
    }

    // Open OrbStack to complete setup
    Process::run('open -a OrbStack');
    $this->progress('info', 'OrbStack installed - waiting for initialization...');

    // Poll for readiness (up to 60 seconds)
    for ($i = 0; $i < 12; $i++) {
        sleep(5);
        if (Process::run('docker info 2>/dev/null')->successful()) {
            return true;
        }
    }

    $this->progress('error', 'OrbStack not ready after 60 seconds');
    return false;
}
```

### File Structure in CLI

```
app/Commands/
├── SetupCommand.php          # Main setup command (NEW)
├── Setup/
│   ├── MacSetup.php          # Mac-specific provisioning logic (NEW)
│   ├── LinuxSetup.php        # Linux-specific provisioning logic (NEW)
│   └── SetupProgress.php     # Progress output trait (NEW)
├── InitCommand.php           # Existing - may need refactoring
└── ...
```

---

## Desktop App Changes (orbit-desktop)

### Minimal Changes Required

The desktop app just needs to:

1. Invoke `launchpad setup --json`
2. Parse JSON lines from stdout
3. Update UI with progress

### Controller Update

**File**: `app/Http/Controllers/ProvisioningController.php`

```php
public function run(Request $request, Environment $environment)
{
    if ($environment->is_local) {
        // Local: run CLI directly
        $command = $this->findLaunchpadBinary() . ' setup --json --tld=' . escapeshellarg($environment->tld ?? 'test');

        // Spawn in background, capture output to file
        $outputFile = storage_path("logs/provision-{$environment->id}.log");
        $pidFile = storage_path("logs/provision-{$environment->id}.pid");

        $fullCommand = sprintf(
            'nohup %s > %s 2>&1 & echo $! > %s',
            $command,
            escapeshellarg($outputFile),
            escapeshellarg($pidFile)
        );

        Process::run($fullCommand);

        $environment->update([
            'status' => 'provisioning',
            'provisioning_step' => 0,
            'provisioning_total_steps' => 15,
        ]);

        return response()->json(['started' => true, 'output_file' => $outputFile]);
    }

    // Remote: existing SSH-based provisioning
    return $this->runRemoteProvisioning($request, $environment);
}

public function status(Environment $environment)
{
    if ($environment->is_local && $environment->status === 'provisioning') {
        // Parse progress from CLI output file
        $outputFile = storage_path("logs/provision-{$environment->id}.log");
        $progress = $this->parseCliProgress($outputFile);

        return response()->json([
            'status' => $progress['complete'] ? ($progress['success'] ? 'active' : 'error') : 'provisioning',
            'provisioning_step' => $progress['step'],
            'provisioning_total_steps' => $progress['total'],
            'provisioning_log' => $progress['log'],
            'provisioning_error' => $progress['error'],
        ]);
    }

    // Existing logic for remote
    return response()->json([...]);
}

protected function parseCliProgress(string $outputFile): array
{
    $log = [];
    $step = 0;
    $total = 15;
    $error = null;
    $complete = false;
    $success = false;

    if (file_exists($outputFile)) {
        $lines = file($outputFile, FILE_IGNORE_NEW_LINES);
        foreach ($lines as $line) {
            $data = json_decode($line, true);
            if (!$data) continue;

            if ($data['type'] === 'step') {
                $step = $data['step'];
                $total = $data['total'];
                $log[] = ['step' => $data['name']];
            } elseif ($data['type'] === 'info') {
                $log[] = ['info' => $data['message']];
            } elseif ($data['type'] === 'complete') {
                $complete = true;
                $success = $data['success'];
                if (!$success) $error = $data['error'] ?? 'Unknown error';
            }
        }
    }

    return compact('log', 'step', 'total', 'error', 'complete', 'success');
}
```

### Vue Component

Minimal changes to `Provisioning.vue` - the existing polling mechanism works, just need dynamic checklist:

```typescript
const localChecklistItems = [
    { step: 3, label: 'OrbStack (Docker runtime)' },
    { step: 5, label: 'PHP-FPM via Homebrew' },
    { step: 10, label: 'Caddy web server' },
    { step: 14, label: 'Docker services (PostgreSQL, Redis, etc.)' },
    { step: 15, label: 'Horizon queue worker' },
];

const checklistItems = computed(() =>
    props.server.is_local ? localChecklistItems : remoteChecklistItems,
);
```

---

## Implementation Order

### Phase 1: CLI Command (in orbit-cli)

1. Create `SetupCommand.php` with platform detection
2. Create `Setup/MacSetup.php` with all Mac provisioning steps
3. Create `Setup/LinuxSetup.php` (refactor from existing init logic)
4. Create `Setup/SetupProgress.php` trait for JSON output
5. Add `CaddyfileGenerator` to CLI (move/adapt from desktop)
6. Add PHP-FPM pool config generation to CLI
7. Add Horizon service management (launchd/systemd) to CLI
8. Test locally on Mac: `php launchpad setup --json`

### Phase 2: Desktop Integration

1. Update `ProvisioningController` to call CLI for local environments
2. Add progress file parsing
3. Update `Provisioning.vue` with dynamic checklist
4. Test end-to-end flow

### Phase 3: Desktop Cleanup

Remove redundant Mac-specific files from desktop app (now handled by CLI):

| File                                           | Status |
| ---------------------------------------------- | ------ |
| `app/Services/MacPhpFpmService.php`            | Remove |
| `app/Services/MacBrewService.php`              | Remove |
| `app/Services/MacHorizonService.php`           | Remove |
| `app/Services/CaddyfileGenerator.php`          | Remove |
| `app/Console/Commands/MigrateToFpmCommand.php` | Remove |
| `app/Console/Commands/DoctorCommand.php`       | Remove |
| `app/Console/Commands/StatusCommand.php`       | Remove |
| `resources/stubs/mac-fpm-pool.stub`            | Remove |
| `resources/stubs/horizon-launchd.plist.stub`   | Remove |

**Keep:**

- `app/Services/DnsResolverService.php` - Creates `/etc/resolver/` files on the Mac where desktop runs

### Phase 4: Release

1. Build and release new CLI version
2. Test full flow: create local environment → automatic setup
3. Verify cleanup doesn't break any remaining functionality

---

## Verification

### CLI Testing (on Mac)

```bash
ssh launchpad@ai
cd ~/projects/orbit-cli

# Test on Mac (copy to local machine first)
scp -r . your-mac:~/orbit-cli-dev/
ssh your-mac
cd ~/orbit-cli-dev
php launchpad setup --json
```

### Desktop Testing

1. Create new local environment in desktop app
2. Observe progress UI updates
3. Verify all services running:
    ```bash
    brew services list | grep -E 'php|caddy'
    launchctl list | grep horizon
    docker ps
    ```
4. Test DNS: `dig orbit.test @127.0.0.1`
5. Open https://orbit.test

---

## Key Design Decisions

| Decision                              | Rationale                                                       |
| ------------------------------------- | --------------------------------------------------------------- |
| **CLI owns all provisioning logic**   | Desktop app stays simple, CLI can be used standalone            |
| **Unified `launchpad setup` command** | Auto-detects platform, no need for `setup:mac` vs `setup:linux` |
| **JSON output format**                | Enables any client (desktop, CI, scripts) to track progress     |
| **OrbStack over Docker Desktop**      | Better performance on Mac, but falls back to Docker Desktop     |
| **Idempotent steps**                  | Safe to re-run, checks before each action                       |
| **Simple sudo calls**                 | Let macOS handle auth naturally (Touch ID if configured)        |

---

## Notes

- All provisioning logic lives in `orbit-cli`, not the desktop app
- Desktop app is just a "dumb UI shell" that displays progress
- The `--json` flag is key for desktop integration
- Existing `ProvisioningService.php` in desktop handles remote (SSH) provisioning - that stays
- `DnsResolverService.php` in desktop stays because it runs on the Mac where the desktop app runs
