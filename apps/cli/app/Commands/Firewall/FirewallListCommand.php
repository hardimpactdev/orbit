<?php

declare(strict_types=1);

namespace App\Commands\Firewall;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;

final class FirewallListCommand extends GatewayCommand
{
    use ResolvesHostContext;

    #[\Override]
    protected $signature = 'firewall:list {--node= : Filter by node name} {--json}';

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

        return $this->renderSuccess($response);
    }
}
