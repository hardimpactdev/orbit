<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Infrastructure\Firewall\AgentUfw;
use App\Infrastructure\Processes\CommandResult;
use App\Models\Node;

/** Removes the UFW rules that let a Route's Cluster LAN reach its workload, by their comment. */
final readonly class RemoteAppDevRouteFirewallManager
{
    public function __construct(
        private AgentUfw $ufw,
    ) {}

    public function remove(Node $node, int $routeId): void
    {
        $comment = "orbit:route-{$routeId}-lan";
        $numbers = $this->numbers($node, $comment);
        rsort($numbers);

        foreach ($numbers as $number) {
            $this->checked($node, $this->ufw->change($node, ['--force', 'delete', (string) $number]));
        }

        if ($this->numbers($node, $comment) !== []) {
            throw $this->failed($node, new CommandResult(1, '', "UFW still holds rules with comment [{$comment}].", 0, false));
        }
    }

    /** @return list<int> The numbers of the rules whose line ends with the comment. */
    private function numbers(Node $node, string $comment): array
    {
        $status = $this->checked($node, $this->ufw->status($node));
        $numbers = [];

        foreach (explode("\n", $status->stdout) as $line) {
            if (str_ends_with(rtrim($line), "# {$comment}") && preg_match('/\A\[\s*(\d+)\]/', $line, $match) === 1) {
                $numbers[] = (int) $match[1];
            }
        }

        return $numbers;
    }

    private function checked(Node $node, CommandResult $result): CommandResult
    {
        if (! $result->succeeded()) {
            throw $this->failed($node, $result);
        }

        return $result;
    }

    private function failed(Node $node, CommandResult $result): RuntimeConvergenceException
    {
        return new RuntimeConvergenceException(
            step: 'route-firewall-remove',
            errorCode: 'app-dev.route_firewall_failed',
            message: "App development step [route-firewall-remove] failed on node [{$node->name}].",
            result: $result,
        );
    }
}
