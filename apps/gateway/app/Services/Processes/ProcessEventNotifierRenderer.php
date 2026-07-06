<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use RuntimeException;

final readonly class ProcessEventNotifierRenderer
{
    public function scriptPath(): string
    {
        return repo_path('apps/gateway/resources/node-scripts/orbit-notify-exit.sh');
    }

    public function installPath(): string
    {
        return '/usr/local/bin/orbit-notify-exit';
    }

    public function gatewayEndpointPath(): string
    {
        return '/etc/orbit/gateway-endpoint';
    }

    public function content(): string
    {
        $content = file_get_contents($this->scriptPath());

        if ($content === false) {
            throw new RuntimeException("Cannot read process event notifier script: {$this->scriptPath()}");
        }

        return $content;
    }

    public function hash(): string
    {
        return hash('sha256', $this->content());
    }

    public function expectedGatewayEndpoint(): ?string
    {
        $gatewayUrl = LocalGatewaySettings::current()->gateway_url;

        if (is_string($gatewayUrl) && trim($gatewayUrl) !== '') {
            return rtrim($gatewayUrl, '/');
        }

        return $this->gatewayRoleEndpoint();
    }

    private function gatewayRoleEndpoint(): ?string
    {
        /** @var Node|null $gatewayNode */
        $gatewayNode = Node::query()
            ->whereHas('roleAssignments', function ($query): void {
                $query
                    ->where('role', NodeRoleName::Gateway->value)
                    ->where('status', NodeRoleStatus::Active->value);
            })
            ->orderBy('name')
            ->first();

        if (! $gatewayNode instanceof Node) {
            return null;
        }

        return 'https://gateway';
    }
}
