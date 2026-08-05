<?php

declare(strict_types=1);

namespace App\Commands\Process;

use App\Exceptions\GatewayApiException;

abstract class ProcessRuntimeActionCommand extends ProcessGatewayCommand
{
    abstract protected function action(): string;

    /**
     * Title for the human progress tree, e.g. "Starting Processes".
     */
    abstract protected function treeTitle(): string;

    /**
     * Past-tense verb used in the success footer, e.g. "started".
     */
    abstract protected function pastTense(): string;

    public function handle(): int
    {
        $appHostname = $this->appHostnameContext();
        $node = $this->nodeContext();
        $instance =
            $node === null && $appHostname === null
                ? $this->appContext()
                : $this->stringOption('instance');
        $workspace = $this->workspaceContext();

        $conflictFailure = $this->rejectConflictingProcessSelectors(
            appHostname: $appHostname,
            node: $node,
            instance: $instance,
            workspace: $workspace,
        );

        if ($conflictFailure !== null) {
            return $conflictFailure;
        }

        $missingFailure = $this->requireProcessSelector(
            appHostname: $appHostname,
            node: $node,
            instance: $instance,
            workspace: $workspace,
        );

        if ($missingFailure !== null) {
            return $missingFailure;
        }

        $name = $this->stringArgument('name');
        $path = "/api/processes/{$this->action()}";
        $payload = $this->filledQuery([
            'app' => $appHostname,
            'node' => $node,
            'instance' => $instance,
            'workspace' => $workspace,
            'name' => $name,
        ]);
        $label = $appHostname !== null
            ? "app '{$appHostname}'"
            : $this->contextLabel($node, $instance, $workspace);

        if ($this->wantsJson()) {
            try {
                $response = $this->gatewayPost($path, $payload);
            } catch (GatewayApiException $exception) {
                return $this->renderGatewayFailure($exception);
            }

            return $this->renderSuccess($response);
        }

        return $this->renderRuntimeActionTree($path, $payload, $name, $label);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderRuntimeActionTree(string $path, array $payload, ?string $name, string $label): int
    {
        $response = [];
        $verb = ucfirst($this->action());

        $outcome = $this->runStepOperation(
            $this->treeTitle(),
            [
                ['label' => 'Resolve runtime units', 'doneLabel' => 'Resolved runtime units'],
                ['label' => "{$verb} runtime units", 'doneLabel' => ucfirst($this->pastTense()).' runtime units'],
                ['label' => 'Record process events', 'doneLabel' => 'Recorded process events'],
            ],
            work: function () use ($path, $payload, &$response): array {
                return $response = $this->gatewayCallForHuman(fn (): array => $this->gatewayPost($path, $payload));
            },
            doneFooter: function () use (&$response, $name, $label): string {
                return $this->successFooter($response, $name, $label);
            },
        );

        return $outcome->isCompleted() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Documented success prose: a named process reads as a single line; an
     * omitted name reads as a count of the affected runtimes.
     *
     * @param  array<string, mixed>  $response
     */
    private function successFooter(array $response, ?string $name, string $label): string
    {
        if ($name !== null) {
            return "Process '{$name}' {$this->pastTense()} for {$label}";
        }

        $runtimes = $this->successData($response)['runtimes'] ?? null;
        $count = is_array($runtimes) ? count($runtimes) : 0;
        $noun = $count === 1 ? 'process' : 'processes';

        return "{$count} {$noun} {$this->pastTense()} for {$label}";
    }
}
