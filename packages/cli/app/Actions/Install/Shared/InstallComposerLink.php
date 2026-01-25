<?php

declare(strict_types=1);

namespace App\Actions\Install\Shared;

use App\Data\Install\InstallContext;
use App\Data\Provision\StepResult;
use App\Services\Install\InstallLogger;
use Illuminate\Support\Facades\Process;

final readonly class InstallComposerLink
{
    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        // Check if already installed
        $checkResult = Process::run('composer global show sandersander/composer-link 2>/dev/null');
        if ($checkResult->successful()) {
            $logger->skip('composer-link already installed');

            return StepResult::success();
        }

        $result = Process::timeout(120)->run(
            'composer global config --no-plugins allow-plugins.sandersander/composer-link true && composer global require sandersander/composer-link --quiet'
        );

        if (! $result->successful()) {
            $logger->warn('Failed to install composer-link - package development linking may not work');

            return StepResult::success(); // Non-critical
        }

        $logger->success('composer-link installed');

        return StepResult::success();
    }
}
