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

        // Use host-based Caddy certificate path
        $certPath = $_SERVER['HOME'].'/Library/Application Support/Caddy/pki/authorities/local/root.crt';

        if (! file_exists($certPath)) {
            $logger->warn('Caddy root certificate not found - certificates may not be trusted');
            $logger->info('Certificate expected at: '.$certPath);
            $logger->info('Try running Caddy first to generate certificates');

            return StepResult::success();
        }

        // Add to macOS Keychain
        $logger->step('Adding to macOS Keychain (sudo authorization required)...');

        $trustResult = Process::run(
            "sudo security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain \"{$certPath}\""
        );

        if (! $trustResult->successful()) {
            $logger->warn('Failed to add certificate to Keychain - continuing installation');
            $logger->info('You may need to manually trust the certificate or run with sudo authorization');

            return StepResult::success();
        }

        $logger->success('Certificate trusted');
        $logger->info('You may need to restart your browser');

        return StepResult::success();
    }
}
