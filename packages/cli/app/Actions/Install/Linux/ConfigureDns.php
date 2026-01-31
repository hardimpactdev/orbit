<?php

declare(strict_types=1);

namespace App\Actions\Install\Linux;

use App\Data\Install\InstallContext;
use HardImpact\Orbit\Core\Data\StepResult;
use App\Services\Install\InstallLogger;
use App\Services\PlatformService;

final readonly class ConfigureDns
{
    public function __construct(
        private PlatformService $platformService,
    ) {}

    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        $tld = $context->tld;
        $dnsStatus = $this->platformService->getDnsStatus($tld);

        // Orbit DNS is already running
        if (($dnsStatus['status'] ?? '') === 'orbit_dns_running') {
            $logger->skip('DNS already configured (orbit-dns running)');

            return StepResult::success();
        }

        // On Linux with systemd-resolved conflict, configure it
        if (($dnsStatus['status'] ?? '') === 'systemd_resolved_conflict') {
            $logger->step('Configuring systemd-resolved for Docker DNS...');

            $result = $this->platformService->configureSystemdResolved();
            if (! $result) {
                $logger->warn('Failed to configure systemd-resolved - DNS may not work correctly');

                return StepResult::success(); // Non-critical, continue anyway
            }

            // Also update resolv.conf
            $this->platformService->configureResolvConf();

            $logger->success('systemd-resolved configured');

            return StepResult::success();
        }

        // Port 53 in use by something else (not orbit-dns)
        if (($dnsStatus['status'] ?? '') === 'port_53_conflict') {
            $logger->warn('Port 53 is in use by another process - Docker DNS may not work');
            $logger->info('Please free port 53 or configure your system DNS to point to 127.0.0.1');

            return StepResult::success(); // Non-critical, continue anyway
        }

        $logger->success('DNS configuration ready');

        return StepResult::success();
    }
}
