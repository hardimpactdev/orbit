<?php

declare(strict_types=1);

namespace App\Commands\Firewall;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\Firewall\ListFirewallRulesRequest;
use Orbit\Sdk\Responses\Firewall\FirewallNodeRulesResponse;
use Orbit\Sdk\Responses\Firewall\FirewallRulesResponse;

final class ListFirewallRulesCommand extends FirewallCommand
{
    #[\Override]
    protected $signature = 'firewall:list
        {--node= : Numeric target node ID; omit it for every node you may address}
        {--managed : Add the orbit: rules that Orbit keeps}
        {--live : Compare the rules with the last UFW report of each node agent}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List the firewall rules of one node or of every node you may address.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $nodeId = null;

        if ($this->option('node') !== null) {
            $nodeId = $this->nodeId();

            if ($nodeId === null) {
                return self::FAILURE;
            }
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $managed = $this->option('managed') === true;
        $live = $this->option('live') === true;
        $response = $this->sendWithProgress(
            $connector,
            new ListFirewallRulesRequest($nodeId, $managed, $live),
            FirewallRulesResponse::class,
            ['List firewall rules', 'Loading firewall rules', 'Loaded firewall rules'],
        );

        if (! $response instanceof FirewallRulesResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        $this->renderTable($response, $nodeId === null && count($response->nodes) !== 1, $managed, $live);
        $this->writeHumanMessage("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }

    private function renderTable(FirewallRulesResponse $response, bool $withNode, bool $managed, bool $live): void
    {
        $rows = [];

        foreach ($response->nodes as $node) {
            // While the agent is silent, its Node's live matches may be stale.
            if ($live && $node->agent !== null && $node->agent->state !== 'online') {
                $this->writeHumanMessage("{$node->node}: agent {$node->agent->label()}");
            }

            if ($live && $node->live !== null) {
                $backend = is_string($node->live['backend_status'] ?? null) ? $node->live['backend_status'] : 'unverifiable';
                $observed = is_string($node->live['observed_at'] ?? null) ? ", observed {$node->live['observed_at']}" : '';
                $this->writeHumanMessage("{$node->node}: UFW {$backend}{$observed}");
            }

            foreach ($this->nodeRows($node, $managed, $live) as $row) {
                $rows[] = $withNode ? [$node->node, ...$row] : $row;
            }
        }

        $headers = ['Port', 'Protocol', 'Source', 'Action', 'Label', 'Status', ...($live ? ['Match'] : [])];

        ConsoleWriter::write($this->output, $this->humanRenderer()->table($withNode ? ['Node', ...$headers] : $headers, $rows, 'No firewall rules.'));
    }

    /** @return list<list<string>> The operator rules in UFW order, then Orbit's own rules, then the live rules nothing explains. */
    private function nodeRows(FirewallNodeRulesResponse $node, bool $managed, bool $live): array
    {
        $outdated = $live && $node->possiblyOutdated() ? ' (possibly outdated)' : '';
        $match = static fn (mixed $value): array => $live ? [(is_string($value) ? $value : 'unverifiable').$outdated] : [];
        $text = static fn (mixed $value): string => is_string($value) && $value !== '' ? $value : '—';
        $rows = [];

        foreach ($node->rules as $rule) {
            $rows[] = [$rule->port, $rule->protocol, $rule->source, $rule->action, $rule->label ?? '—', $rule->status, ...$match($rule->match)];
        }

        foreach ($managed ? ($node->managed ?? []) : [] as $rule) {
            $role = is_string($rule['role'] ?? null) ? "role {$rule['role']}" : 'baseline';
            $rows[] = [$text($rule['port'] ?? null), $text($rule['protocol'] ?? null), $text($rule['source'] ?? null), $text($rule['action'] ?? null),
                "{$text($rule['name'] ?? null)} ({$role})", 'locked', ...$match($rule['match'] ?? null)];
        }

        foreach ($live && is_array($node->live['unmatched'] ?? null) ? $node->live['unmatched'] : [] as $rule) {
            if (is_array($rule)) {
                $rows[] = [$text($rule['port'] ?? null), $text($rule['protocol'] ?? null), $text($rule['source'] ?? null), $text($rule['action'] ?? null),
                    $text($rule['comment'] ?? null), 'live only', ...$match($rule['match'] ?? null)];
            }
        }

        return $rows;
    }
}
