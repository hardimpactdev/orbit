<?php

declare(strict_types=1);

namespace App\Commands\Process;

use App\Exceptions\GatewayApiException;

class ProcessUpdateCommand extends ProcessGatewayCommand
{
    #[\Override]
    protected $signature = 'process:update
        {name? : Existing process name}
        {--name= : New process name}
        {--label= : Human display label}
        {--node= : Owning node name}
        {--instance= : Instance selector}
        {--workspace= : Workspace name}
        {--command= : New command}
        {--restart-policy= : Restart policy (never|on_failure|always)}
        {--crash-notification= : Crash notification policy (none)}
        {--runtime= : Process runtime (docker|docker-swarm|systemd|launchd)}
        {--bind=* : Publish host for node-owned Docker managed services (wireguard|loopback); repeatable}
        {--restart : Restart affected runtime units after update}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Update a process definition.';

    public function handle(): int
    {
        $node = $this->nodeContext();
        $label = $this->resolveProcessLabelOption();

        if (is_int($label)) {
            return $label;
        }

        $input = ProcessUpdateInput::fromValues([
            'node' => $node,
            'instance' => $node === null ? $this->appContext() : $this->stringOption('instance'),
            'workspace' => $this->workspaceContext(),
            'name' => $this->stringArgument('name'),
            'new_name' => $this->stringOption('name'),
            'label' => $label,
            'command' => $this->stringOption('command'),
            'restart_policy' => $this->stringOption('restart-policy'),
            'crash_notification' => $this->stringOption('crash-notification'),
            'runtime' => $this->stringOption('runtime'),
            'binds' => ProcessBindOption::fromOption($this->option('bind')),
            'restart' => $this->option('restart') === true,
        ]);
        $validation = new ProcessUpdateValidator()->validate($input);

        if ($validation !== null) {
            return $this->failValidation($validation->field, $validation->message, $validation->meta);
        }

        $path = '/api/processes/'.rawurlencode((string) $input->name);
        $payload = $input->payload();

        if ($this->wantsJson()) {
            try {
                $response = $this->gatewayPatch($path, $payload);
            } catch (GatewayApiException $exception) {
                return $this->renderGatewayFailure($exception);
            }

            return $this->renderSuccess($response);
        }

        return $this->renderUpdateTree(
            $path,
            $payload,
            (string) $input->name,
            $input->newName,
            $this->contextLabel($input->node, $input->instance, $input->workspace),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderUpdateTree(string $path, array $payload, string $name, ?string $newName, string $label): int
    {
        $response = [];

        $phases = [
            ['label' => 'Validate process', 'doneLabel' => 'Validated process'],
            ['label' => 'Apply and verify process change', 'doneLabel' => 'Applied and verified process change'],
            ['label' => 'Render runtime units', 'doneLabel' => 'Rendered runtime units'],
        ];

        if ($newName !== null && $newName !== $name) {
            $phases[] = ['label' => 'Remove old runtime units', 'doneLabel' => 'Removed old runtime units'];
        }

        if ($this->option('restart') === true) {
            $phases[] = ['label' => 'Restart runtime units', 'doneLabel' => 'Restarted runtime units'];
        }

        $outcome = $this->runStepOperation(
            'Updating Process',
            $phases,
            work: function () use ($path, $payload, &$response): array {
                return $response = $this->gatewayCallForHuman(fn (): array => $this->gatewayPatch($path, $payload));
            },
            doneFooter: static fn (): string => $newName !== null && $newName !== $name
                ? "Process '{$name}' renamed to '{$newName}' for {$label}"
                : "Process '{$name}' updated for {$label}",
        );

        if (! $outcome->isCompleted()) {
            return self::FAILURE;
        }

        $this->renderDriftNotes($response);

        return self::SUCCESS;
    }
}
