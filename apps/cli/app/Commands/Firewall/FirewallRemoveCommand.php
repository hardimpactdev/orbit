<?php

declare(strict_types=1);

namespace App\Commands\Firewall;

use App\Commands\Concerns\WithStepTree;
use App\Exceptions\GatewayApiException;
use RuntimeException;

final class FirewallRemoveCommand extends FirewallGatewayCommand
{
    use WithStepTree;

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
                    'validation_failed',
                    'Use --force to remove this firewall rule.',
                    ['field' => 'force', 'reason' => 'destructive_consent_required'],
                );
            }

            if (! $this->confirm("Remove firewall rule '{$name}' from {$node}?", default: false)) {
                return $this->renderFailure(
                    'validation_failed',
                    'Operation cancelled.',
                    ['field' => 'force', 'reason' => 'destructive_consent_required'],
                );
            }
        }

        if ($this->wantsJson()) {
            try {
                $response = $this->removeRule($name, $node);
            } catch (GatewayApiException $exception) {
                return $this->renderGatewayFailure($exception);
            }

            return $this->renderSuccess($response);
        }

        return $this->renderRemovalTree($name, $node);
    }

    private function renderRemovalTree(string $name, string $node): int
    {
        $response = [];

        $result = $this->runStepOperation(
            'Removing Firewall Rule',
            [
                ['label' => 'Confirm destructive removal'],
                ['label' => 'Resolve firewall rule'],
                ['label' => 'Remove backend firewall rule'],
                ['label' => 'Apply and verify firewall removal'],
            ],
            work: function () use ($name, $node, &$response): void {
                $response = $this->removeRuleForHuman($name, $node);
            },
            doneFooter: function () use ($name, $node, &$response): string {
                return $this->isAlreadyAbsent($response)
                    ? "Firewall rule '{$name}' was already absent on {$node}"
                    : "Firewall rule '{$name}' removed from {$node}";
            },
        );

        if (! $result->isCompleted()) {
            return self::FAILURE;
        }

        $this->renderRemovalSummary($name, $node, $response);

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function removeRule(string $name, string $node): array
    {
        return $this->gatewayDelete('/api/firewall-rules/'.rawurlencode($name), [
            'node' => $node,
            'destructive_consent' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function removeRuleForHuman(string $name, string $node): array
    {
        try {
            return $this->removeRule($name, $node);
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
    private function renderRemovalSummary(string $name, string $node, array $response): void
    {
        if ($this->isAlreadyAbsent($response)) {
            $this->line("  Firewall rule '{$name}' was already absent on {$node}; nothing to remove.");

            return;
        }

        $this->line("  Rule: {$name}");
        $this->line("  Node: {$node}");
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function isAlreadyAbsent(array $response): bool
    {
        $status = $response['success']['data']['rule']['status'] ?? null;

        return $status === 'already_absent';
    }
}
