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
        $node = $this->nodeContext();
        $app = $node === null ? $this->appContext() : $this->stringOption('app');
        $workspace = $this->workspaceContext();

        if ($node !== null && ($app !== null || $workspace !== null)) {
            return $this->failValidation('context', 'A node context cannot be combined with app or workspace context.', [
                'node' => $node,
                'app' => $app,
                'workspace' => $workspace,
            ]);
        }

        if ($node === null && $app === null && $workspace === null) {
            return $this->failValidation('app', 'A node, app, or workspace context is required.');
        }

        $name = $this->stringArgument('name');
        $path = "/api/processes/{$this->action()}";
        $payload = $this->filledQuery([
            'node' => $node,
            'app' => $app,
            'workspace' => $workspace,
            'name' => $name,
        ]);

        if ($this->wantsJson()) {
            try {
                $response = $this->gatewayPost($path, $payload);
            } catch (GatewayApiException $exception) {
                return $this->renderGatewayFailure($exception);
            }

            return $this->renderSuccess($response);
        }

        return $this->renderRuntimeActionTree($path, $payload, $name, $this->contextLabel($node, $app, $workspace));
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
