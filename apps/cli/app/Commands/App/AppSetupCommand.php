<?php

declare(strict_types=1);

namespace App\Commands\App;

use Orbit\Core\Progress\ProgressEventType;

final class AppSetupCommand extends AppGatewayCommand
{
    #[\Override]
    protected $signature = 'app:setup
        {app? : App name or hostname}
        {--json : Output JSON}
        {--stream-json : Stream newline-delimited JSON progress frames}';

    #[\Override]
    protected $description = 'Run configured setup steps for an app.';

    public function handle(): int
    {
        $app = $this->stringArgument('app') ?? $this->appFromOrbitMarker();

        if ($app === null) {
            return $this->failValidation('app', 'App is required.');
        }

        return $this->streamProgress(
            $this->apiAppPath($app, '/setup'),
            [],
            fn (ProgressEventType $type, array $payload): int => $this->renderProgressTerminalFrame($type, $payload),
        );
    }
}
