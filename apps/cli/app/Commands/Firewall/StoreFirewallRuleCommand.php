<?php

declare(strict_types=1);

namespace App\Commands\Firewall;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleInterrupted;
use App\Support\Console\ProgressOutcome;
use App\Support\Console\ProgressState;
use App\Support\Console\PromptAborted;
use Laravel\Prompts\SelectPrompt;
use Laravel\Prompts\TextPrompt;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\Nodes\ListNodesRequest;
use Orbit\Sdk\Responses\Nodes\NodesResponse;
use Orbit\Sdk\Responses\Operations\OperationResponse;

abstract class StoreFirewallRuleCommand extends FirewallCommand
{
    abstract protected function request(int $nodeId, string $port, ?string $source, ?string $protocol): GatewayRequest;

    /** A command whose port argument is optional asks for a missing port and Node in a terminal. */
    protected function promptsForMissingInput(): bool
    {
        return false;
    }

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        if ($this->promptsForMissingInput() && ! $this->askForMissingInput($repository, $connectors)) {
            return self::FAILURE;
        }

        $target = $this->target();
        $source = $target === null ? false : $this->source();

        if ($target === null || $source === false) {
            return self::FAILURE;
        }

        [$nodeId, $port, $protocol] = $target;

        return $this->admit($repository, $connectors, $this->request($nodeId, $port, $source, $protocol),
            "Apply firewall rule [{$this->describe($port, $protocol, $source)}]", 'Firewall rule');
    }

    #[\Override]
    protected function operationOutcome(OperationResponse $operation, string $verb): ?ProgressOutcome
    {
        $reason = $operation->result['skipped'] ?? null;

        return $operation->succeeded() && is_string($reason)
            ? new ProgressOutcome(ProgressState::Skipped, "Skipped — already applied ({$reason})")
            : null;
    }

    /** Without a terminal, or with --json, a missing value is refused by the validation of the target. */
    private function askForMissingInput(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): bool
    {
        $mode = $this->consoleMode();
        $port = $this->argument('port');
        $needsPort = ! is_string($port) || $port === '';
        $needsNode = $this->stringOption('node') === null;

        if (! $mode->mayPrompt || $mode->machine || (! $needsPort && ! $needsNode)) {
            return true;
        }

        try {
            if ($needsPort) {
                $this->input->setArgument('port', $this->commandPrompts()->run(static fn (): TextPrompt => new TextPrompt(
                    'Destination port or range',
                    placeholder: '8000:8100',
                    required: true,
                    validate: static fn (string $value): ?string => preg_match('/\A(\d{1,5})(?::(\d{1,5}))?\z/D', $value, $parts) === 1
                        && (int) $parts[1] >= 1 && (int) ($parts[2] ?? $parts[1]) <= 65_535 && (int) $parts[1] <= (int) ($parts[2] ?? $parts[1])
                        ? null : 'Firewall port must be from 1 to 65535 or an ordered range.',
                )));
            }

            if ($needsNode) {
                $nodeId = $this->askForNode($repository, $connectors);

                if ($nodeId === null) {
                    return false;
                }

                $this->input->setOption('node', (string) $nodeId);
            }
        } catch (PromptAborted|ConsoleInterrupted) {
            $this->renderGatewayFailure('input.cancelled', 'Firewall rule creation was cancelled.');

            return false;
        }

        return true;
    }

    private function askForNode(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): ?int
    {
        $connector = $this->gatewayConnector($repository, $connectors);
        $nodes = $connector === null ? null : $this->send($connector, new ListNodesRequest, NodesResponse::class);

        if (! $nodes instanceof NodesResponse) {
            return null;
        }

        $options = [];

        foreach ($nodes->nodes as $node) {
            if ($node->status === 'active' && $node->platform === 'linux') {
                $options[$node->id] = $node->name;
            }
        }

        if ($options === []) {
            $this->renderGatewayFailure('firewall.node_id_invalid', 'No active Linux Node is available.');

            return null;
        }

        $selected = $this->commandPrompts()->run(static fn (): SelectPrompt => new SelectPrompt('Node', $options));

        return is_int($selected) || is_string($selected) ? (int) $selected : null;
    }
}
