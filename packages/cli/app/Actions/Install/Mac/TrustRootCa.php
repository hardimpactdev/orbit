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

        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: '/tmp';
        $certPath = $home . '/Library/Application Support/Caddy/pki/authorities/local/root.crt';

        if (! file_exists($certPath)) {
            $logger->warn('Caddy root certificate not found - certificates may not be trusted');
            $logger->info('Certificate expected at: ' . $certPath);
            $logger->info('Try running Caddy first to generate certificates');

            return StepResult::success();
        }

        $logger->step('Trusting Caddy root certificate (authorization required)...');
        $logger->info('A system dialog may appear - please authorize to trust the certificate');

        $trustResult = Process::timeout(60)->run('caddy trust');

        if (! $trustResult->successful()) {
            if (str_contains($trustResult->errorOutput(), 'already')) {
                $logger->success('Certificate already trusted');
                return StepResult::success();
            }

            $logger->warn('Could not automatically trust certificate');
            $logger->info('Please manually trust the certificate:');
            $logger->info('1. Open Keychain Access');
            $logger->info('2. Select System keychain');
            $logger->info('3. Find "Caddy Local Authority" certificate');
            $logger->info('4. Double-click -> Trust -> Always Trust');

            return StepResult::success();
        }

        $logger->success('Certificate trusted');
        $logger->info('You may need to restart your browser');

        return StepResult::success();
    }
}
