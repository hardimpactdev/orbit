<?php

declare(strict_types=1);

namespace App\Commands\Firewall;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;

use function Laravel\Prompts\table;

final class FirewallListCommand extends GatewayCommand
{
    use ResolvesHostContext;

    #[\Override]
    protected $signature = 'firewall:list
        {--node= : Filter by node name}
        {--json}';

    #[\Override]
    protected $description = 'List firewall rules tracked by gateway intent.';

    public function handle(): int
    {
        $node = $this->stringOption('node');

        if ($node !== null && str_contains($node, ',')) {
            return $this->renderFailure(
                'validation_failed',
                'The selected node is not a firewall target.',
                [
                    'field' => 'node',
                    'node' => $node,
                ],
            );
        }

        try {
            $response = $this->gatewayGet('/api/firewall-rules', $this->filledQuery([
                'node' => $node,
            ]));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $rules = $this->rulesFromGatewayResponse($response);

        if ($rules === []) {
            $this->line($this->emptyState($node));

            return self::SUCCESS;
        }

        $this->renderRuleTables($rules);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<array<string, mixed>>
     */
    private function rulesFromGatewayResponse(array $response): array
    {
        $rules = $response['success']['data']['rules'] ?? null;

        if (! is_array($rules)) {
            return [];
        }

        return array_values(array_filter($rules, is_array(...)));
    }

    private function emptyState(?string $node): string
    {
        if ($node !== null) {
            return "No firewall rules found on node {$node}.";
        }

        return 'No firewall rules found.';
    }

    /**
     * @param  list<array<string, mixed>>  $rules
     */
    private function renderRuleTables(array $rules): void
    {
        $first = true;

        foreach ($this->rulesGroupedByNode($rules) as $node => $nodeRules) {
            if (! $first) {
                $this->newLine();
            }

            $first = false;

            $this->line("Node: {$node}");

            table(
                headers: ['NAME', 'DIRECTION', 'ACTION', 'SOURCE', 'DESTINATION', 'PORT', 'PROTOCOL', 'STATUS'],
                rows: array_map(fn (array $rule): array => [
                    $this->ruleString($rule, 'name'),
                    $this->ruleString($rule, 'direction'),
                    $this->ruleString($rule, 'action'),
                    $this->ruleString($rule, 'source'),
                    $this->ruleString($rule, 'destination'),
                    $this->ruleString($rule, 'port'),
                    $this->ruleString($rule, 'protocol'),
                    $this->ruleString($rule, 'status'),
                ], $nodeRules),
            );
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rules
     * @return array<string, list<array<string, mixed>>>
     */
    private function rulesGroupedByNode(array $rules): array
    {
        $grouped = [];

        foreach ($rules as $rule) {
            $grouped[$this->ruleString($rule, 'node')][] = $rule;
        }

        ksort($grouped);

        return $grouped;
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function ruleString(array $rule, string $key): string
    {
        $value = $rule[$key] ?? null;

        if (is_scalar($value) && (string) $value !== '') {
            return (string) $value;
        }

        return '—';
    }
}
