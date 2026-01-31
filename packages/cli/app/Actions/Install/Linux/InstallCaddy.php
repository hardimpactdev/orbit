<?php

declare(strict_types=1);

namespace App\Actions\Install\Linux;

use App\Data\Install\InstallContext;
use App\Services\Install\InstallLogger;
use App\Services\PlatformService;
use HardImpact\Orbit\Core\Data\StepResult;
use Illuminate\Support\Facades\Process;

final readonly class InstallCaddy
{
    public function __construct(
        private PlatformService $platformService,
    ) {}

    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        if ($this->platformService->commandExists('caddy')) {
            $logger->skip('Caddy already installed');

            return StepResult::success();
        }

        $logger->step('Installing Caddy...');

        $result = Process::timeout(300)->run(
            'sudo apt install -y debian-keyring debian-archive-keyring apt-transport-https curl && '
            .'curl -1sLf "https://dl.cloudsmith.io/public/caddy/stable/gpg.key" | sudo gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg && '
            .'curl -1sLf "https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt" | sudo tee /etc/apt/sources.list.d/caddy-stable.list && '
            .'sudo apt update && sudo apt install -y caddy'
        );

        if (! $result->successful()) {
            return StepResult::failed('Failed to install Caddy: '.$result->errorOutput());
        }

        $logger->success('Caddy installed');

        return StepResult::success();
    }
}
