<?php

declare(strict_types=1);

namespace App\Commands\Firewall;

use App\Commands\Concerns\WithStepTree;
use App\Exceptions\GatewayApiException;
use RuntimeException;

abstract class FirewallStoreCommand extends FirewallGatewayCommand
{
    use WithStepTree;

    abstract protected function firewallAction(): string;

    abstract protected function treeTitle(): string;

    public function handle(): int
    {
        $name = $this->requiredArgument('name', 'The firewall rule name is required.');

        if (is_int($name)) {
            return $name;
        }

        $node = $this->firewallTargetNode();

        if (is_int($node)) {
            return $node;
        }

        $port = $this->stringOption('port');

        if ($port === null) {
            if (! $this->wantsJson() && $this->input->isInteractive()) {
                $answer = $this->ask('Port');
                $port = is_string($answer) && trim($answer) !== '' ? trim($answer) : null;
            }
        }

        if ($port === null) {
            return $this->failValidation('port', 'The firewall rule port is required.');
        }

        $payload = $this->filledQuery([
            'action' => $this->firewallAction(),
            'name' => $name,
            'node' => $node,
            'direction' => $this->stringOption('direction') ?? 'incoming',
            'source' => $this->stringOption('from') ?? 'any',
            'destination' => $this->stringOption('to'),
            'port' => $port,
            'protocol' => $this->stringOption('protocol') ?? 'tcp',
            'reason' => $this->stringOption('reason'),
        ]);

        if ($this->wantsJson()) {
            try {
                $response = $this->gatewayPost('/api/firewall-rules', $payload);
            } catch (GatewayApiException $exception) {
                return $this->renderGatewayFailure($exception);
            }

            return $this->renderSuccess($response);
        }

        return $this->renderStoreTree($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderStoreTree(array $payload): int
    {
        $response = [];

        $result = $this->runStepOperation(
            $this->treeTitle(),
            [
                ['label' => 'Validate firewall target'],
                ['label' => 'Check baseline policy boundary'],
                ['label' => 'Apply and verify firewall rule'],
            ],
            work: function () use ($payload, &$response): void {
                $response = $this->storeForHuman($payload);
            },
            doneFooter: function () use (&$response): string {
                return $this->storeFooter($response);
            },
        );

        if (! $result->isCompleted()) {
            return self::FAILURE;
        }

        $this->renderRuleSummary($response);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function storeForHuman(array $payload): array
    {
        try {
            return $this->gatewayPost('/api/firewall-rules', $payload);
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
    private function storeFooter(array $response): string
    {
        $rule = $this->rule($response);
        $name = $this->ruleField($rule, 'name');
        $node = $this->ruleField($rule, 'node');

        return "{$this->firewallAction()} rule '{$name}' applied on {$node}";
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function renderRuleSummary(array $response): void
    {
        $rule = $this->rule($response);

        $lines = [
            'Rule' => $this->ruleField($rule, 'name'),
            'Node' => $this->ruleField($rule, 'node'),
            'Action' => $this->ruleField($rule, 'action'),
            'Direction' => $this->ruleField($rule, 'direction'),
            'Source' => $this->ruleField($rule, 'source'),
        ];

        $destination = $rule['destination'] ?? null;
        if (is_scalar($destination) && (string) $destination !== '') {
            $lines['Destination'] = (string) $destination;
        }

        $lines['Port'] = $this->ruleField($rule, 'port');
        $lines['Protocol'] = $this->ruleField($rule, 'protocol');
        $lines['Backend apply'] = $this->backendApply($response);

        foreach ($lines as $label => $value) {
            $this->line("  {$label}: {$value}");
        }
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function rule(array $response): array
    {
        $rule = $response['success']['data']['rule'] ?? null;

        return is_array($rule) ? $rule : [];
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function ruleField(array $rule, string $key): string
    {
        $value = $rule[$key] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : '—';
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function backendApply(array $response): string
    {
        $meta = $response['success']['meta'] ?? null;
        $enacted = is_array($meta) ? ($meta['backend_enacted'] ?? null) : null;

        if ($enacted === true) {
            return 'enacted';
        }

        $status = $this->ruleField($this->rule($response), 'status');

        return $status === '—' ? 'pending' : $status;
    }
}
