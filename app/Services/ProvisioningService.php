<?php

namespace App\Services;

use App\Models\Environment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class ProvisioningService
{
    protected Environment $environment;

    protected string $sshPublicKey;

    protected int $currentStep = 0;

    protected int $totalSteps = 17;

    protected string $cliDownloadUrl = 'https://github.com/nckrtl/orbit-cli/releases/latest/download/orbit.phar';

    protected array $steps = [
        1 => 'Clearing old SSH host keys',
        2 => 'Testing root SSH connection',
        3 => 'Creating orbit user',
        4 => 'Setting up SSH key',
        5 => 'Configuring passwordless sudo',
        6 => 'Securing SSH configuration',
        7 => 'Testing orbit user connection',
        8 => 'Installing Docker',
        9 => 'Configuring DNS',
        10 => 'Adding Ondřej PPA',
        11 => 'Installing PHP-FPM versions',
        12 => 'Configuring PHP-FPM pools',
        13 => 'Installing Caddy',
        14 => 'Installing orbit CLI',
        15 => 'Creating directory structure',
        16 => 'Initializing orbit stack',
        17 => 'Starting orbit services',
    ];

    public function provision(Environment $environment, string $sshPublicKey): bool
    {
        $this->environment = $environment;
        $this->sshPublicKey = trim($sshPublicKey);
        $this->currentStep = 0;

        // Initialize provisioning state
        $this->environment->update([
            'status' => Environment::STATUS_PROVISIONING,
            'provisioning_log' => [],
            'provisioning_error' => null,
            'provisioning_step' => 0,
            'provisioning_total_steps' => $this->totalSteps,
        ]);

        try {
            // Step 1: Clear old SSH host keys
            if (! $this->runStep(1, fn (): bool => $this->clearOldHostKeys())) {
                return false;
            }

            // Step 2: Test root connection
            if (! $this->runStep(2, fn (): bool => $this->testRootConnection())) {
                return $this->failure('Cannot connect as root. Ensure root SSH access is available.');
            }

            // Step 3: Create orbit user
            if (! $this->runStep(3, fn (): bool => $this->createUser())) {
                return $this->failure('Failed to create orbit user');
            }

            // Step 4: Setup SSH key for orbit user
            if (! $this->runStep(4, fn (): bool => $this->setupSshKey())) {
                return $this->failure('Failed to setup SSH key');
            }

            // Step 5: Configure sudo for orbit user
            if (! $this->runStep(5, fn (): bool => $this->configureSudo())) {
                return $this->failure('Failed to configure sudo');
            }

            // Step 6: Secure SSH configuration
            if (! $this->runStep(6, fn (): bool => $this->secureSsh())) {
                return $this->failure('Failed to secure SSH');
            }

            // Step 7: Test orbit user connection
            if (! $this->runStep(7, fn (): bool => $this->testOrbitConnection())) {
                return $this->failure('Cannot connect as orbit user');
            }

            // Step 8: Install Docker
            if (! $this->runStep(8, fn (): bool => $this->installDocker())) {
                return $this->failure('Failed to install Docker');
            }

            // Step 9: Configure DNS
            if (! $this->runStep(9, fn (): bool => $this->configureDns())) {
                return $this->failure('Failed to configure DNS');
            }

            // Step 10: Add Ondřej PPA
            if (! $this->runStep(10, fn (): bool => $this->addOndrejPpa())) {
                return $this->failure('Failed to add Ondřej PPA');
            }

            // Step 11: Install PHP-FPM versions
            if (! $this->runStep(11, fn (): bool => $this->installPhpFpm())) {
                return $this->failure('Failed to install PHP-FPM');
            }

            // Step 12: Configure PHP-FPM pools
            if (! $this->runStep(12, fn (): bool => $this->configurePhpFpmPools())) {
                return $this->failure('Failed to configure PHP-FPM pools');
            }

            // Step 13: Install Caddy
            if (! $this->runStep(13, fn (): bool => $this->installCaddy())) {
                return $this->failure('Failed to install Caddy');
            }

            // Step 14: Install orbit CLI
            if (! $this->runStep(14, fn (): bool => $this->installCli())) {
                return $this->failure('Failed to install orbit CLI');
            }

            // Step 15: Create directory structure
            if (! $this->runStep(15, fn (): bool => $this->createDirectories())) {
                return $this->failure('Failed to create directories');
            }

            // Step 16: Initialize orbit
            if (! $this->runStep(16, fn (): bool => $this->initializeOrbit())) {
                return $this->failure('Failed to initialize orbit');
            }

            // Step 17: Start orbit
            if (! $this->runStep(17, fn (): bool => $this->startOrbit())) {
                return $this->failure('Failed to start orbit');
            }

            // Success! Clear provisioning log since it's no longer needed
            $this->environment->update([
                'status' => Environment::STATUS_ACTIVE,
                'user' => 'launchpad',
                'port' => 22,
                'provisioning_log' => null,
                'provisioning_error' => null,
                'provisioning_step' => null,
                'provisioning_total_steps' => null,
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Provisioning failed', ['error' => $e->getMessage()]);

            return $this->failure($e->getMessage());
        }
    }

    protected function runStep(int $stepNumber, callable $action): bool
    {
        $this->currentStep = $stepNumber;
        $stepName = $this->steps[$stepNumber] ?? "Step {$stepNumber}";

        $this->logStep($stepName);
        Log::info("Provisioning step {$stepNumber}: {$stepName}");

        $this->environment->update([
            'provisioning_step' => $stepNumber,
        ]);

        return $action();
    }

    protected function logStep(string $message): void
    {
        $log = $this->environment->provisioning_log ?? [];
        $log[] = ['step' => $message, 'time' => now()->toIso8601String()];
        $this->environment->update(['provisioning_log' => $log]);
    }

    protected function logInfo(string $message): void
    {
        $log = $this->environment->provisioning_log ?? [];
        $log[] = ['info' => $message, 'time' => now()->toIso8601String()];
        $this->environment->update(['provisioning_log' => $log]);
    }

    protected function logError(string $message): void
    {
        $log = $this->environment->provisioning_log ?? [];
        $log[] = ['error' => $message, 'time' => now()->toIso8601String()];
        $this->environment->update(['provisioning_log' => $log]);
    }

    protected function failure(string $message): bool
    {
        $this->logError($message);
        $this->environment->update([
            'status' => Environment::STATUS_ERROR,
            'provisioning_error' => $message,
        ]);

        return false;
    }

    protected function runAsRoot(string $command, int $timeout = 120): array
    {
        $sshCommand = $this->buildSshCommand('root', $command);
        $result = Process::timeout($timeout)->run($sshCommand);

        return [
            'success' => $result->successful(),
            'output' => $result->output(),
            'error' => $result->errorOutput(),
            'exit_code' => $result->exitCode(),
        ];
    }

    protected function runAsLaunchpadUser(string $command, int $timeout = 120): array
    {
        $sshCommand = $this->buildSshCommand('launchpad', $command);
        $result = Process::timeout($timeout)->run($sshCommand);

        return [
            'success' => $result->successful(),
            'output' => $result->output(),
            'error' => $result->errorOutput(),
            'exit_code' => $result->exitCode(),
        ];
    }

    protected function buildSshCommand(string $user, string $command): string
    {
        $sshOptions = [
            '-o BatchMode=yes',
            '-o StrictHostKeyChecking=no',
            '-o UserKnownHostsFile=/dev/null',
            '-o ConnectTimeout=10',
        ];

        $options = implode(' ', $sshOptions);
        $escapedCommand = escapeshellarg($command);

        return "ssh {$options} {$user}@{$this->environment->host} {$escapedCommand}";
    }

    protected function clearOldHostKeys(): bool
    {
        // Remove any existing host keys to prevent conflicts when server is reset
        Process::run("ssh-keygen -R {$this->environment->host} 2>/dev/null || true");
        $this->logInfo('Cleared old SSH host keys');

        return true;
    }

    protected function testRootConnection(): bool
    {
        $result = $this->runAsRoot('echo "connected"');

        return $result['success'] && str_contains((string) $result['output'], 'connected');
    }

    protected function createUser(): bool
    {
        // Check if user exists
        $check = $this->runAsRoot('id launchpad >/dev/null 2>&1 && echo "user_exists" || echo "user_not_exists"');

        if (trim((string) $check['output']) === 'user_exists') {
            $this->logInfo('User launchpad already exists');

            return true;
        }

        // Create user with home directory
        $result = $this->runAsRoot('useradd -m -s /bin/bash launchpad 2>&1 || true');

        // Verify user was created
        $verify = $this->runAsRoot('id launchpad >/dev/null 2>&1 && echo "success" || echo "failed"');
        if (! str_contains(trim((string) $verify['output']), 'success')) {
            $this->logError('Failed to create user: '.$result['output'].$result['error']);

            return false;
        }

        return true;
    }

    protected function setupSshKey(): bool
    {
        // Escape the SSH key for shell
        $escapedKey = str_replace("'", "'\\''", $this->sshPublicKey);

        $script = <<<BASH
mkdir -p /home/launchpad/.ssh
chmod 700 /home/launchpad/.ssh
echo '$escapedKey' > /home/launchpad/.ssh/authorized_keys
chmod 600 /home/launchpad/.ssh/authorized_keys
chown -R launchpad:launchpad /home/launchpad/.ssh
chown launchpad:launchpad /home/launchpad
BASH;

        $result = $this->runAsRoot($script);

        if (! $result['success']) {
            $this->logError('SSH key setup error: '.$result['error']);

            return false;
        }

        // Verify setup
        $verify = $this->runAsRoot('stat -c "%U" /home/launchpad/.ssh/authorized_keys');
        if (trim((string) $verify['output']) !== 'launchpad') {
            $this->logError('SSH key file ownership incorrect: '.trim((string) $verify['output']));

            return false;
        }

        return true;
    }

    protected function configureSudo(): bool
    {
        // Add SSH user to sudo group and configure passwordless sudo
        $commands = [
            'usermod -aG sudo launchpad 2>/dev/null || usermod -aG wheel launchpad 2>/dev/null || true',
            "echo 'launchpad ALL=(ALL) NOPASSWD:ALL' > /etc/sudoers.d/launchpad",
            'chmod 440 /etc/sudoers.d/launchpad',
        ];

        $result = $this->runAsRoot(implode(' && ', $commands));

        return $result['success'];
    }

    protected function secureSsh(): bool
    {
        $sshdConfig = '/etc/ssh/sshd_config';

        // Backup and update sshd_config
        $commands = [
            "cp {$sshdConfig} {$sshdConfig}.bak",
            // Disable password authentication
            "sed -i 's/^#*PasswordAuthentication.*/PasswordAuthentication no/' {$sshdConfig}",
            "sed -i 's/^#*ChallengeResponseAuthentication.*/ChallengeResponseAuthentication no/' {$sshdConfig}",
            // Disable root login
            "sed -i 's/^#*PermitRootLogin.*/PermitRootLogin no/' {$sshdConfig}",
            // Enable pubkey authentication
            "sed -i 's/^#*PubkeyAuthentication.*/PubkeyAuthentication yes/' {$sshdConfig}",
            // Ensure settings exist if not present
            "grep -q '^PasswordAuthentication' {$sshdConfig} || echo 'PasswordAuthentication no' >> {$sshdConfig}",
            "grep -q '^PermitRootLogin' {$sshdConfig} || echo 'PermitRootLogin no' >> {$sshdConfig}",
            "grep -q '^PubkeyAuthentication' {$sshdConfig} || echo 'PubkeyAuthentication yes' >> {$sshdConfig}",
            // Restart SSH service
            'systemctl restart sshd || systemctl restart ssh || service ssh restart',
        ];

        $result = $this->runAsRoot(implode(' && ', $commands));

        return $result['success'];
    }

    protected function testOrbitConnection(): bool
    {
        $result = $this->runAsLaunchpadUser('echo "connected"');

        return $result['success'] && str_contains((string) $result['output'], 'connected');
    }

    protected function installDocker(): bool
    {
        // Check if Docker is already installed
        $check = $this->runAsLaunchpadUser('docker --version 2>/dev/null && echo "docker_found" || echo "docker_not_found"');

        if (str_contains((string) $check['output'], 'docker_found')) {
            $this->logInfo('Docker already installed');
            // Ensure orbit user is in docker group
            $this->runAsLaunchpadUser('sudo usermod -aG docker launchpad');

            return true;
        }

        // Install Docker using official script
        $installCommands = [
            'curl -fsSL https://get.docker.com | sudo sh',
            'sudo usermod -aG docker launchpad',
            'sudo systemctl enable docker',
            'sudo systemctl start docker',
        ];

        $result = $this->runAsLaunchpadUser(implode(' && ', $installCommands), 300);

        if (! $result['success']) {
            $this->logError('Docker installation output: '.$result['error']);

            return false;
        }

        return true;
    }

    protected function configureDns(): bool
    {
        // Disable systemd-resolved (it uses port 53 which Orbit DNS needs)
        // and set DNS to 1.1.1.1
        $commands = [
            'sudo systemctl stop systemd-resolved 2>/dev/null || true',
            'sudo systemctl disable systemd-resolved 2>/dev/null || true',
            'sudo rm -f /etc/resolv.conf',
            'echo "nameserver 1.1.1.1" | sudo tee /etc/resolv.conf',
            'echo "nameserver 8.8.8.8" | sudo tee -a /etc/resolv.conf',
        ];

        $result = $this->runAsLaunchpadUser(implode(' && ', $commands));

        if (! $result['success']) {
            $this->logError('DNS configuration output: '.$result['error']);

            return false;
        }

        return true;
    }

    protected function addOndrejPpa(): bool
    {
        // Check if PPA is already added
        $check = $this->runAsLaunchpadUser('apt-cache policy | grep -q "ondrej/php" && echo "exists" || echo "missing"');

        if (str_contains((string) $check['output'], 'exists')) {
            $this->logInfo('Ondřej PPA already added');

            return true;
        }

        // Add Ondřej PPA for PHP packages
        $commands = [
            'sudo add-apt-repository ppa:ondrej/php -y',
            'sudo apt update',
        ];

        $result = $this->runAsLaunchpadUser(implode(' && ', $commands), 120);

        if (! $result['success']) {
            $this->logError('PPA addition output: '.$result['output'].$result['error']);

            return false;
        }

        return true;
    }

    protected function installPhpFpm(): bool
    {
        // Install PHP-FPM versions with common extensions
        $versions = ['8.2', '8.3', '8.4'];
        $extensions = [
            'fpm', 'cli', 'mbstring', 'xml', 'curl', 'zip', 'gd',
            'pgsql', 'mysql', 'redis', 'sqlite3', 'bcmath', 'intl', 'pcntl',
        ];

        foreach ($versions as $version) {
            // Check if already installed
            $check = $this->runAsLaunchpadUser("dpkg -l | grep -q 'php{$version}-fpm' && echo 'installed' || echo 'missing'");

            if (str_contains((string) $check['output'], 'installed')) {
                $this->logInfo("PHP {$version} already installed");

                continue;
            }

            // Build package list
            $packages = [];
            foreach ($extensions as $ext) {
                $packages[] = "php{$version}-{$ext}";
            }

            $packageList = implode(' ', $packages);
            $this->logInfo("Installing PHP {$version} with extensions");

            // Install packages
            $result = $this->runAsLaunchpadUser("sudo DEBIAN_FRONTEND=noninteractive apt install -y {$packageList}", 300);

            if (! $result['success']) {
                $this->logError("PHP {$version} installation output: ".$result['output'].$result['error']);

                return false;
            }
        }

        return true;
    }

    protected function configurePhpFpmPools(): bool
    {
        $versions = ['8.2', '8.3', '8.4'];

        // Keep Orbit-managed PHP config in one place so it can be shared across versions.
        $this->runAsLaunchpadUser('mkdir -p ~/.config/orbit/php ~/.config/orbit/logs');

        $globalIniPath = '/home/launchpad/.config/orbit/php/orbit.ini';
        $globalIni = "; Orbit global PHP settings\n"
            ."; Shared across all installed PHP versions (CLI + FPM)\n"
            ."; Add directives here (e.g. memory_limit=512M)\n";

        $changedAny = false;

        $globalIniResult = $this->ensureRemoteFile($globalIniPath, $globalIni, false);
        if (! $globalIniResult['success']) {
            $this->logError('Failed to write Orbit global PHP ini');

            return false;
        }
        $changedAny = $changedAny || $globalIniResult['changed'];

        foreach ($versions as $version) {
            $normalized = str_replace('.', '', $version); // "8.4" -> "84"
            $socketPath = "/home/launchpad/.config/orbit/php/php{$normalized}.sock";
            $logPath = "/home/launchpad/.config/orbit/logs/php{$normalized}-fpm.log";

            // Create custom pool configuration
            $poolConfig = <<<INI
; Orbit PHP-FPM Pool Configuration
; Generated by orbit-desktop provisioning

[orbit-{$normalized}]
user = launchpad
group = launchpad

; Socket configuration
listen = {$socketPath}
listen.owner = launchpad
listen.group = launchpad
listen.mode = 0660

; Process management
pm = dynamic
pm.max_children = 10
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
pm.max_requests = 500

; Logging
php_admin_value[error_log] = {$logPath}
php_admin_flag[log_errors] = on
catch_workers_output = yes
decorate_workers_output = no

; Environment variables (critical for CLI/Bun access)
env[PATH] = /home/launchpad/.local/bin:/home/launchpad/.bun/bin:/usr/local/bin:/usr/bin:/bin
env[HOME] = /home/launchpad
env[USER] = launchpad
INI;

            $poolConfigPath = "/home/launchpad/.config/orbit/php/php{$normalized}-fpm.conf";

            $poolWriteResult = $this->ensureRemoteFile($poolConfigPath, $poolConfig, false);
            if (! $poolWriteResult['success']) {
                $this->logError("Failed to write pool config for PHP {$version}");

                return false;
            }
            $changedAny = $changedAny || $poolWriteResult['changed'];

            // Include our pool in the PHP-FPM config (symlinked into pool.d)
            $poolLink = "/etc/php/{$version}/fpm/pool.d/orbit.conf";
            $poolSymlinkResult = $this->ensureRemoteSymlink($poolConfigPath, $poolLink, true);
            if (! $poolSymlinkResult['success']) {
                $this->logError("Failed to symlink pool config for PHP {$version}");

                return false;
            }
            $changedAny = $changedAny || $poolSymlinkResult['changed'];

            // Use a single global ini file across all versions.
            $fpmIniLink = "/etc/php/{$version}/fpm/conf.d/99-orbit.ini";
            $cliIniLink = "/etc/php/{$version}/cli/conf.d/99-orbit.ini";

            $fpmIniSymlinkResult = $this->ensureRemoteSymlink($globalIniPath, $fpmIniLink, true);
            if (! $fpmIniSymlinkResult['success']) {
                $this->logError("Failed to symlink Orbit ini for PHP {$version} (FPM)");

                return false;
            }
            $changedAny = $changedAny || $fpmIniSymlinkResult['changed'];

            $cliIniSymlinkResult = $this->ensureRemoteSymlink($globalIniPath, $cliIniLink, true);
            if (! $cliIniSymlinkResult['success']) {
                $this->logError("Failed to symlink Orbit ini for PHP {$version} (CLI)");

                return false;
            }
            $changedAny = $changedAny || $cliIniSymlinkResult['changed'];

            // Start and enable PHP-FPM service
            $this->logInfo("Starting PHP-FPM {$version}");
            $startResult = $this->runAsLaunchpadUser("sudo systemctl enable php{$version}-fpm && sudo systemctl start php{$version}-fpm");

            if (! $startResult['success']) {
                $this->logError("Failed to start PHP-FPM {$version}: ".$startResult['error']);

                return false;
            }
        }

        // Watch PHP configs and reload PHP-FPM automatically when they change.
        if (! $this->installPhpFpmReloadWatcher($versions, $globalIniPath)) {
            return false;
        }

        if ($changedAny) {
            foreach ($versions as $version) {
                $this->reloadPhpFpm($version);
            }
        }

        return true;
    }

    protected function ensureRemoteFile(string $path, string $contents, bool $sudo): array
    {
        $expectedHash = hash('sha256', $contents);
        $pathArg = escapeshellarg($path);

        $getHashScript = "if [ -f {$pathArg} ]; then sha256sum {$pathArg} | cut -d ' ' -f1; else echo missing; fi";
        $getHashCommand = $sudo
            ? 'sudo sh -c '.escapeshellarg($getHashScript)
            : $getHashScript;

        $currentHashResult = $this->runAsLaunchpadUser($getHashCommand);
        if (! $currentHashResult['success']) {
            return ['success' => false, 'changed' => false];
        }

        $currentHash = trim((string) $currentHashResult['output']);
        if ($currentHash === $expectedHash) {
            return ['success' => true, 'changed' => false];
        }

        $base64 = base64_encode($contents);
        $base64Arg = escapeshellarg($base64);

        $writeCommand = $sudo
            ? "printf %s {$base64Arg} | base64 -d | sudo tee {$pathArg} >/dev/null"
            : "printf %s {$base64Arg} | base64 -d > {$pathArg}";

        $writeResult = $this->runAsLaunchpadUser($writeCommand);
        if (! $writeResult['success']) {
            return ['success' => false, 'changed' => false];
        }

        return ['success' => true, 'changed' => true];
    }

    protected function ensureRemoteSymlink(string $target, string $link, bool $sudo): array
    {
        $targetArg = escapeshellarg($target);
        $linkArg = escapeshellarg($link);

        $linkCheckScript = "if [ -L {$linkArg} ] && [ \"$(readlink -f {$linkArg})\" = \"$(readlink -f {$targetArg})\" ]; then echo ok; else ln -sf {$targetArg} {$linkArg} && echo changed; fi";

        $command = $sudo
            ? 'sudo sh -c '.escapeshellarg($linkCheckScript)
            : 'sh -c '.escapeshellarg($linkCheckScript);

        $result = $this->runAsLaunchpadUser($command);
        if (! $result['success']) {
            return ['success' => false, 'changed' => false];
        }

        $output = trim((string) $result['output']);

        return ['success' => true, 'changed' => $output === 'changed'];
    }

    protected function reloadPhpFpm(string $version): void
    {
        $reload = $this->runAsLaunchpadUser("sudo systemctl reload php{$version}-fpm");
        if ($reload['success']) {
            return;
        }

        $this->runAsLaunchpadUser("sudo systemctl restart php{$version}-fpm");
    }

    protected function installPhpFpmReloadWatcher(array $versions, string $globalIniPath): bool
    {
        $scriptPath = '/usr/local/bin/orbit-reload-php-fpm';
        $scriptLines = [
            '#!/usr/bin/env bash',
            'set -euo pipefail',
        ];

        foreach ($versions as $version) {
            $scriptLines[] = "if systemctl list-unit-files | grep -q '^php{$version}-fpm\\.service'; then";
            $scriptLines[] = "  systemctl reload php{$version}-fpm || systemctl restart php{$version}-fpm";
            $scriptLines[] = 'fi';
        }

        $script = implode("\n", $scriptLines)."\n";

        $scriptResult = $this->ensureRemoteFile($scriptPath, $script, true);
        if (! $scriptResult['success']) {
            $this->logError('Failed to install PHP-FPM reload script');

            return false;
        }

        if ($scriptResult['changed']) {
            $this->runAsLaunchpadUser('sudo chmod +x '.escapeshellarg($scriptPath));
        }

        $servicePath = '/etc/systemd/system/orbit-php-fpm-reload.service';
        $pathPath = '/etc/systemd/system/orbit-php-fpm-reload.path';

        $serviceUnit = <<<UNIT
[Unit]
Description=Orbit reload PHP-FPM when configs change

[Service]
Type=oneshot
ExecStart={$scriptPath}
UNIT;

        $serviceResult = $this->ensureRemoteFile($servicePath, $serviceUnit."\n", true);
        if (! $serviceResult['success']) {
            $this->logError('Failed to install PHP-FPM reload systemd service');

            return false;
        }

        $pathLines = [
            '[Unit]',
            'Description=Orbit watch PHP config changes',
            '',
            '[Path]',
            "PathModified={$globalIniPath}",
        ];

        foreach ($versions as $version) {
            $pathLines[] = "PathModified=/etc/php/{$version}/fpm/php.ini";
            $pathLines[] = "PathModified=/etc/php/{$version}/fpm/php-fpm.conf";
            $pathLines[] = "PathModified=/etc/php/{$version}/fpm/conf.d/";
            $pathLines[] = "PathModified=/etc/php/{$version}/fpm/pool.d/";
        }

        $pathLines[] = 'Unit=orbit-php-fpm-reload.service';
        $pathLines[] = '';
        $pathLines[] = '[Install]';
        $pathLines[] = 'WantedBy=multi-user.target';

        $pathUnit = implode("\n", $pathLines)."\n";

        $pathResult = $this->ensureRemoteFile($pathPath, $pathUnit, true);
        if (! $pathResult['success']) {
            $this->logError('Failed to install PHP-FPM reload systemd path unit');

            return false;
        }

        if ($serviceResult['changed'] || $pathResult['changed']) {
            $this->runAsLaunchpadUser('sudo systemctl daemon-reload');
        }

        $enableResult = $this->runAsLaunchpadUser('sudo systemctl enable --now orbit-php-fpm-reload.path');
        if (! $enableResult['success']) {
            $this->logError('Failed to enable PHP-FPM reload watcher: '.$enableResult['error']);

            return false;
        }

        return true;
    }

    protected function installCaddy(): bool
    {
        // Check if Caddy is already installed
        $check = $this->runAsLaunchpadUser('command -v caddy >/dev/null 2>&1 && echo "installed" || echo "missing"');

        if (str_contains((string) $check['output'], 'installed')) {
            $this->logInfo('Caddy already installed');

            return true;
        }

        // Install Caddy from official repository
        $commands = [
            'sudo apt install -y debian-keyring debian-archive-keyring apt-transport-https curl',
            "curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | sudo gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg",
            "curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | sudo tee /etc/apt/sources.list.d/caddy-stable.list",
            'sudo apt update',
            'sudo apt install -y caddy',
        ];

        $result = $this->runAsLaunchpadUser(implode(' && ', $commands), 180);

        if (! $result['success']) {
            $this->logError('Caddy installation output: '.$result['output'].$result['error']);

            return false;
        }

        // Verify installation
        $verify = $this->runAsLaunchpadUser('caddy version');

        return $verify['success'];
    }

    protected function installCli(): bool
    {
        $commands = [
            'mkdir -p ~/.local/bin',
            "curl -L -o ~/.local/bin/orbit {$this->cliDownloadUrl}",
            'chmod +x ~/.local/bin/orbit',
        ];

        $result = $this->runAsLaunchpadUser(implode(' && ', $commands));

        if (! $result['success']) {
            $this->logError('CLI installation output: '.$result['error']);

            return false;
        }

        // Verify installation
        $verify = $this->runAsLaunchpadUser('php ~/.local/bin/orbit --version');

        return $verify['success'];
    }

    protected function createDirectories(): bool
    {
        $commands = [
            'mkdir -p ~/projects',
        ];

        $result = $this->runAsLaunchpadUser(implode(' && ', $commands));

        return $result['success'];
    }

    protected function initializeOrbit(): bool
    {
        // Need to use sg (switch group) to pick up docker group membership
        $result = $this->runAsLaunchpadUser('sg docker -c "php ~/.local/bin/orbit.init"', 600);

        if (! $result['success']) {
            $this->logError('Orbit init output: '.$result['output'].$result['error']);

            return false;
        }

        // Create Docker network (CLI init has a bug where it doesn't persist the network)
        $this->runAsLaunchpadUser('sg docker -c "docker network create orbit 2>/dev/null || true"');

        return true;
    }

    protected function startOrbit(): bool
    {
        $result = $this->runAsLaunchpadUser('sg docker -c "php ~/.local/bin/orbit start"', 120);

        if (! $result['success']) {
            $this->logError('Orbit start output: '.$result['output'].$result['error']);

            return false;
        }

        return true;
    }

    public function getOrbitStatus(): ?array
    {
        $result = $this->runAsLaunchpadUser('sg docker -c "php ~/.local/bin/orbit status --json"');

        if (! $result['success']) {
            return null;
        }

        return json_decode((string) $result['output'], true);
    }

    /**
     * Check if an environment already has Orbit configured.
     * Returns info about existing setup or null if not configured.
     */
    public static function checkExistingSetup(string $host, string $user = 'root'): array
    {
        $result = [
            'has_orbit' => false,
            'has_orbit_user' => false,
            'orbit_running' => false,
            'can_connect' => false,
            'connected_as' => null,
            'error' => null,
        ];

        $sshOptions = [
            '-o BatchMode=yes',
            '-o StrictHostKeyChecking=no',
            '-o UserKnownHostsFile=/dev/null',
            '-o ConnectTimeout=10',
        ];
        $options = implode(' ', $sshOptions);

        // First, try connecting as the orbit user (in case already provisioned)
        $orbitCheck = Process::timeout(15)->run(
            "ssh {$options} launchpad@{$host} 'echo connected'"
        );

        if ($orbitCheck->successful() && str_contains($orbitCheck->output(), 'connected')) {
            $result['can_connect'] = true;
            $result['connected_as'] = 'launchpad';
            $result['has_orbit_user'] = true;

            // Check if orbit CLI is installed and get status
            $statusCheck = Process::timeout(30)->run(
                "ssh {$options} launchpad@{$host} 'php ~/.local/bin/orbit status --json 2>/dev/null'"
            );

            if ($statusCheck->successful()) {
                $status = json_decode($statusCheck->output(), true);
                if ($status) {
                    $result['has_orbit'] = true;
                    $result['orbit_running'] = ($status['status'] ?? '') === 'running';
                    $result['status'] = $status;
                }
            } else {
                // Check if CLI exists but maybe not running
                $cliCheck = Process::timeout(15)->run(
                    "ssh {$options} launchpad@{$host} 'test -f ~/.local/bin/launchpad && echo exists'"
                );
                if ($cliCheck->successful() && str_contains($cliCheck->output(), 'exists')) {
                    $result['has_orbit'] = true;
                }
            }

            return $result;
        }

        // Try connecting as the specified user (usually root for provisioning)
        $rootCheck = Process::timeout(15)->run(
            "ssh {$options} {$user}@{$host} 'echo connected'"
        );

        if ($rootCheck->successful() && str_contains($rootCheck->output(), 'connected')) {
            $result['can_connect'] = true;
            $result['connected_as'] = $user;

            // Check if orbit user exists
            $userCheck = Process::timeout(15)->run(
                "ssh {$options} {$user}@{$host} 'id launchpad >/dev/null 2>&1 && echo exists || echo missing'"
            );

            if (str_contains($userCheck->output(), 'exists')) {
                $result['has_orbit_user'] = true;
            }

            return $result;
        }

        // Cannot connect
        $result['error'] = 'Cannot connect to environment. Ensure SSH key is configured.';

        return $result;
    }
}
