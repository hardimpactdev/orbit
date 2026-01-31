<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Process;

final class PlatformService
{
    // ===========================================
    // OS Detection
    // ===========================================

    public function isMacOS(): bool
    {
        return PHP_OS_FAMILY === 'Darwin';
    }

    public function isLinux(): bool
    {
        return PHP_OS_FAMILY === 'Linux';
    }

    public function getOS(): string
    {
        return PHP_OS_FAMILY;
    }

    // ===========================================
    // Package Manager Detection
    // ===========================================

    public function getPackageManager(): ?string
    {
        if ($this->isMacOS()) {
            return $this->commandExists('brew') ? 'brew' : null;
        }

        if ($this->isLinux()) {
            if ($this->commandExists('apt')) {
                return 'apt';
            }
            if ($this->commandExists('dnf')) {
                return 'dnf';
            }
            if ($this->commandExists('yum')) {
                return 'yum';
            }
        }

        return null;
    }

    // ===========================================
    // Command Utilities
    // ===========================================

    public function commandExists(string $command): bool
    {
        $result = Process::run("command -v {$command} 2>/dev/null");

        return $result->successful() && trim($result->output()) !== '';
    }

    public function getCommandOutput(string $command): ?string
    {
        $result = Process::run($command);

        return $result->successful() ? trim($result->output()) : null;
    }

    // ===========================================
    // Container Runtime Detection
    // ===========================================

    public function hasOrbStack(): bool
    {
        if (! $this->isMacOS()) {
            return false;
        }

        return $this->commandExists('orbctl');
    }

    public function hasDockerDesktop(): bool
    {
        if (! $this->isMacOS()) {
            return false;
        }

        // Check if Docker Desktop app exists
        return is_dir('/Applications/Docker.app');
    }

    public function hasDocker(): bool
    {
        if (! $this->commandExists('docker')) {
            return false;
        }

        // Verify docker is actually working
        $result = Process::run('docker info 2>/dev/null');

        return $result->successful();
    }

    public function getContainerRuntime(): ?string
    {
        if (! $this->hasDocker()) {
            return null;
        }

        if ($this->isMacOS()) {
            // Check for OrbStack first (preferred)
            if ($this->hasOrbStack()) {
                return 'orbstack';
            }

            if ($this->hasDockerDesktop()) {
                return 'docker-desktop';
            }
        }

        return 'docker';
    }

    public function getRecommendedRuntime(): string
    {
        return $this->isMacOS() ? 'orbstack' : 'docker';
    }

    // ===========================================
    // PHP Detection
    // ===========================================

    public function hasPhp(string $minVersion = '8.2'): bool
    {
        if (! $this->commandExists('php')) {
            return false;
        }

        $version = $this->getPhpVersion();
        if ($version === null) {
            return false;
        }

        return version_compare($version, $minVersion, '>=');
    }

    public function getPhpVersion(): ?string
    {
        $output = $this->getCommandOutput('php -r "echo PHP_VERSION;"');

        return $output ?: null;
    }

    // ===========================================
    // Composer Detection
    // ===========================================

    public function hasComposer(): bool
    {
        return $this->commandExists('composer');
    }

    public function getComposerVersion(): ?string
    {
        $output = $this->getCommandOutput('composer --version 2>/dev/null');
        if ($output === null) {
            return null;
        }

        // Extract version from "Composer version 2.7.1 2024-01-10 ..."
        if (preg_match('/Composer version (\d+\.\d+\.\d+)/', $output, $matches)) {
            return $matches[1];
        }

        return null;
    }

    // ===========================================
    // dig Detection (DNS debugging tool)
    // ===========================================

    public function hasDig(): bool
    {
        return $this->commandExists('dig');
    }

    // ===========================================
    // DNS Detection
    // ===========================================

    public function getDnsStatus(string $tld): array
    {
        // Check if port 53 is in use
        $port53InUse = $this->isPortInUse(53);

        // Check if orbit-dns is already running and owns port 53
        if ($port53InUse && $this->isOrbitDnsRunning()) {
            return [
                'status' => 'orbit_dns_running',
                'message' => "Orbit DNS is running and handles .{$tld} domains",
                'port_53_in_use' => true,
            ];
        }

        // Check for existing dnsmasq on macOS
        if ($this->isMacOS()) {
            $hasDnsmasqResolver = $this->hasDnsmasqResolverForTld($tld);
            $dnsmasqRunning = $this->isDnsmasqRunning();

            if ($hasDnsmasqResolver && $dnsmasqRunning) {
                return [
                    'status' => 'dnsmasq_configured',
                    'message' => "Existing dnsmasq handles .{$tld} domains",
                    'can_use_existing' => true,
                    'port_53_in_use' => $port53InUse,
                ];
            }

            // If dnsmasq is running but we got here, resolver isn't configured for this TLD
            if ($dnsmasqRunning) {
                return [
                    'status' => 'dnsmasq_wrong_tld',
                    'message' => "dnsmasq running but not configured for .{$tld}",
                    'can_use_existing' => false,
                    'port_53_in_use' => $port53InUse,
                ];
            }
        }

        // Check for systemd-resolved on Linux
        if ($this->isLinux()) {
            $hasSystemdResolved = $this->hasSystemdResolved();
            $stubListenerDisabled = $this->isSystemdResolvedStubDisabled();

            if ($hasSystemdResolved && ! $stubListenerDisabled) {
                return [
                    'status' => 'systemd_resolved_conflict',
                    'message' => 'systemd-resolved is using port 53',
                    'needs_configuration' => true,
                    'port_53_in_use' => true,
                ];
            }
        }

        if ($port53InUse) {
            return [
                'status' => 'port_53_conflict',
                'message' => 'Port 53 is in use by another process',
                'port_53_in_use' => true,
            ];
        }

        return [
            'status' => 'ready',
            'message' => 'Ready for Docker DNS',
            'port_53_in_use' => false,
        ];
    }

    public function isPortInUse(int $port): bool
    {
        if ($this->isMacOS()) {
            $result = Process::run("lsof -i :{$port} 2>/dev/null | grep LISTEN");
        } else {
            $result = Process::run("ss -tlnp 2>/dev/null | grep :{$port}");
        }

        return $result->successful() && trim($result->output()) !== '';
    }

    /**
     * Check if the orbit-dns container is running and using port 53.
     */
    public function isOrbitDnsRunning(): bool
    {
        $result = Process::run('docker ps -q -f name=orbit-dns');

        return $result->successful() && trim($result->output()) !== '';
    }

    /**
     * Check if port 53 is being used by orbit-dns specifically.
     */
    public function isPort53OwnedByOrbitDns(): bool
    {
        if (! $this->isPortInUse(53)) {
            return false;
        }

        return $this->isOrbitDnsRunning();
    }

    // ===========================================
    // macOS DNS (dnsmasq)
    // ===========================================

    public function hasDnsmasqResolverForTld(string $tld): bool
    {
        $resolverFile = "/etc/resolver/{$tld}";

        if (! file_exists($resolverFile)) {
            return false;
        }

        $content = file_get_contents($resolverFile);

        return str_contains($content, '127.0.0.1');
    }

    public function isDnsmasqRunning(): bool
    {
        if ($this->isMacOS()) {
            $result = Process::run('brew services list 2>/dev/null | grep dnsmasq | grep started');

            return $result->successful();
        }

        // On Linux, check if dnsmasq process is running
        $result = Process::run('pgrep -x dnsmasq');

        return $result->successful();
    }

    public function getDnsmasqConfigPath(): ?string
    {
        if ($this->isMacOS()) {
            $brewPrefix = $this->getCommandOutput('brew --prefix');
            if ($brewPrefix) {
                return "{$brewPrefix}/etc/dnsmasq.conf";
            }
        }

        if (file_exists('/etc/dnsmasq.conf')) {
            return '/etc/dnsmasq.conf';
        }

        return null;
    }

    // ===========================================
    // Linux DNS (systemd-resolved)
    // ===========================================

    public function hasSystemdResolved(): bool
    {
        if (! $this->isLinux()) {
            return false;
        }

        $result = Process::run('systemctl is-active systemd-resolved 2>/dev/null');

        return $result->successful() && trim($result->output()) === 'active';
    }

    public function isSystemdResolvedStubDisabled(): bool
    {
        // Check main config
        $mainConfig = '/etc/systemd/resolved.conf';
        if (file_exists($mainConfig)) {
            $content = file_get_contents($mainConfig);
            if (preg_match('/^\s*DNSStubListener\s*=\s*no/mi', $content)) {
                return true;
            }
        }

        // Check drop-in configs
        $dropInDir = '/etc/systemd/resolved.conf.d';
        if (is_dir($dropInDir)) {
            foreach (glob("{$dropInDir}/*.conf") as $file) {
                $content = file_get_contents($file);
                if (preg_match('/^\s*DNSStubListener\s*=\s*no/mi', $content)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function configureSystemdResolved(): bool
    {
        $dropInDir = '/etc/systemd/resolved.conf.d';
        $configFile = "{$dropInDir}/orbit.conf";
        $config = "[Resolve]\nDNSStubListener=no\n";

        // Create drop-in directory
        $result = Process::run("sudo mkdir -p {$dropInDir}");
        if (! $result->successful()) {
            return false;
        }

        // Write config file
        $result = Process::run("echo '{$config}' | sudo tee {$configFile}");
        if (! $result->successful()) {
            return false;
        }

        // Restart systemd-resolved
        $result = Process::run('sudo systemctl restart systemd-resolved');

        return $result->successful();
    }

    public function configureResolvConf(): bool
    {
        $content = "nameserver 127.0.0.1\nnameserver 1.1.1.1\n";

        // Backup existing resolv.conf
        Process::run('sudo cp /etc/resolv.conf /etc/resolv.conf.backup 2>/dev/null');

        // Write new resolv.conf
        $result = Process::run("echo '{$content}' | sudo tee /etc/resolv.conf");

        return $result->successful();
    }

    // ===========================================
    // Installation Helpers
    // ===========================================

    public function installPhp(string $version = '8.4'): bool
    {
        if ($this->isMacOS()) {
            $result = Process::timeout(300)->run(
                "/bin/bash -c \"\$(curl -fsSL https://php.new/install/mac/{$version})\""
            );

            return $result->successful();
        }

        if ($this->isLinux()) {
            $result = Process::timeout(300)->run(
                "/bin/bash -c \"\$(curl -fsSL https://php.new/install/linux/{$version})\""
            );

            return $result->successful();
        }

        return false;
    }

    public function installComposer(): bool
    {
        $pm = $this->getPackageManager();

        if ($pm === 'brew') {
            $result = Process::timeout(120)->run('brew install composer');

            return $result->successful();
        }

        if ($pm === 'apt') {
            $result = Process::timeout(120)->run('sudo apt update && sudo apt install -y composer');

            return $result->successful();
        }

        return false;
    }

    public function installDig(): bool
    {
        // dig is built-in on macOS
        if ($this->isMacOS()) {
            return true;
        }

        $pm = $this->getPackageManager();

        if ($pm === 'apt') {
            $result = Process::timeout(60)->run('sudo apt update && sudo apt install -y dnsutils');

            return $result->successful();
        }

        if ($pm === 'dnf' || $pm === 'yum') {
            $result = Process::timeout(60)->run("sudo {$pm} install -y bind-utils");

            return $result->successful();
        }

        return false;
    }

    public function installOrbStack(): bool
    {
        if (! $this->isMacOS()) {
            return false;
        }

        if (! $this->commandExists('brew')) {
            return false;
        }

        $result = Process::timeout(300)->run('brew install --cask orbstack');

        return $result->successful();
    }

    public function installDocker(): bool
    {
        if ($this->isMacOS()) {
            // On macOS, prefer OrbStack
            return $this->installOrbStack();
        }

        $pm = $this->getPackageManager();

        if ($pm === 'apt') {
            $result = Process::timeout(300)->run(
                'sudo apt update && sudo apt install -y docker.io && sudo systemctl enable --now docker'
            );

            return $result->successful();
        }

        return false;
    }

    // ===========================================
    // Supervisor Detection & Installation
    // ===========================================

    public function hasSupervisor(): bool
    {
        return $this->commandExists('supervisorctl');
    }

    public function isSupervisorRunning(): bool
    {
        if (! $this->hasSupervisor()) {
            return false;
        }

        $result = Process::run('sudo supervisorctl status 2>/dev/null');

        // supervisorctl returns 0 if running, even with no programs
        return $result->successful() || str_contains($result->output(), 'no such file');
    }

    public function installSupervisor(): bool
    {
        $pm = $this->getPackageManager();

        if ($pm === 'apt') {
            $result = Process::timeout(120)->run(
                'sudo apt update && sudo apt install -y supervisor && sudo systemctl enable --now supervisor'
            );

            return $result->successful();
        }

        if ($pm === 'brew') {
            $result = Process::timeout(120)->run('brew install supervisor');

            return $result->successful();
        }

        return false;
    }

    // ===========================================
    // Prerequisite Summary
    // ===========================================

    public function checkPrerequisites(): array
    {
        $checks = [];

        // PHP
        $phpVersion = $this->getPhpVersion();
        $checks['php'] = [
            'name' => 'PHP',
            'installed' => $this->hasPhp('8.2'),
            'version' => $phpVersion,
            'required' => '>= 8.2',
            'installable' => true,
        ];

        // Docker
        $runtime = $this->getContainerRuntime();
        $checks['docker'] = [
            'name' => 'Docker',
            'installed' => $runtime !== null,
            'version' => $runtime,
            'required' => 'OrbStack (macOS) or Docker',
            'installable' => $this->isMacOS() || $this->getPackageManager() === 'apt',
        ];

        // Composer
        $composerVersion = $this->getComposerVersion();
        $checks['composer'] = [
            'name' => 'Composer',
            'installed' => $this->hasComposer(),
            'version' => $composerVersion,
            'required' => 'Any version',
            'installable' => $this->getPackageManager() !== null,
        ];

        // dig (optional)
        $checks['dig'] = [
            'name' => 'dig',
            'installed' => $this->hasDig(),
            'version' => null,
            'required' => 'Optional (DNS debugging)',
            'installable' => true,
            'optional' => true,
        ];

        // Supervisor (for Horizon)
        $checks['supervisor'] = [
            'name' => 'Supervisor',
            'installed' => $this->hasSupervisor(),
            'version' => null,
            'required' => 'For Horizon queue worker (optional - orbit uses Docker)',
            'optional' => true,
            'installable' => $this->getPackageManager() !== null,
        ];

        return $checks;
    }
}
