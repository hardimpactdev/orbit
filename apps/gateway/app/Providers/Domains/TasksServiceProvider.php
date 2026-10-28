<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Actions\Tasks\InstallSwampReaderCredentialAction;
use App\Domain\Tasks\SwampReaderAssistance;
use App\Infrastructure\AgentView\StreamWebSocketClient;
use App\Infrastructure\GatewayReleases\GatewayReleaseLock;
use App\Infrastructure\Tasks\SwampReaderEnvironment;
use Illuminate\Support\ServiceProvider;

final class TasksServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(InstallSwampReaderCredentialAction::class, fn ($app): InstallSwampReaderCredentialAction => new InstallSwampReaderCredentialAction(
            $app->make(GatewayReleaseLock::class),
            $app->make(SwampReaderEnvironment::class),
            new StreamWebSocketClient(2_097_152, 2_097_152),
            $app->make(SwampReaderAssistance::class),
        ));
    }
}
