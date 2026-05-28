<?php

declare(strict_types=1);

namespace App\Commands\Firewall;

use App\Exceptions\GatewayApiException;

final class FirewallRemoveCommand extends FirewallGatewayCommand
{
    #[\Override]
    protected $signature = 'firewall:remove
        {name? : Firewall rule name}
        {--node= : Target node}
        {--force : Confirm destructive operation without prompting}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Remove firewall rule intent through the gateway.';

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

        if (! $this->option('force')) {
            if (! $this->input->isInteractive() || $this->wantsJson()) {
                return $this->renderFailure(
                    'destructive_consent_required',
                    'Use --force to remove this firewall rule.',
                    ['field' => 'force'],
                );
            }

            if (! $this->confirm("Remove firewall rule '{$name}' from {$node}?", default: false)) {
                return $this->renderFailure(
                    'destructive_consent_required',
                    'Operation cancelled.',
                    ['field' => 'force'],
                );
            }
        }

        try {
            $response = $this->gatewayDelete('/api/firewall-rules/'.rawurlencode($name), [
                'node' => $node,
                'destructive_consent' => true,
            ]);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }
}
