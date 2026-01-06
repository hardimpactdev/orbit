<?php

namespace App\Services;

use App\Models\Server;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class ProvisioningService
{
    protected Server $server;

    protected string $sshPublicKey;

    protected int $currentStep = 0;

    protected int $totalSteps = 14;

    protected string $cliDownloadUrl = 'https://github.com/nckrtl/launchpad-cli/releases/latest/download/launchpad.phar';

    protected array $steps = [
        1 => 'Clearing old SSH host keys',
        2 => 'Testing root SSH connection',
        3 => 'Creating launchpad user',
        4 => 'Setting up SSH key',
        5 => 'Configuring passwordless sudo',
        6 => 'Securing SSH configuration',
        7 => 'Testing launchpad user connection',
        8 => 'Installing Docker',
        9 => 'Configuring DNS',
        10 => 'Installing PHP',
        11 => 'Installing launchpad CLI',
        12 => 'Creating directory structure',
        13 => 'Initializing launchpad stack',
        14 => 'Starting launchpad services',
    ];

    public function provision(Server $server, string $sshPublicKey): bool
    {
        $this->server = $server;
        $this->sshPublicKey = trim($sshPublicKey);
        $this->currentStep = 0;

        // Initialize provisioning state
        $this->server->update([
            'status' => Server::STATUS_PROVISIONING,
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

            // Step 3: Create launchpad user
            if (! $this->runStep(3, fn (): bool => $this->createUser())) {
                return $this->failure('Failed to create launchpad user');
            }

            // Step 4: Setup SSH key for launchpad user
            if (! $this->runStep(4, fn (): bool => $this->setupSshKey())) {
                return $this->failure('Failed to setup SSH key');
            }

            // Step 5: Configure sudo for launchpad user
            if (! $this->runStep(5, fn (): bool => $this->configureSudo())) {
                return $this->failure('Failed to configure sudo');
            }

            // Step 6: Secure SSH configuration
            if (! $this->runStep(6, fn (): bool => $this->secureSsh())) {
                return $this->failure('Failed to secure SSH');
            }

            // Step 7: Test launchpad user connection
            if (! $this->runStep(7, fn (): bool => $this->testLaunchpadConnection())) {
                return $this->failure('Cannot connect as launchpad user');
            }

            // Step 8: Install Docker
            if (! $this->runStep(8, fn (): bool => $this->installDocker())) {
                return $this->failure('Failed to install Docker');
            }

            // Step 9: Configure DNS
            if (! $this->runStep(9, fn (): bool => $this->configureDns())) {
                return $this->failure('Failed to configure DNS');
            }

            // Step 10: Install PHP
            if (! $this->runStep(10, fn (): bool => $this->installPhp())) {
                return $this->failure('Failed to install PHP');
            }

            // Step 11: Install launchpad CLI
            if (! $this->runStep(11, fn (): bool => $this->installCli())) {
                return $this->failure('Failed to install launchpad CLI');
            }

            // Step 12: Create directory structure
            if (! $this->runStep(12, fn (): bool => $this->createDirectories())) {
                return $this->failure('Failed to create directories');
            }

            // Step 13: Initialize launchpad
            if (! $this->runStep(13, fn (): bool => $this->initializeLaunchpad())) {
                return $this->failure('Failed to initialize launchpad');
            }

            // Step 14: Start launchpad
            if (! $this->runStep(14, fn (): bool => $this->startLaunchpad())) {
                return $this->failure('Failed to start launchpad');
            }

            // Success!
            $this->server->update([
                'status' => Server::STATUS_ACTIVE,
                'user' => 'launchpad',
                'port' => 22,
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

        $this->server->update([
            'provisioning_step' => $stepNumber,
        ]);

        return $action();
    }

    protected function logStep(string $message): void
    {
        $log = $this->server->provisioning_log ?? [];
        $log[] = ['step' => $message, 'time' => now()->toIso8601String()];
        $this->server->update(['provisioning_log' => $log]);
    }

    protected function logInfo(string $message): void
    {
        $log = $this->server->provisioning_log ?? [];
        $log[] = ['info' => $message, 'time' => now()->toIso8601String()];
        $this->server->update(['provisioning_log' => $log]);
    }

    protected function logError(string $message): void
    {
        $log = $this->server->provisioning_log ?? [];
        $log[] = ['error' => $message, 'time' => now()->toIso8601String()];
        $this->server->update(['provisioning_log' => $log]);
    }

    protected function failure(string $message): bool
    {
        $this->logError($message);
        $this->server->update([
            'status' => Server::STATUS_ERROR,
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

    protected function runAsLaunchpad(string $command, int $timeout = 120): array
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

        return "ssh {$options} {$user}@{$this->server->host} {$escapedCommand}";
    }

    protected function clearOldHostKeys(): bool
    {
        // Remove any existing host keys to prevent conflicts when server is reset
        Process::run("ssh-keygen -R {$this->server->host} 2>/dev/null || true");
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
        // Add launchpad to sudo group and configure passwordless sudo
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

    protected function testLaunchpadConnection(): bool
    {
        $result = $this->runAsLaunchpad('echo "connected"');

        return $result['success'] && str_contains((string) $result['output'], 'connected');
    }

    protected function installDocker(): bool
    {
        // Check if Docker is already installed
        $check = $this->runAsLaunchpad('docker --version 2>/dev/null && echo "docker_found" || echo "docker_not_found"');

        if (str_contains((string) $check['output'], 'docker_found')) {
            $this->logInfo('Docker already installed');
            // Ensure launchpad user is in docker group
            $this->runAsLaunchpad('sudo usermod -aG docker launchpad');

            return true;
        }

        // Install Docker using official script
        $installCommands = [
            'curl -fsSL https://get.docker.com | sudo sh',
            'sudo usermod -aG docker launchpad',
            'sudo systemctl enable docker',
            'sudo systemctl start docker',
        ];

        $result = $this->runAsLaunchpad(implode(' && ', $installCommands), 300);

        if (! $result['success']) {
            $this->logError('Docker installation output: '.$result['error']);

            return false;
        }

        return true;
    }

    protected function configureDns(): bool
    {
        // Disable systemd-resolved (it uses port 53 which launchpad DNS needs)
        // and set DNS to 1.1.1.1
        $commands = [
            'sudo systemctl stop systemd-resolved 2>/dev/null || true',
            'sudo systemctl disable systemd-resolved 2>/dev/null || true',
            'sudo rm -f /etc/resolv.conf',
            'echo "nameserver 1.1.1.1" | sudo tee /etc/resolv.conf',
            'echo "nameserver 8.8.8.8" | sudo tee -a /etc/resolv.conf',
        ];

        $result = $this->runAsLaunchpad(implode(' && ', $commands));

        if (! $result['success']) {
            $this->logError('DNS configuration output: '.$result['error']);

            return false;
        }

        return true;
    }

    protected function installPhp(): bool
    {
        // Check if PHP is already installed (check herd-lite path first)
        $check = $this->runAsLaunchpad('export PATH="$HOME/.config/herd-lite/bin:$PATH" && php --version 2>/dev/null && echo "installed" || echo "not_installed"');

        if (str_contains((string) $check['output'], 'installed') && ! str_contains((string) $check['output'], 'not_installed')) {
            $this->logInfo('PHP already installed');

            return true;
        }

        // Install PHP using php.new (needs TERM set for the installer)
        $result = $this->runAsLaunchpad('export TERM=xterm-ghostty && /bin/bash -c "$(curl -fsSL https://php.new/install/linux)"', 300);

        if (! $result['success']) {
            $this->logError('PHP installation output: '.$result['output'].$result['error']);

            return false;
        }

        // Verify installation - php.new installs to ~/.config/herd-lite/bin
        $verify = $this->runAsLaunchpad('export PATH="$HOME/.config/herd-lite/bin:$PATH" && php --version');

        return $verify['success'];
    }

    protected function installCli(): bool
    {
        $commands = [
            'mkdir -p ~/.local/bin',
            "curl -L -o ~/.local/bin/launchpad {$this->cliDownloadUrl}",
            'chmod +x ~/.local/bin/launchpad',
        ];

        $result = $this->runAsLaunchpad(implode(' && ', $commands));

        if (! $result['success']) {
            $this->logError('CLI installation output: '.$result['error']);

            return false;
        }

        // Verify installation - use herd-lite PHP
        $verify = $this->runAsLaunchpad('export PATH="$HOME/.config/herd-lite/bin:$PATH" && php ~/.local/bin/launchpad --version');

        return $verify['success'];
    }

    protected function createDirectories(): bool
    {
        $commands = [
            'mkdir -p ~/projects',
        ];

        $result = $this->runAsLaunchpad(implode(' && ', $commands));

        return $result['success'];
    }

    protected function initializeLaunchpad(): bool
    {
        // Need to use sg (switch group) to pick up docker group membership
        // Also set PATH for herd-lite PHP
        $result = $this->runAsLaunchpad('export PATH="$HOME/.config/herd-lite/bin:$HOME/.local/bin:$PATH" && sg docker -c "php ~/.local/bin/launchpad init"', 600);

        if (! $result['success']) {
            $this->logError('Launchpad init output: '.$result['output'].$result['error']);

            return false;
        }

        // Create Docker network (CLI init has a bug where it doesn't persist the network)
        $this->runAsLaunchpad('sg docker -c "docker network create launchpad 2>/dev/null || true"');

        return true;
    }

    protected function startLaunchpad(): bool
    {
        $result = $this->runAsLaunchpad('export PATH="$HOME/.config/herd-lite/bin:$HOME/.local/bin:$PATH" && sg docker -c "php ~/.local/bin/launchpad start"', 120);

        if (! $result['success']) {
            $this->logError('Launchpad start output: '.$result['output'].$result['error']);

            return false;
        }

        return true;
    }

    public function getLaunchpadStatus(): ?array
    {
        $result = $this->runAsLaunchpad('export PATH="$HOME/.config/herd-lite/bin:$HOME/.local/bin:$PATH" && sg docker -c "php ~/.local/bin/launchpad status --json"');

        if (! $result['success']) {
            return null;
        }

        return json_decode((string) $result['output'], true);
    }
}
