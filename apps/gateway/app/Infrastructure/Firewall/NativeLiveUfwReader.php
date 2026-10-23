<?php

declare(strict_types=1);

namespace App\Infrastructure\Firewall;

use App\Domain\Firewall\LiveFirewallBackendStatus;
use App\Infrastructure\Processes\CommandDeadline;
use App\Models\Node;
use Throwable;

final readonly class NativeLiveUfwReader
{
    public function __construct(
        private AgentUfw $ufw,
        private CommandDeadline $deadline = new CommandDeadline,
    ) {}

    /**
     * @return array{backend: LiveFirewallBackendStatus, stdout: string}
     */
    public function read(Node $node): array
    {
        $host = $node->wireguard_ip;

        if ($node->platform !== 'linux' || ! is_string($host) || $host === '') {
            return ['backend' => LiveFirewallBackendStatus::Unreachable, 'stdout' => ''];
        }

        try {
            $result = $this->ufw->status($node, deadlineSeconds: $this->deadline->cap(30.0));
        } catch (Throwable) {
            return ['backend' => LiveFirewallBackendStatus::Unreachable, 'stdout' => ''];
        }

        if (! $result->succeeded() || $result->truncated) {
            return ['backend' => LiveFirewallBackendStatus::Unreachable, 'stdout' => ''];
        }

        if (preg_match('/\AStatus:\s+inactive\s*$/mi', $result->stdout) === 1) {
            return ['backend' => LiveFirewallBackendStatus::Inactive, 'stdout' => $result->stdout];
        }

        if (preg_match('/\AStatus:\s+absent\s*$/mi', $result->stdout) === 1) {
            return ['backend' => LiveFirewallBackendStatus::Absent, 'stdout' => $result->stdout];
        }

        if (preg_match('/\AStatus:\s+active\s*$/mi', $result->stdout) !== 1) {
            return ['backend' => LiveFirewallBackendStatus::Unreachable, 'stdout' => $result->stdout];
        }

        return ['backend' => LiveFirewallBackendStatus::Active, 'stdout' => $result->stdout];
    }
}
