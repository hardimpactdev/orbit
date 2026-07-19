<?php

declare(strict_types=1);

namespace App\Services\Dns;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class OrbitDnsServiceInstaller
{
    public function __construct(
        private readonly DnsmasqReconciler $reconciler,
        private readonly string $rootPath,
    ) {}

    public function install(): void
    {
        $this->ensureWgEasyRunning();

        File::ensureDirectoryExists($this->rootPath);

        $confPath = $this->rootPath.'/'.DnsmasqReconciler::BaseConfig;
        $recordsPath = $this->rootPath.'/dnsmasq.d';
        $legacyBase = File::exists($confPath) && str_contains(File::get($confPath), 'address=/');

        $stagedConfigurationChanged = $legacyBase
            ? $this->reconciler->stageLegacyMigrationLayout()
            : $this->reconciler->stageAllForInstall();

        $composePath = $this->rootPath.'/docker-compose.yaml';
        $compose = $this->renderCompose($confPath, $recordsPath);
        $existingCompose = File::exists($composePath) ? File::get($composePath) : null;

        if ($existingCompose !== $compose) {
            File::put($composePath, $compose);
        }

        $result = Process::timeout(180)->run(sprintf(
            'docker compose -f %s up -d',
            escapeshellarg($composePath),
        ));

        if (! $result->successful()) {
            throw new RuntimeException(
                'Failed to start orbit-dns: '.trim($result->errorOutput().' '.$result->output()),
            );
        }

        if ($legacyBase) {
            $this->reconciler->migrateLegacyLayout();
        } elseif ($stagedConfigurationChanged) {
            $this->reconciler->activateStagedConfiguration();
        }
    }

    private function ensureWgEasyRunning(): void
    {
        $result = Process::timeout(15)->run('docker ps -q -f name=wg-easy');

        if (! $result->successful() || trim($result->output()) === '') {
            throw new RuntimeException(
                'wg-easy container is not running; install wg-easy before orbit-dns.',
            );
        }
    }

    private function renderCompose(string $confPath, string $recordsPath): string
    {
        return <<<YAML
            services:
              orbit-dns:
                image: 4km3/dnsmasq:latest
                container_name: orbit-dns
                network_mode: "container:wg-easy"
                restart: unless-stopped
                cap_add:
                  - NET_ADMIN
                volumes:
                  - {$confPath}:/etc/dnsmasq.conf:ro
                  - {$recordsPath}:/etc/dnsmasq.d:ro

            YAML;
    }
}
