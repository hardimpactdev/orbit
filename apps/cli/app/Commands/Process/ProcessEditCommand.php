<?php

declare(strict_types=1);

namespace App\Commands\Process;

use App\Exceptions\GatewayApiException;

final class ProcessEditCommand extends ProcessGatewayCommand
{
    protected $signature = 'process:edit
        {name? : Existing process name}
        {--app= : Parent app slug}
        {--command= : New command}
        {--restart-policy= : Restart policy (never|on_failure|always)}
        {--crash-notification= : Crash notification policy (none|agent_ide)}
        {--runtime= : Process runtime (docker|supervisor)}
        {--restart : Restart affected runtime units after update}
        {--json : Output JSON}';

    protected $description = 'Edit an app process definition.';

    public function handle(): int
    {
        $app = $this->appContext();
        $name = $this->stringArgument('name');
        $command = $this->stringOption('command');
        $restartPolicy = $this->stringOption('restart-policy');
        $crashNotification = $this->stringOption('crash-notification');
        $runtime = $this->stringOption('runtime');

        if ($app === null) {
            return $this->failValidation('app', 'An app context is required.');
        }

        $validation = $this->validateProcessName($name)
            ?? $this->validateEditableFields($command, $restartPolicy, $crashNotification, $runtime)
            ?? $this->validateRestartPolicy($restartPolicy)
            ?? $this->validateCrashNotification($crashNotification)
            ?? $this->validateRuntime($runtime);

        if ($validation !== null) {
            return $validation;
        }

        try {
            $response = $this->gatewayPatch('/api/processes/'.rawurlencode((string) $name), $this->filledQuery([
                'app' => $app,
                'command' => $command,
                'restart_policy' => $restartPolicy,
                'crash_notification' => $crashNotification,
                'runtime' => $runtime,
                'restart' => $this->option('restart') === true,
            ]));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }

    private function validateEditableFields(?string $command, ?string $restartPolicy, ?string $crashNotification, ?string $runtime): ?int
    {
        if ($command !== null || $restartPolicy !== null || $crashNotification !== null || $runtime !== null) {
            return null;
        }

        return $this->failValidation('editable_fields', 'At least one editable field is required.');
    }
}
