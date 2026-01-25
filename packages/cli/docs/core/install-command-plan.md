# Install Command Implementation Plan

> **Status: IMPLEMENTED** (v0.1.36)
>
> This plan has been fully implemented. The new `orbit install` command consolidates
> the previous `setup`, `init`, and `trust` commands into a single unified installation
> experience.

## Overview

Consolidate `setup`, `init`, `trust`, and `start` into a single `orbit install` command using the Action pattern for modularity. Each logical step becomes an idempotent action that checks if work is already done before executing.

Platform-specific logic is split into separate pipelines (`InstallLinuxPipeline`, `InstallMacPipeline`) for easier testing and maintenance, with shared actions reused between them.

## Command Signatures

### New Commands
```bash
orbit install                    # Full installation (orchestrates everything)
orbit trust-root-ca              # Trust SSL certificate (renamed from trust)
```

### Deprecated/Removed
```bash
orbit setup                      # Removed - logic moves to install actions
orbit init                       # Removed - logic moves to install actions
orbit trust                      # Renamed to "trust-root-ca"
```

### Kept As-Is
```bash
orbit start                      # Start services (useful for daily use)
orbit stop                       # Stop services
```

---

## Architecture

### Directory Structure
```
app/
├── Actions/
│   └── Install/                           # NEW: Installation actions
│       │
│       │── Shared/                        # Platform-agnostic actions
│       │   ├── CreateDirectories.php
│       │   ├── CopyConfigurationFiles.php
│       │   ├── InstallWebApp.php
│       │   ├── GenerateCaddyfile.php
│       │   ├── GenerateDnsConfig.php
│       │   ├── InitializeServices.php
│       │   ├── CreateDockerNetwork.php
│       │   ├── ConfigureHostsFile.php
│       │   ├── BuildDockerImages.php
│       │   ├── PullServiceImages.php
│       │   ├── StartServices.php
│       │   └── InstallComposerLink.php
│       │
│       ├── Linux/                         # Linux-specific actions
│       │   ├── CheckPrerequisites.php
│       │   ├── InstallDocker.php
│       │   ├── InstallPhp.php
│       │   ├── InstallCaddy.php
│       │   ├── InstallSupportTools.php
│       │   ├── ConfigureDns.php
│       │   └── TrustRootCa.php
│       │
│       └── Mac/                           # macOS-specific actions
│           ├── CheckPrerequisites.php
│           ├── InstallHomebrew.php
│           ├── InstallOrbStack.php
│           ├── InstallPhp.php
│           ├── InstallCaddy.php
│           ├── InstallSupportTools.php
│           ├── ConfigureDns.php
│           └── TrustRootCa.php
│
├── Commands/
│   ├── InstallCommand.php                 # NEW: Main orchestrator
│   └── TrustRootCaCommand.php             # NEW: Renamed from TrustCommand
│
├── Data/
│   └── Install/
│       └── InstallContext.php             # NEW: Installation configuration
│
└── Services/
    └── Install/
        ├── InstallLinuxPipeline.php       # NEW: Linux installation flow
        ├── InstallMacPipeline.php         # NEW: macOS installation flow
        └── InstallLogger.php              # NEW: Progress output
```

---

## Data Structures

### InstallContext
```php
<?php

declare(strict_types=1);

namespace App\Data\Install;

final readonly class InstallContext
{
    public function __construct(
        public string $tld = 'test',
        public array $phpVersions = ['8.4', '8.5'],
        public bool $skipDocker = false,
        public bool $skipTrust = false,
        public bool $nonInteractive = false,
        public string $configDir = '',      // ~/.config/orbit
        public string $homeDir = '',        // ~
    ) {}

    public static function fromOptions(array $options): self
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME');

        return new self(
            tld: $options['tld'] ?? 'test',
            phpVersions: array_map('trim', explode(',', $options['php-versions'] ?? '8.4,8.5')),
            skipDocker: $options['skip-docker'] ?? false,
            skipTrust: $options['skip-trust'] ?? false,
            nonInteractive: $options['yes'] ?? false,
            configDir: "{$home}/.config/orbit",
            homeDir: $home,
        );
    }
}
```

### StepResult (reuse from Provision)
```php
// Already exists at App\Data\Provision\StepResult
// Can be moved to App\Data\StepResult and shared between Provision and Install
```

---

## Action Specifications

### Shared Actions (Platform-Agnostic)

These actions work identically on both Linux and macOS:

| Action | Purpose | Idempotency Check |
|--------|---------|-------------------|
| `CreateDirectories` | Create `~/.config/orbit` structure | Skip existing dirs |
| `CopyConfigurationFiles` | Deploy stub files (PHP, Caddy, DNS) | Skip if files exist with same content |
| `InstallWebApp` | Extract orbit-web bundle | Skip if already installed |
| `GenerateCaddyfile` | Generate Caddy config | Always regenerate (fast) |
| `GenerateDnsConfig` | Generate dnsmasq.conf | Always regenerate (fast) |
| `InitializeServices` | Create services.yaml, docker-compose | Skip if exists |
| `CreateDockerNetwork` | Create orbit Docker network | Skip if network exists |
| `ConfigureHostsFile` | Add /etc/hosts entries | Skip if entries exist |
| `BuildDockerImages` | Build DNS, PHP images | Skip if images exist |
| `PullServiceImages` | Pull postgres, redis, mailpit | Skip if images exist |
| `StartServices` | Start all Docker containers | Skip if already running |
| `InstallComposerLink` | Install global Composer plugin | Skip if already installed |

#### Example: CreateDirectories (Shared)
```php
<?php

declare(strict_types=1);

namespace App\Actions\Install\Shared;

use App\Data\Install\InstallContext;
use App\Data\Provision\StepResult;
use App\Services\Install\InstallLogger;

final readonly class CreateDirectories
{
    private const DIRECTORIES = [
        'php',
        'caddy',
        'dns',
        'postgres',
        'redis',
        'mailpit',
        'horizon',
        'logs',
        'logs/provision',
    ];

    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        foreach (self::DIRECTORIES as $dir) {
            $path = "{$context->configDir}/{$dir}";

            if (is_dir($path)) {
                continue;
            }

            if (! mkdir($path, 0755, true)) {
                return StepResult::failed("Failed to create directory: {$path}");
            }
        }

        return StepResult::success();
    }
}
```

---

### Linux-Specific Actions

| Action | Purpose | Idempotency Check |
|--------|---------|-------------------|
| `CheckPrerequisites` | Verify apt available, systemd present | Always runs (quick) |
| `InstallDocker` | Install Docker via apt | Skip if `docker` running |
| `InstallPhp` | Install PHP via ondrej/php PPA | Skip installed versions |
| `InstallCaddy` | Install Caddy via apt | Skip if installed |
| `InstallSupportTools` | Install dig, etc. via apt | Skip if installed |
| `ConfigureDns` | Configure systemd-resolved | Skip if resolver configured |
| `TrustRootCa` | Add cert to system trust store | Skip if already trusted |

#### Example: Linux/CheckPrerequisites
```php
<?php

declare(strict_types=1);

namespace App\Actions\Install\Linux;

use App\Data\Install\InstallContext;
use App\Data\Provision\StepResult;
use App\Services\Install\InstallLogger;

final readonly class CheckPrerequisites
{
    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        // Check apt is available
        if (! $this->commandExists('apt')) {
            return StepResult::failed('apt package manager not found. Ubuntu/Debian required.');
        }

        // Check systemd is available (for DNS configuration)
        if (! is_dir('/etc/systemd')) {
            return StepResult::failed('systemd not found. Required for DNS configuration.');
        }

        return StepResult::success();
    }

    private function commandExists(string $command): bool
    {
        return Process::run("which {$command}")->successful();
    }
}
```

#### Example: Linux/InstallDocker
```php
<?php

declare(strict_types=1);

namespace App\Actions\Install\Linux;

use App\Data\Install\InstallContext;
use App\Data\Provision\StepResult;
use App\Services\Install\InstallLogger;
use Illuminate\Support\Facades\Process;

final readonly class InstallDocker
{
    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        if ($context->skipDocker) {
            $logger->skip('Docker installation skipped');
            return StepResult::success();
        }

        // Check if Docker is already installed and running
        $dockerRunning = Process::run('docker info')->successful();
        if ($dockerRunning) {
            $logger->skip('Docker already installed and running');
            return StepResult::success();
        }

        // Check if Docker is installed but not running
        if ($this->commandExists('docker')) {
            $logger->step('Starting Docker service');
            Process::run('sudo systemctl start docker');

            if (Process::run('docker info')->successful()) {
                return StepResult::success();
            }

            return StepResult::failed('Docker installed but failed to start');
        }

        // Install Docker
        $logger->step('Installing Docker');

        $commands = [
            'sudo apt-get update',
            'sudo apt-get install -y ca-certificates curl gnupg',
            'sudo install -m 0755 -d /etc/apt/keyrings',
            'curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg',
            'sudo chmod a+r /etc/apt/keyrings/docker.gpg',
            'echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "$VERSION_CODENAME") stable" | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null',
            'sudo apt-get update',
            'sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin',
            'sudo usermod -aG docker $USER',
        ];

        foreach ($commands as $command) {
            $result = Process::timeout(300)->run($command);
            if (! $result->successful()) {
                return StepResult::failed("Failed to install Docker: {$result->errorOutput()}");
            }
        }

        return StepResult::success();
    }

    private function commandExists(string $command): bool
    {
        return Process::run("which {$command}")->successful();
    }
}
```

---

### macOS-Specific Actions

| Action | Purpose | Idempotency Check |
|--------|---------|-------------------|
| `CheckPrerequisites` | Verify Homebrew available | Always runs (quick) |
| `InstallHomebrew` | Install Homebrew if missing | Skip if installed |
| `InstallOrbStack` | Install OrbStack via brew | Skip if installed |
| `InstallPhp` | Install PHP via shivammathur/php tap | Skip installed versions |
| `InstallCaddy` | Install Caddy via brew | Skip if installed |
| `InstallSupportTools` | Install dig via brew | Skip if installed |
| `ConfigureDns` | Configure /etc/resolver | Skip if resolver configured |
| `TrustRootCa` | Add cert to macOS Keychain | Skip if already trusted |

#### Example: Mac/CheckPrerequisites
```php
<?php

declare(strict_types=1);

namespace App\Actions\Install\Mac;

use App\Data\Install\InstallContext;
use App\Data\Provision\StepResult;
use App\Services\Install\InstallLogger;
use Illuminate\Support\Facades\Process;

final readonly class CheckPrerequisites
{
    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        // Check Homebrew is available
        if (! $this->commandExists('brew')) {
            return StepResult::failed(
                'Homebrew not installed. Install it first: /bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"'
            );
        }

        return StepResult::success();
    }

    private function commandExists(string $command): bool
    {
        return Process::run("which {$command}")->successful();
    }
}
```

#### Example: Mac/TrustRootCa
```php
<?php

declare(strict_types=1);

namespace App\Actions\Install\Mac;

use App\Data\Install\InstallContext;
use App\Data\Provision\StepResult;
use App\Services\Install\InstallLogger;
use Illuminate\Support\Facades\Process;

final readonly class TrustRootCa
{
    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        if ($context->skipTrust) {
            $logger->skip('Certificate trust skipped');
            return StepResult::success();
        }

        // Check if Caddy container is running
        $caddyRunning = Process::run('docker ps --format "{{.Names}}" | grep orbit-caddy')->successful();
        if (! $caddyRunning) {
            return StepResult::failed('Caddy container not running. Cannot extract certificate.');
        }

        // Extract certificate from container
        $tempCert = '/tmp/orbit-caddy-root.crt';
        $extractResult = Process::run(
            "docker cp orbit-caddy:/data/caddy/pki/authorities/local/root.crt {$tempCert}"
        );

        if (! $extractResult->successful()) {
            return StepResult::failed('Failed to extract certificate from Caddy container');
        }

        // Add to macOS Keychain
        $trustResult = Process::run(
            "sudo security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain {$tempCert}"
        );

        // Cleanup
        @unlink($tempCert);

        if (! $trustResult->successful()) {
            return StepResult::failed('Failed to add certificate to Keychain');
        }

        return StepResult::success();
    }
}
```

---

## Platform Pipelines

### InstallLinuxPipeline

```php
<?php

declare(strict_types=1);

namespace App\Services\Install;

use App\Actions\Install\Linux;
use App\Actions\Install\Shared;
use App\Data\Install\InstallContext;
use App\Data\Provision\StepResult;

final readonly class InstallLinuxPipeline
{
    /**
     * @return array<array{action: class-string, name: string}>
     */
    public function steps(): array
    {
        return [
            // Phase 1: System Dependencies (Linux-specific)
            ['action' => Linux\CheckPrerequisites::class, 'name' => 'Checking prerequisites'],
            ['action' => Linux\InstallDocker::class, 'name' => 'Installing Docker'],
            ['action' => Linux\InstallPhp::class, 'name' => 'Installing PHP'],
            ['action' => Linux\InstallCaddy::class, 'name' => 'Installing Caddy'],
            ['action' => Linux\InstallSupportTools::class, 'name' => 'Installing support tools'],

            // Phase 2: Configuration (Shared)
            ['action' => Shared\CreateDirectories::class, 'name' => 'Creating directories'],
            ['action' => Shared\CopyConfigurationFiles::class, 'name' => 'Copying configuration'],
            ['action' => Shared\InstallWebApp::class, 'name' => 'Installing web dashboard'],
            ['action' => Shared\GenerateCaddyfile::class, 'name' => 'Generating Caddyfile'],
            ['action' => Shared\GenerateDnsConfig::class, 'name' => 'Generating DNS config'],
            ['action' => Shared\InitializeServices::class, 'name' => 'Initializing services'],

            // Phase 3: Docker Setup (Shared)
            ['action' => Shared\CreateDockerNetwork::class, 'name' => 'Creating Docker network'],
            ['action' => Shared\BuildDockerImages::class, 'name' => 'Building Docker images'],
            ['action' => Shared\PullServiceImages::class, 'name' => 'Pulling service images'],

            // Phase 4: System Integration (Mixed)
            ['action' => Shared\ConfigureHostsFile::class, 'name' => 'Configuring /etc/hosts'],
            ['action' => Linux\ConfigureDns::class, 'name' => 'Configuring DNS'],

            // Phase 5: Start & Finalize (Mixed)
            ['action' => Shared\StartServices::class, 'name' => 'Starting services'],
            ['action' => Shared\InstallComposerLink::class, 'name' => 'Installing composer-link'],
            ['action' => Linux\TrustRootCa::class, 'name' => 'Trusting SSL certificate'],
        ];
    }

    public function run(InstallContext $context, InstallLogger $logger): StepResult
    {
        $steps = $this->steps();
        $total = count($steps);

        foreach ($steps as $index => $step) {
            $logger->progress($index + 1, $total, $step['name']);

            $result = app($step['action'])->handle($context, $logger);

            if ($result->isFailed()) {
                return $result;
            }
        }

        return StepResult::success();
    }
}
```

### InstallMacPipeline

```php
<?php

declare(strict_types=1);

namespace App\Services\Install;

use App\Actions\Install\Mac;
use App\Actions\Install\Shared;
use App\Data\Install\InstallContext;
use App\Data\Provision\StepResult;

final readonly class InstallMacPipeline
{
    /**
     * @return array<array{action: class-string, name: string}>
     */
    public function steps(): array
    {
        return [
            // Phase 1: System Dependencies (Mac-specific)
            ['action' => Mac\CheckPrerequisites::class, 'name' => 'Checking prerequisites'],
            ['action' => Mac\InstallHomebrew::class, 'name' => 'Checking Homebrew'],
            ['action' => Mac\InstallOrbStack::class, 'name' => 'Installing OrbStack'],
            ['action' => Mac\InstallPhp::class, 'name' => 'Installing PHP'],
            ['action' => Mac\InstallCaddy::class, 'name' => 'Installing Caddy'],
            ['action' => Mac\InstallSupportTools::class, 'name' => 'Installing support tools'],

            // Phase 2: Configuration (Shared)
            ['action' => Shared\CreateDirectories::class, 'name' => 'Creating directories'],
            ['action' => Shared\CopyConfigurationFiles::class, 'name' => 'Copying configuration'],
            ['action' => Shared\InstallWebApp::class, 'name' => 'Installing web dashboard'],
            ['action' => Shared\GenerateCaddyfile::class, 'name' => 'Generating Caddyfile'],
            ['action' => Shared\GenerateDnsConfig::class, 'name' => 'Generating DNS config'],
            ['action' => Shared\InitializeServices::class, 'name' => 'Initializing services'],

            // Phase 3: Docker Setup (Shared)
            ['action' => Shared\CreateDockerNetwork::class, 'name' => 'Creating Docker network'],
            ['action' => Shared\BuildDockerImages::class, 'name' => 'Building Docker images'],
            ['action' => Shared\PullServiceImages::class, 'name' => 'Pulling service images'],

            // Phase 4: System Integration (Mixed)
            ['action' => Shared\ConfigureHostsFile::class, 'name' => 'Configuring /etc/hosts'],
            ['action' => Mac\ConfigureDns::class, 'name' => 'Configuring DNS'],

            // Phase 5: Start & Finalize (Mixed)
            ['action' => Shared\StartServices::class, 'name' => 'Starting services'],
            ['action' => Shared\InstallComposerLink::class, 'name' => 'Installing composer-link'],
            ['action' => Mac\TrustRootCa::class, 'name' => 'Trusting SSL certificate'],
        ];
    }

    public function run(InstallContext $context, InstallLogger $logger): StepResult
    {
        $steps = $this->steps();
        $total = count($steps);

        foreach ($steps as $index => $step) {
            $logger->progress($index + 1, $total, $step['name']);

            $result = app($step['action'])->handle($context, $logger);

            if ($result->isFailed()) {
                return $result;
            }
        }

        return StepResult::success();
    }
}
```

---

## InstallCommand

```php
<?php

declare(strict_types=1);

namespace App\Commands;

use App\Data\Install\InstallContext;
use App\Services\Install\InstallLinuxPipeline;
use App\Services\Install\InstallLogger;
use App\Services\Install\InstallMacPipeline;
use LaravelZero\Framework\Commands\Command;

final class InstallCommand extends Command
{
    protected $signature = 'install
        {--tld=test : Top-level domain for local sites}
        {--php-versions=8.4,8.5 : PHP versions to install (comma-separated)}
        {--skip-docker : Skip Docker/OrbStack installation}
        {--skip-trust : Skip SSL certificate trust}
        {--yes : Non-interactive mode}';

    protected $description = 'Install Orbit and configure your development environment';

    public function handle(): int
    {
        $context = InstallContext::fromOptions($this->options());
        $logger = new InstallLogger($this);

        $platform = PHP_OS_FAMILY === 'Darwin' ? 'macOS' : 'Linux';

        $logger->title('Installing Orbit');
        $logger->info("Platform: {$platform}");
        $logger->info("TLD: .{$context->tld}");
        $logger->info("PHP versions: ".implode(', ', $context->phpVersions));
        $logger->newLine();

        // Select platform-specific pipeline
        $pipeline = PHP_OS_FAMILY === 'Darwin'
            ? app(InstallMacPipeline::class)
            : app(InstallLinuxPipeline::class);

        $result = $pipeline->run($context, $logger);

        if ($result->isFailed()) {
            $logger->newLine();
            $logger->error('Installation failed: '.$result->error);
            return self::FAILURE;
        }

        $logger->newLine();
        $logger->success('Orbit installed successfully!');
        $logger->newLine();
        $logger->info("Dashboard: https://orbit.{$context->tld}");
        $logger->info("Create a site: orbit site:create myapp");

        return self::SUCCESS;
    }
}
```

---

## TrustRootCaCommand

Standalone command for users who want to re-trust the certificate:

```php
<?php

declare(strict_types=1);

namespace App\Commands;

use App\Actions\Install\Linux\TrustRootCa as LinuxTrustRootCa;
use App\Actions\Install\Mac\TrustRootCa as MacTrustRootCa;
use App\Data\Install\InstallContext;
use App\Services\Install\InstallLogger;
use LaravelZero\Framework\Commands\Command;

final class TrustRootCaCommand extends Command
{
    protected $signature = 'trust-root-ca';

    protected $description = 'Trust Caddy\'s root CA certificate for HTTPS';

    public function handle(): int
    {
        $context = InstallContext::fromOptions([]);
        $logger = new InstallLogger($this);

        $logger->step('Trusting SSL certificate');

        $action = PHP_OS_FAMILY === 'Darwin'
            ? app(MacTrustRootCa::class)
            : app(LinuxTrustRootCa::class);

        $result = $action->handle($context, $logger);

        if ($result->isFailed()) {
            $logger->error($result->error);
            return self::FAILURE;
        }

        $logger->success('Certificate trusted');
        return self::SUCCESS;
    }
}
```

---

## InstallLogger

```php
<?php

declare(strict_types=1);

namespace App\Services\Install;

use LaravelZero\Framework\Commands\Command;

final class InstallLogger
{
    public function __construct(
        private Command $command,
    ) {}

    public function title(string $message): void
    {
        $this->command->newLine();
        $this->command->line("<fg=blue;options=bold>{$message}</>");
        $this->command->newLine();
    }

    public function step(string $message): void
    {
        $this->command->line("  <fg=yellow>→</> {$message}");
    }

    public function progress(int $current, int $total, string $message): void
    {
        $this->command->line("<fg=gray>[{$current}/{$total}]</> {$message}");
    }

    public function success(string $message): void
    {
        $this->command->line("  <fg=green>✓</> {$message}");
    }

    public function skip(string $message): void
    {
        $this->command->line("  <fg=gray>○</> {$message}");
    }

    public function error(string $message): void
    {
        $this->command->line("  <fg=red>✗</> {$message}");
    }

    public function info(string $message): void
    {
        $this->command->line("  {$message}");
    }

    public function newLine(): void
    {
        $this->command->newLine();
    }
}
```

---

## Migration Path

### Phase 1: Create New Structure
1. Create directory structure:
   - `app/Actions/Install/Shared/`
   - `app/Actions/Install/Linux/`
   - `app/Actions/Install/Mac/`
   - `app/Services/Install/`
   - `app/Data/Install/`
2. Create `InstallContext` data class
3. Create `InstallLogger` service
4. Move `StepResult` to shared location (`App\Data\StepResult`)

### Phase 2: Create Shared Actions
Extract platform-agnostic logic into shared actions:
1. `CreateDirectories`
2. `CopyConfigurationFiles`
3. `InstallWebApp`
4. `GenerateCaddyfile`
5. `GenerateDnsConfig`
6. `InitializeServices`
7. `CreateDockerNetwork`
8. `ConfigureHostsFile`
9. `BuildDockerImages`
10. `PullServiceImages`
11. `StartServices`
12. `InstallComposerLink`

### Phase 3: Create Linux Actions
Extract Linux-specific logic from `SetupCommand` and `InitCommand`:
1. `CheckPrerequisites`
2. `InstallDocker`
3. `InstallPhp`
4. `InstallCaddy`
5. `InstallSupportTools`
6. `ConfigureDns`
7. `TrustRootCa`

### Phase 4: Create Mac Actions
Extract macOS-specific logic from `SetupCommand` and `InitCommand`:
1. `CheckPrerequisites`
2. `InstallHomebrew`
3. `InstallOrbStack`
4. `InstallPhp`
5. `InstallCaddy`
6. `InstallSupportTools`
7. `ConfigureDns`
8. `TrustRootCa`

### Phase 5: Create Pipelines & Commands
1. Create `InstallLinuxPipeline`
2. Create `InstallMacPipeline`
3. Create `InstallCommand`
4. Create `TrustRootCaCommand`

### Phase 6: Deprecate Old Commands
1. Add deprecation warning to `SetupCommand` → suggests `orbit install`
2. Add deprecation warning to `InitCommand` → suggests `orbit install`
3. Add deprecation warning to `TrustCommand` → suggests `orbit trust-root-ca`

### Phase 7: Remove Old Commands (future release)
1. Remove `SetupCommand`
2. Remove `InitCommand`
3. Remove `TrustCommand`

---

## User Experience

### First-Time Install (Linux)
```
$ orbit install

Installing Orbit

  Platform: Linux
  TLD: .test
  PHP versions: 8.4, 8.5

[1/19] Checking prerequisites
  ✓ System requirements met
[2/19] Installing Docker
  ○ Docker already installed and running
[3/19] Installing PHP
  → Installing PHP 8.4
  ✓ PHP 8.4 installed
  ○ PHP 8.5 already installed
[4/19] Installing Caddy
  ○ Caddy already installed
[5/19] Installing support tools
  ○ dig already installed
[6/19] Creating directories
  ✓ Directory structure created
[7/19] Copying configuration
  ✓ Configuration files deployed
...
[18/19] Starting services
  ✓ All services started
[19/19] Trusting SSL certificate
  ✓ Certificate trusted

Orbit installed successfully!

  Dashboard: https://orbit.test
  Create a site: orbit site:create myapp
```

### Re-running (Idempotent)
```
$ orbit install

Installing Orbit

  Platform: Linux
  TLD: .test
  PHP versions: 8.4, 8.5

[1/19] Checking prerequisites
  ✓ System requirements met
[2/19] Installing Docker
  ○ Docker already installed and running
[3/19] Installing PHP
  ○ PHP 8.4 already installed
  ○ PHP 8.5 already installed
[4/19] Installing Caddy
  ○ Caddy already installed
...
[19/19] Trusting SSL certificate
  ○ Certificate already trusted

Orbit installed successfully!

  Dashboard: https://orbit.test
  Create a site: orbit site:create myapp
```

### Trust Certificate Only
```
$ orbit trust-root-ca
  → Trusting SSL certificate
  ✓ Certificate trusted
```

---

## Testing Strategy

The platform-specific pipeline split makes testing straightforward:

### Unit Tests
- Test each action in isolation with mocked dependencies
- Test `InstallContext::fromOptions()` with various inputs

### Integration Tests (per platform)
```php
// tests/Feature/InstallLinuxPipelineTest.php
it('defines correct step order', function () {
    $pipeline = new InstallLinuxPipeline();
    $steps = $pipeline->steps();

    expect($steps[0]['action'])->toBe(Linux\CheckPrerequisites::class);
    expect($steps)->toHaveCount(19);
});

// tests/Feature/InstallMacPipelineTest.php
it('includes OrbStack step for macOS', function () {
    $pipeline = new InstallMacPipeline();
    $steps = collect($pipeline->steps())->pluck('action');

    expect($steps)->toContain(Mac\InstallOrbStack::class);
    expect($steps)->not->toContain(Linux\InstallDocker::class);
});
```

### Action Tests
```php
// tests/Feature/Actions/Install/Shared/CreateDirectoriesTest.php
it('creates all required directories', function () {
    $context = new InstallContext(configDir: '/tmp/orbit-test');
    $logger = Mockery::mock(InstallLogger::class)->shouldIgnoreMissing();

    $result = (new CreateDirectories())->handle($context, $logger);

    expect($result->isSuccess())->toBeTrue();
    expect(is_dir('/tmp/orbit-test/php'))->toBeTrue();
    expect(is_dir('/tmp/orbit-test/caddy'))->toBeTrue();
    // ...
});

it('skips existing directories', function () {
    // Pre-create directories
    mkdir('/tmp/orbit-test/php', 0755, true);

    $context = new InstallContext(configDir: '/tmp/orbit-test');
    $logger = Mockery::mock(InstallLogger::class)->shouldIgnoreMissing();

    $result = (new CreateDirectories())->handle($context, $logger);

    expect($result->isSuccess())->toBeTrue();
});
```

---

## File Summary

| File | Type | LOC (est) |
|------|------|-----------|
| `app/Data/Install/InstallContext.php` | Data | 30 |
| `app/Services/Install/InstallLogger.php` | Service | 50 |
| `app/Services/Install/InstallLinuxPipeline.php` | Pipeline | 60 |
| `app/Services/Install/InstallMacPipeline.php` | Pipeline | 65 |
| `app/Commands/InstallCommand.php` | Command | 50 |
| `app/Commands/TrustRootCaCommand.php` | Command | 35 |
| `app/Actions/Install/Shared/*.php` (12 files) | Actions | ~600 |
| `app/Actions/Install/Linux/*.php` (7 files) | Actions | ~400 |
| `app/Actions/Install/Mac/*.php` (8 files) | Actions | ~450 |
| **Total** | | **~1740** |
