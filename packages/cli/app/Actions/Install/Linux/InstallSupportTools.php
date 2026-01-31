<?php

declare(strict_types=1);

namespace App\Actions\Install\Linux;

use App\Data\Install\InstallContext;
use HardImpact\Orbit\Core\Data\StepResult;
use App\Services\Install\InstallLogger;
use App\Services\PlatformService;
use Illuminate\Support\Facades\Process;

final readonly class InstallSupportTools
{
    public function __construct(
        private PlatformService $platformService,
    ) {}

    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        // Install Bun if missing
        if (! $this->platformService->commandExists('bun')) {
            $logger->step('Installing Bun...');
            $result = Process::timeout(300)->run('curl -fsSL https://bun.sh/install | bash');
            if (! $result->successful()) {
                $logger->warn('Failed to install Bun - you may need to install it manually');
            } else {
                $logger->success('Bun installed');
            }
        } else {
            $logger->skip('Bun already installed');
        }

        // Install Composer if missing
        if (! $this->platformService->commandExists('composer')) {
            $logger->step('Installing Composer...');
            $result = Process::timeout(300)->run(
                'php -r "copy(\'https://getcomposer.org/installer\', \'composer-setup.php\');" && '
                .'sudo php composer-setup.php --install-dir=/usr/local/bin --filename=composer && '
                .'php -r "unlink(\'composer-setup.php\');"'
            );
            if (! $result->successful()) {
                $logger->warn('Failed to install Composer - you may need to install it manually');
            } else {
                $logger->success('Composer installed');
            }
        } else {
            $logger->skip('Composer already installed');
        }

        return StepResult::success();
    }
}
