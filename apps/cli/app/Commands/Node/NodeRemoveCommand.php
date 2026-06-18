<?php

declare(strict_types=1);

namespace App\Commands\Node;

use App\Commands\Concerns\WithStepTree;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;
use RuntimeException;

final class NodeRemoveCommand extends GatewayCommand
{
    use WithStepTree;

    #[\Override]
    protected $signature = 'node:remove
        {name? : Name of the node to remove}
        {--force : Confirm destructive operation without prompting}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Remove a node from the registry.';

    public function handle(): int
    {
        $name = $this->stringArgument('name');

        if ($name === null) {
            return $this->renderFailure('validation_failed', 'Node name is required.', ['field' => 'name']);
        }

        if (! (bool) $this->option('force')) {
            return $this->renderFailure('validation_failed', 'Use --force to remove this node.', ['field' => 'force']);
        }

        if ($this->wantsJson()) {
            try {
                $response = $this->removeNode($name);
            } catch (GatewayApiException $exception) {
                return $this->renderGatewayFailure($exception);
            }

            return $this->renderSuccess($response);
        }

        return $this->renderRemovalTree($name);
    }

    private function renderRemovalTree(string $name): int
    {
        $response = [];

        $outcome = $this->runStepOperation(
            "Removing node '{$name}'",
            [
                ['label' => 'Validate removal', 'doneLabel' => 'Validated removal'],
                ['label' => 'Remove node grants', 'doneLabel' => 'Removed node grants'],
                ['label' => 'Remove WireGuard peer', 'doneLabel' => 'Removed WireGuard peer'],
                ['label' => 'Remove node record', 'doneLabel' => 'Removed node record'],
            ],
            work: function () use ($name, &$response): array {
                return $response = $this->removeNodeForHuman($name);
            },
            doneFooter: function () use (&$response, $name): string {
                return $this->driftIsPresent($response)
                    ? "Node '{$name}' removed with drift"
                    : "Node '{$name}' removed";
            },
        );

        if (! $outcome->isCompleted()) {
            return self::FAILURE;
        }

        $this->renderRemovalNotes($name, $response);

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function removeNode(string $name): array
    {
        return $this->gatewayDelete('/api/nodes/'.rawurlencode($name), [
            'destructive_consent' => true,
        ]);
    }

    /**
     * Run the destructive call inside the progress tree, re-throwing gateway
     * failures with their operator-facing message so the failed step and footer
     * render the documented prose rather than a JSON envelope.
     *
     * @return array<string, mixed>
     */
    private function removeNodeForHuman(string $name): array
    {
        try {
            return $this->removeNode($name);
        } catch (GatewayApiException $exception) {
            throw new RuntimeException(
                $exception->gatewayErrorMessage() ?? $exception->getMessage(),
                previous: $exception,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function renderRemovalNotes(string $name, array $response): void
    {
        $data = $this->successData($response);

        if (($data['self_removal'] ?? null) === true) {
            $this->line('  This machine no longer has Orbit gateway access.');
        }

        foreach ($this->driftMessages($response) as $message) {
            $this->line("  Drift detected: {$message}");
            $this->line('  Run: orbit doctor --family=node --restore');
        }
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function driftIsPresent(array $response): bool
    {
        return $this->driftMessages($response) !== [];
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<string>
     */
    private function driftMessages(array $response): array
    {
        $data = $this->successData($response);
        $drift = $data['drift'] ?? $data['warnings'] ?? null;

        if (! is_array($drift)) {
            return [];
        }

        $messages = [];

        foreach ($drift as $entry) {
            if (is_string($entry) && trim($entry) !== '') {
                $messages[] = trim($entry);
            } elseif (is_array($entry) && is_string($entry['message'] ?? null) && trim($entry['message']) !== '') {
                $messages[] = trim($entry['message']);
            }
        }

        return $messages;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function successData(array $response): array
    {
        $data = $response['success']['data'] ?? null;

        return is_array($data) ? $data : [];
    }

    private function stringArgument(string $key): ?string
    {
        $value = $this->argument($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
