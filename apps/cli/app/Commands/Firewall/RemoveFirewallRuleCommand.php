<?php

declare(strict_types=1);

namespace App\Commands\Firewall;

use App\Commands\Concerns\FollowsOperations;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Firewall\ListFirewallRulesRequest;
use Orbit\Sdk\Requests\Firewall\RemoveFirewallRuleRequest;
use Orbit\Sdk\Responses\Firewall\FirewallRuleResponse;
use Orbit\Sdk\Responses\Firewall\FirewallRulesResponse;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class RemoveFirewallRuleCommand extends FirewallCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'firewall:remove
        {name : Stable firewall rule name}
        {--node= : Numeric target node ID}
        {--yes : Confirm firewall rule removal}
        {--idempotency-key= : Reuse a key to retry this operation start}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Remove one named UFW rule through the gateway.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $nodeId = $this->nodeId();

        if ($nodeId === null) {
            return self::FAILURE;
        }

        $name = $this->ruleName();

        if ($name === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        if ($this->option('yes') !== true && $this->option('idempotency-key') === null) {
            $rules = $this->sendWithProgress($connector, new ListFirewallRulesRequest($nodeId), FirewallRulesResponse::class,
                ['Resolve firewall rule', 'Loading firewall rules', 'Loaded firewall rules']);

            if (! $rules instanceof FirewallRulesResponse) {
                return self::FAILURE;
            }

            if (! array_any($rules->rules, static fn (FirewallRuleResponse $rule): bool => $rule->name === $name)) {
                return $this->renderGatewayFailure('http.404', 'Resource not found.', $rules->requestId);
            }
        }

        if (! $this->confirmAction("Remove firewall rule [{$name}] from Node #{$nodeId}?", 'Firewall rule removal cancelled.')) {
            return self::FAILURE;
        }

        $profile = $this->activeGatewayProfile($repository);
        if ($profile === null) {
            return self::FAILURE;
        }
        $operation = $this->send($connector, new RemoveFirewallRuleRequest($nodeId, $name, is_string($this->option('idempotency-key')) ? $this->option('idempotency-key') : null), OperationResponse::class);

        return $operation instanceof OperationResponse
            ? $this->followOperation($connector, $profile, $operation, "Remove firewall rule [{$name}]", 'Firewall rule removal')
            : self::FAILURE;
    }
}
