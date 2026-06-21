<?php

declare(strict_types=1);

namespace App\Commands\Node;

use App\Commands\Concerns\WithStepTree;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;
use RuntimeException;

final class NodeUpdateCommand extends GatewayCommand
{
    use WithStepTree;

    #[\Override]
    protected $signature = 'node:update
        {name? : Node name to update}
        {--host= : New SSH/bootstrap endpoint}
        {--tld= : Node TLD}
        {--gateway-endpoint= : WireGuard endpoint host this node should use to reach the gateway}
        {--public-ipv4= : Public IPv4 address metadata}
        {--public-ipv6= : Public IPv6 address metadata}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Update node registry metadata and role-owned settings.';

    public function handle(): int
    {
        $name = $this->stringArgument('name');

        if ($name === null) {
            return $this->renderFailure('validation_failed', 'Node name is required.', ['field' => 'name']);
        }

        $payload = array_filter([
            'host' => $this->stringOption('host'),
            'tld' => $this->stringOption('tld'),
            'gateway_endpoint' => $this->stringOption('gateway-endpoint'),
            'public_ipv4' => $this->stringOption('public-ipv4'),
            'public_ipv6' => $this->stringOption('public-ipv6'),
        ], fn (?string $value): bool => $value !== null);

        if ($payload === []) {
            return $this->renderFailure('validation_failed', 'At least one field must be provided to update a node.', ['field' => 'fields']);
        }

        if ($this->wantsJson()) {
            try {
                $response = $this->updateNode($name, $payload);
            } catch (GatewayApiException $exception) {
                return $this->renderGatewayFailure($exception);
            }

            return $this->renderSuccess($response);
        }

        return $this->renderUpdateTree($name, $payload);
    }

    /**
     * @param  array<string, string>  $payload
     */
    private function renderUpdateTree(string $name, array $payload): int
    {
        $response = [];

        $outcome = $this->runStepOperation(
            'Updating Node',
            [
                ['label' => 'Validate node', 'doneLabel' => 'Validated node'],
                ['label' => 'Apply and verify node change', 'doneLabel' => 'Applied node change'],
                ['label' => 'Apply node artifacts', 'doneLabel' => 'Applied node artifacts'],
            ],
            work: function () use ($name, $payload, &$response): array {
                return $response = $this->updateNodeForHuman($name, $payload);
            },
            doneFooter: function () use ($name, &$response): string {
                return $this->footerFor($name, $response);
            },
        );

        if (! $outcome->isCompleted()) {
            return self::FAILURE;
        }

        $this->renderUpdateNotes($response);

        return self::SUCCESS;
    }

    private function footerFor(string $name, array $response): string
    {
        if ($this->changedFields($response) === []) {
            return "Node '{$name}' unchanged";
        }

        if ($this->driftIsPresent($response)) {
            return "Node '{$name}' updated with drift";
        }

        return "Node '{$name}' updated";
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function renderUpdateNotes(array $response): void
    {
        $changed = $this->changedFields($response);

        if ($changed === []) {
            $this->line('  No fields were modified.');

            return;
        }

        $this->line('  Changed: '.implode(', ', $changed));

        foreach ($this->driftMessages($response) as $message) {
            $this->line("  Drift detected: {$message}");
            $this->line('  Run: orbit doctor --family=node --restore');
        }
    }

    /**
     * @param  array<string, string>  $payload
     * @return array<string, mixed>
     */
    private function updateNode(string $name, array $payload): array
    {
        return $this->gatewayPut('/api/nodes/'.rawurlencode($name), $payload);
    }

    /**
     * Run the update inside the progress tree, re-throwing gateway failures with
     * their operator-facing message so the failed footer renders the documented
     * prose rather than a JSON envelope.
     *
     * @param  array<string, string>  $payload
     * @return array<string, mixed>
     */
    private function updateNodeForHuman(string $name, array $payload): array
    {
        try {
            return $this->updateNode($name, $payload);
        } catch (GatewayApiException $exception) {
            throw new RuntimeException(
                $exception->gatewayErrorMessage() ?? $exception->getMessage(),
                previous: $exception,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<string>
     */
    private function changedFields(array $response): array
    {
        $changed = $this->successData($response)['changed'] ?? null;

        if (! is_array($changed)) {
            return [];
        }

        $fields = [];

        foreach ($changed as $field) {
            if (is_string($field) && $field !== '') {
                $fields[] = $field;
            }
        }

        return $fields;
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
        $warnings = $response['success']['meta']['warnings'] ?? null;

        if (! is_array($warnings)) {
            return [];
        }

        $messages = [];

        foreach ($warnings as $entry) {
            if (is_array($entry) && is_string($entry['message'] ?? null) && trim($entry['message']) !== '') {
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

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
