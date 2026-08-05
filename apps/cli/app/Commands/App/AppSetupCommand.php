<?php

declare(strict_types=1);

namespace App\Commands\App;

use Orbit\Core\Progress\ProgressEventType;

final class AppSetupCommand extends AppGatewayCommand
{
    #[\Override]
    protected $signature = 'instance:setup
        {instance? : Instance selector (app.instance or hostname)}
        {--json : Output JSON}
        {--stream-json : Stream newline-delimited JSON progress frames}';

    #[\Override]
    protected $description = 'Run configured setup steps for an instance.';

    public function handle(): int
    {
        $app = $this->stringArgument('instance') ?? $this->instanceFromOrbitMarker();

        if ($app === null) {
            return $this->failValidation('instance', 'Instance is required.');
        }

        return $this->streamProgress(
            $this->apiInstancePath($app, '/setup'),
            [],
            fn (ProgressEventType $type, array $payload): int => $this->renderProgressTerminalFrame($type, $payload),
        );
    }
}
