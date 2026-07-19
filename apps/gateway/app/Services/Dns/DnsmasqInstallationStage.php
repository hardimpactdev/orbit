<?php

declare(strict_types=1);

namespace App\Services\Dns;

use Illuminate\Support\Facades\File;

final readonly class DnsmasqInstallationStage
{
    private function __construct(
        private DnsmasqReconciler $reconciler,
        private bool $legacyBase,
        private bool $configurationChanged,
    ) {}

    public static function prepare(string $rootPath, DnsmasqReconciler $reconciler): self
    {
        $basePath = $rootPath.'/'.DnsmasqReconciler::BaseConfig;
        $legacyBase = File::exists($basePath) && str_contains(File::get($basePath), 'address=/');
        $configurationChanged = $legacyBase
            ? $reconciler->stageLegacyMigrationLayout()
            : $reconciler->stageAllForInstall();

        return new self(
            reconciler: $reconciler,
            legacyBase: $legacyBase,
            configurationChanged: $configurationChanged,
        );
    }

    public function activate(): void
    {
        if ($this->legacyBase) {
            $this->reconciler->migrateLegacyLayout();

            return;
        }

        if (! $this->configurationChanged) {
            return;
        }

        $this->reconciler->activateStagedConfiguration();
    }
}
