<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Firewall\ListFirewallRulesRequest;
use App\Http\Gateway\Responses\Firewall\FirewallRuleListResponse;
use App\Services\Firewall\FirewallRuleQuery;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

use function Laravel\Prompts\table;

#[Signature('firewall:list
    {--node= : Filter by node name}
    {--json : Output JSON}')]
#[Description('List firewall rules tracked by gateway intent')]
class FirewallListCommand extends Command
{
    public function handle(FirewallRuleQuery $rules): int
    {
        $node = $this->stringOption('node');

        if ($node !== null && str_contains($node, ',')) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'The selected node is not a firewall target.',
                meta: [
                    'field' => 'node',
                    'node' => $node,
                ],
            );
        }

        try {
            $result = $this->fetchRules($rules, $node);
        } catch (GatewayApiException $e) {
            return $this->failCommand(
                code: $e->errorCode() ?? 'gateway_unavailable',
                message: $e->getMessage() !== '' ? $e->getMessage() : 'Gateway connection is required to list firewall rules.',
                meta: $e->errorMeta(),
            );
        } catch (Throwable) {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway connection is required to list firewall rules.',
                meta: [],
            );
        }

        if ($this->wantsJson()) {
            return $this->jsonSuccess(['rules' => $result['rules']], $result['meta']);
        }

        $this->renderHuman($result['rules'], $result['meta']);

        return self::SUCCESS;
    }

    /**
     * @return array{
     *     rules: list<array<string, mixed>>,
     *     meta: array<string, mixed>,
     * }
     */
    private function fetchRules(FirewallRuleQuery $rules, ?string $node): array
    {
        if ((bool) config('orbit.is_gateway', false)) {
            return $rules->list(node: $node);
        }

        /** @var FirewallRuleListResponse $dto */
        $dto = app(GatewayConnector::class)
            ->send(new ListFirewallRulesRequest(node: $node))
            ->dto();

        return [
            'rules' => $dto->rules,
            'meta' => $dto->meta,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rules
     * @param  array<string, mixed>  $meta
     */
    private function renderHuman(array $rules, array $meta): void
    {
        if ($rules === []) {
            $node = is_string($meta['node'] ?? null) ? $meta['node'] : 'all nodes';

            $this->line("No firewall rules found for {$node}.");

            return;
        }

        $currentNode = (string) ($rules[0]['node'] ?? 'without node');
        $rows = [];

        foreach ($rules as $rule) {
            $node = (string) ($rule['node'] ?? 'without node');

            if ($node !== $currentNode) {
                $this->renderNodeTable($currentNode, $rows);
                $this->newLine();
                $rows = [];
            }

            $currentNode = $node;
            $rows[] = [
                $rule['name'] ?? '',
                $rule['direction'] ?? '',
                $rule['action'] ?? '',
                $rule['source'] ?? '',
                $rule['destination'] ?? '-',
                $rule['port'] ?? '',
                $rule['protocol'] ?? '',
                $rule['address_family'] ?? '',
                $rule['interface'] ?? '-',
                ($rule['protected'] ?? false) === true ? 'protected' : (string) ($rule['owner'] ?? 'user'),
                $rule['reason'] ?? '-',
                $rule['status'] ?? '',
            ];
        }

        $this->renderNodeTable($currentNode, $rows);
    }

    /**
     * @param  list<array<int, mixed>>  $rows
     */
    private function renderNodeTable(string $node, array $rows): void
    {
        $this->line("Node: {$node}");
        table(['Name', 'Direction', 'Action', 'Source', 'Destination', 'Port', 'Protocol', 'Family', 'Interface', 'Owner', 'Reason', 'Status'], $rows);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    private function jsonSuccess(array $data, array $meta): int
    {
        $this->line(json_encode([
            'success' => [
                'data' => $data,
                'meta' => $meta,
            ],
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function failCommand(string $code, string $message, array $meta): int
    {
        if ($this->wantsJson()) {
            $this->line(json_encode([
                'error' => [
                    'code' => $code,
                    'message' => $message,
                    'meta' => empty($meta) ? (object) [] : $meta,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function wantsJson(): bool
    {
        return $this->option('json') === true;
    }
}
