<?php

declare(strict_types=1);

namespace App\Actions\Install\Mac;

use App\Data\Install\InstallContext;
use App\Data\Provision\StepResult;
use App\Services\Install\InstallLogger;
use App\Services\PlatformService;
use Illuminate\Support\Facades\Process;

final readonly class InstallHomebrew
{
    public function __construct(
        private PlatformService $platformService,
    ) {}

    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        if ($this->platformService->commandExists('brew')) {
            $brewPath = $this->platformService->getCommandOutput('command -v brew');
            $logger->skip("Homebrew already installed at {$brewPath}");

            return StepResult::success();
        }

        $logger->step('Installing Homebrew...');

        $result = Process::timeout(600)->run(
            '/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"'
        );

        if (! $result->successful()) {
            return StepResult::failed('Failed to install Homebrew: '.$result->errorOutput());
        }

        $logger->success('Homebrew installed');

        return StepResult::success();
    }
}
