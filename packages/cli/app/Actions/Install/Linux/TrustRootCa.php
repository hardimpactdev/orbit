<?php

declare(strict_types=1);

namespace App\Actions\Install\Linux;

use App\Data\Install\InstallContext;
use HardImpact\Orbit\Core\Data\StepResult;
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

        // Use host-based Caddy certificate path
        $certPath = $_SERVER['HOME'].'/.local/share/caddy/pki/authorities/local/root.crt';

        if (! file_exists($certPath)) {
            $logger->warn('Caddy root certificate not found - certificates may not be trusted');
            $logger->info('Certificate expected at: '.$certPath);
            $logger->info('Try running Caddy first to generate certificates');

            return StepResult::success();
        }

        // On Linux, add to system trust store
        $trustResult = $this->addToLinuxTrustStore($certPath, $logger);

        if ($trustResult) {
            $logger->success('Certificate trusted');
        }

        return StepResult::success();
    }

    private function addToLinuxTrustStore(string $certPath, InstallLogger $logger): bool
    {
        $logger->step('Adding to Linux trust store (sudo authorization required)...');

        // Try update-ca-certificates (Debian/Ubuntu)
        if (file_exists('/usr/local/share/ca-certificates')) {
            $result = Process::run(
                "sudo cp \"{$certPath}\" /usr/local/share/ca-certificates/orbit-caddy-root.crt && sudo update-ca-certificates"
            );

            if ($result->successful()) {
                return true;
            }
        }

        // Try trust anchor (Fedora/RHEL)
        if (file_exists('/etc/pki/ca-trust/source/anchors')) {
            $result = Process::run(
                "sudo cp \"{$certPath}\" /etc/pki/ca-trust/source/anchors/orbit-caddy-root.crt && sudo update-ca-trust"
            );

            if ($result->successful()) {
                return true;
            }
        }

        $logger->warn('Could not automatically trust certificate - continuing installation');
        $logger->info('You may need to manually trust the certificate or run with sudo authorization');
        $logger->info('Certificate located at: '.$certPath);

        return false;
    }
}
