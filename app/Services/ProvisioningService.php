<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Log;

class ProvisioningService
{
    protected string $host;
    protected string $sshPublicKey;
    protected array $log = [];

    protected string $cliDownloadUrl = 'https://github.com/nckrtl/launchpad-cli/releases/latest/download/launchpad.phar';

    public function provision(string $host, string $sshPublicKey): array
    {
        $this->host = $host;
        $this->sshPublicKey = trim($sshPublicKey);
        $this->log = [];

        try {
            // Step 1: Test root connection
            $this->step('Testing root SSH connection');
            if (!$this->testRootConnection()) {
                return $this->failure('Cannot connect as root. Ensure root SSH access is available.');
            }

            // Step 2: Create launchpad user
            $this->step('Creating launchpad user');
            if (!$this->createUser()) {
                return $this->failure('Failed to create launchpad user');
            }

            // Step 3: Setup SSH key for launchpad user
            $this->step('Setting up SSH key for launchpad user');
            if (!$this->setupSshKey()) {
                return $this->failure('Failed to setup SSH key');
            }

            // Step 4: Configure sudo for launchpad user
            $this->step('Configuring passwordless sudo');
            if (!$this->configureSudo()) {
                return $this->failure('Failed to configure sudo');
            }

            // Step 5: Secure SSH configuration
            $this->step('Securing SSH configuration');
            if (!$this->secureSsh()) {
                return $this->failure('Failed to secure SSH');
            }

            // Step 6: Test launchpad user connection
            $this->step('Testing launchpad user SSH connection');
            if (!$this->testLaunchpadConnection()) {
                return $this->failure('Cannot connect as launchpad user');
            }

            // Step 7: Install Docker
            $this->step('Installing Docker');
            if (!$this->installDocker()) {
                return $this->failure('Failed to install Docker');
            }

            // Step 8: Disable systemd-resolved (uses port 53)
            $this->step('Configuring DNS');
            if (!$this->configureDns()) {
                return $this->failure('Failed to configure DNS');
            }

            // Step 9: Install PHP
            $this->step('Installing PHP');
            if (!$this->installPhp()) {
                return $this->failure('Failed to install PHP');
            }

            // Step 10: Install launchpad CLI
            $this->step('Installing launchpad CLI');
            if (!$this->installCli()) {
                return $this->failure('Failed to install launchpad CLI');
            }

            // Step 11: Create directory structure
            $this->step('Creating directory structure');
            if (!$this->createDirectories()) {
                return $this->failure('Failed to create directories');
            }

            // Step 12: Initialize launchpad
            $this->step('Initializing launchpad stack');
            if (!$this->initializeLaunchpad()) {
                return $this->failure('Failed to initialize launchpad');
            }

            // Step 13: Start launchpad
            $this->step('Starting launchpad services');
            if (!$this->startLaunchpad()) {
                return $this->failure('Failed to start launchpad');
            }

            // Step 14: Final connection test
            $this->step('Final connection test');
            $status = $this->getLaunchpadStatus();

            return [
                'success' => true,
                'message' => 'Server provisioned successfully',
                'log' => $this->log,
                'server' => [
                    'host' => $this->host,
                    'user' => 'launchpad',
                    'port' => 22,
                ],
                'status' => $status,
            ];

        } catch (\Exception $e) {
            Log::error('Provisioning failed', ['error' => $e->getMessage()]);
            return $this->failure($e->getMessage());
        }
    }

    protected function step(string $message): void
    {
        $this->log[] = ['step' => $message, 'time' => now()->toIso8601String()];
        Log::info("Provisioning: {$message}");
    }

    protected function failure(string $message): array
    {
        $this->log[] = ['error' => $message, 'time' => now()->toIso8601String()];
        return [
            'success' => false,
            'error' => $message,
            'log' => $this->log,
        ];
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
            '-o StrictHostKeyChecking=accept-new',
            '-o ConnectTimeout=10',
        ];

        $options = implode(' ', $sshOptions);
        $escapedCommand = escapeshellarg($command);

        return "ssh {$options} {$user}@{$this->host} {$escapedCommand}";
    }

    protected function testRootConnection(): bool
    {
        $result = $this->runAsRoot('echo "connected"');
        return $result['success'] && str_contains($result['output'], 'connected');
    }

    protected function createUser(): bool
    {
        // Check if user exists
        $check = $this->runAsRoot('id launchpad >/dev/null 2>&1 && echo "user_exists" || echo "user_not_exists"');

        if (trim($check['output']) === 'user_exists') {
            $this->log[] = ['info' => 'User launchpad already exists'];
            return true;
        }

        // Create user with home directory
        $result = $this->runAsRoot('useradd -m -s /bin/bash launchpad 2>&1 || true');

        // Verify user was created
        $verify = $this->runAsRoot('id launchpad >/dev/null 2>&1 && echo "success" || echo "failed"');
        if (!str_contains(trim($verify['output']), 'success')) {
            $this->log[] = ['error' => 'Failed to create user: ' . $result['output'] . $result['error']];
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

        if (!$result['success']) {
            $this->log[] = ['error' => 'SSH key setup error: ' . $result['error']];
            return false;
        }

        // Verify setup
        $verify = $this->runAsRoot('stat -c "%U" /home/launchpad/.ssh/authorized_keys');
        if (trim($verify['output']) !== 'launchpad') {
            $this->log[] = ['error' => 'SSH key file ownership incorrect: ' . trim($verify['output'])];
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
        return $result['success'] && str_contains($result['output'], 'connected');
    }

    protected function installDocker(): bool
    {
        // Check if Docker is already installed
        $check = $this->runAsLaunchpad('docker --version 2>/dev/null && echo "docker_found" || echo "docker_not_found"');

        if (str_contains($check['output'], 'docker_found')) {
            $this->log[] = ['info' => 'Docker already installed'];
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

        if (!$result['success']) {
            $this->log[] = ['error' => 'Docker installation output: ' . $result['error']];
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

        if (!$result['success']) {
            $this->log[] = ['error' => 'DNS configuration output: ' . $result['error']];
            return false;
        }

        return true;
    }

    protected function installPhp(): bool
    {
        // Check if PHP is already installed
        $check = $this->runAsLaunchpad('php --version 2>/dev/null && echo "installed" || echo "not_installed"');

        if (str_contains($check['output'], 'installed') && !str_contains($check['output'], 'not_installed')) {
            $this->log[] = ['info' => 'PHP already installed'];
            return true;
        }

        // Install PHP using php.new (needs TERM set for the installer)
        $result = $this->runAsLaunchpad('export TERM=xterm && /bin/bash -c "$(curl -fsSL https://php.new/install/linux)"', 300);

        if (!$result['success']) {
            $this->log[] = ['error' => 'PHP installation output: ' . $result['output'] . $result['error']];
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

        if (!$result['success']) {
            $this->log[] = ['error' => 'CLI installation output: ' . $result['error']];
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

        if (!$result['success']) {
            $this->log[] = ['error' => 'Launchpad init output: ' . $result['output'] . $result['error']];
            return false;
        }

        return true;
    }

    protected function startLaunchpad(): bool
    {
        $result = $this->runAsLaunchpad('export PATH="$HOME/.config/herd-lite/bin:$HOME/.local/bin:$PATH" && sg docker -c "php ~/.local/bin/launchpad start"', 120);

        if (!$result['success']) {
            $this->log[] = ['error' => 'Launchpad start output: ' . $result['output'] . $result['error']];
            return false;
        }

        return true;
    }

    protected function getLaunchpadStatus(): ?array
    {
        $result = $this->runAsLaunchpad('export PATH="$HOME/.config/herd-lite/bin:$HOME/.local/bin:$PATH" && sg docker -c "php ~/.local/bin/launchpad status --json"');

        if (!$result['success']) {
            return null;
        }

        return json_decode($result['output'], true);
    }
}
