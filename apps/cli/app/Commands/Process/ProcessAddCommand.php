<?php

declare(strict_types=1);

namespace App\Commands\Process;

use App\Exceptions\GatewayApiException;

final class ProcessAddCommand extends ProcessGatewayCommand
{
    protected $signature = 'process:add
        {name? : Process name}
        {processCommand? : Command to run}
        {--app= : Parent app slug}
        {--restart-policy=never : Restart policy (never|on_failure|always)}
        {--crash-notification=none : Crash notification policy (none|agent_ide)}
        {--runtime= : Process runtime (docker|supervisor); defaults to docker for PHP apps and supervisor for non-PHP apps}
        {--start : Start rendered runtime units after creation}
        {--json : Output JSON}';

    protected $description = 'Add an app process definition.';

    public function handle(): int
    {
        $app = $this->appContext();
        $name = $this->stringArgument('name');
        $command = $this->stringArgument('processCommand');
        $restartPolicy = $this->stringOption('restart-policy') ?? 'never';
        $crashNotification = $this->stringOption('crash-notification') ?? 'none';
        $runtime = $this->stringOption('runtime');

        if ($app === null) {
            return $this->failValidation('app', 'An app context is required.');
        }

        $validation = $this->validateProcessName($name)
            ?? ($command === null ? $this->failValidation('command', 'The process command is required.') : null)
            ?? $this->validateRestartPolicy($restartPolicy)
            ?? $this->validateCrashNotification($crashNotification)
            ?? $this->validateRuntime($runtime);

        if ($validation !== null) {
            return $validation;
        }

        try {
            $response = $this->gatewayPost('/api/processes', $this->filledQuery([
                'app' => $app,
                'name' => $name,
                'command' => $command,
                'restart_policy' => $restartPolicy,
                'crash_notification' => $crashNotification,
                'start' => $this->option('start') === true,
                'runtime' => $runtime,
            ]));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }
}
