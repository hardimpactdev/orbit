<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Domain\Metrics\MetricsCredentialRuntime;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Node;

final readonly class MetricsReadCommands implements MetricsCredentialRuntime
{
    public function __construct(private AgentCommandRunner $agents) {}
    public function health(Node $node, string $service): bool
    {
        if (MetricsService::tryFrom($service) === null) { return false; }
        try { return ($this->agents->run($node, 'metrics.runtime-health', ['service' => $service])['healthy'] ?? false) === true; }
        catch (\Throwable) { return false; }
    }
    public function verify(Node $node, #[\SensitiveParameter] string $password): bool
    {
        return ($this->agents->run($node, 'metrics.credentials', ['address' => $node->wireguard_ip, 'action' => ['operation' => 'verify', 'password' => $password]])['verified'] ?? false) === true;
    }
    public function actual(Node $node): string
    {
        $assignment = \App\Models\NodeRole::query()->where('role', \App\Domain\Nodes\RoleName::Metrics)->with('node')->limit(2)->get();
        if ($assignment->count() !== 1) { return $assignment->isEmpty() ? 'inactive' : 'drift'; }
        try {
            $result = $this->agents->run($node, 'metrics.node-exporter', ['address' => $node->wireguard_ip, 'metrics_address' => $assignment->sole()->node->wireguard_ip, 'action' => ['operation' => 'actual']]);
            return is_string($result['actual'] ?? null) ? $result['actual'] : 'unknown';
        } catch (\Throwable) { return 'unknown'; }
    }
}
