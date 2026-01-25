<?php

declare(strict_types=1);

namespace App\Actions\Provision;

use App\Data\Provision\ProvisionContext;
use App\Data\Provision\StepResult;
use App\Services\ProvisionLogger;
use Illuminate\Support\Facades\Process;

final readonly class RunPostInstallScripts
{
    public function handle(ProvisionContext $context, ProvisionLogger $logger): StepResult
    {
        $composerPath = "{$context->projectPath}/composer.json";

        if (! file_exists($composerPath)) {
            return StepResult::success();
        }

        $composerJson = json_decode(file_get_contents($composerPath), true);
        $hasScripts = isset($composerJson['scripts']['post-autoload-dump'])
            || isset($composerJson['scripts']['post-install-cmd']);

        if (! $hasScripts) {
            $logger->log('No post-install scripts found in composer.json');

            return StepResult::success();
        }

        $logger->info('Running post-install scripts...');

        $result = Process::path($context->projectPath)
            ->env($context->getPhpEnv())
            ->timeout(300)
            ->run('composer run-script post-autoload-dump 2>/dev/null || true');

        $logger->log("Post-install scripts completed (exit: {$result->exitCode()})");
        $logger->info('Post-install scripts completed');

        return StepResult::success();
    }
}
