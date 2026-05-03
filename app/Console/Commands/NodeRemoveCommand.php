<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Node;
use App\Models\NodeAccess;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

#[Signature('node:remove
    {name? : Name of the node to remove}
    {--force : Confirm destructive operation without prompting}
    {--json : Output as JSON}')]
#[Description('Remove a node from the registry')]
class NodeRemoveCommand extends Command
{
    public function handle(): int
    {
        $callerRole = $this->callerRole();

        if ($callerRole === 'app') {
            return $this->failCommand(
                code: 'caller_role_not_allowed',
                message: 'This command may only be run from a control or gateway node.',
                meta: ['caller_role' => 'app'],
            );
        }

        if ($callerRole === 'unknown') {
            return $this->failCommand(
                code: 'local_context_invalid',
                message: 'Local node role setting is invalid.',
                meta: [
                    'setting' => 'general.local_node_role',
                    'reason' => 'unsupported_value',
                    'caller_role' => 'unknown',
                ],
            );
        }

        if ($callerRole === 'control') {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway connection is required to remove a node.',
                meta: [],
            );
        }

        return $this->handleGatewayLocal();
    }

    private function handleGatewayLocal(): int
    {
        $name = $this->argument('name');
        if ($name === null) {
            if ($this->isInteractiveInput()) {
                $name = text(label: 'Node name', required: true);
            } else {
                return $this->failCommand(
                    code: 'validation_failed',
                    message: 'Node name is required.',
                    meta: ['field' => 'name'],
                );
            }
        }

        $name = (string) $name;

        $node = $this->resolveNode($name);
        if (is_int($node)) {
            return $node;
        }

        if ($node->role === 'gateway') {
            return $this->failCommand(
                code: 'node.gateway_removal_denied',
                message: 'The gateway node cannot be removed with this command.',
                meta: [
                    'name' => $name,
                    'role' => 'gateway',
                ],
            );
        }

        $localNodeName = Node::query()
            ->where('is_local', true)
            ->value('name');

        $isSelfRemoval = $localNodeName !== null
            && $localNodeName === $name;

        if (! $this->option('force')) {
            if (! $this->isInteractiveInput()) {
                return $this->failCommand(
                    code: 'validation_failed',
                    message: 'Use --force to remove this node.',
                    meta: ['field' => 'force'],
                );
            }

            $confirmMessage = $this->confirmationMessage($name, $isSelfRemoval);

            if (! confirm($confirmMessage, default: false)) {
                return $this->failCommand(
                    code: 'validation_failed',
                    message: 'Operation cancelled.',
                    meta: [],
                );
            }
        }

        if (! $this->wantsJson()) {
            $this->renderProgressTree($name);
        }

        $grantsRemoved = NodeAccess::query()
            ->where('consumer_node_id', $node->id)
            ->orWhere('serving_node_id', $node->id)
            ->delete();

        // WireGuard peer teardown is a documented bootstrap gap.
        $peerRemoved = false;

        $node->delete();

        $data = [
            'name' => $name,
            'action' => 'removed',
            'removed_self' => $isSelfRemoval,
            'wireguard_peer_removed' => $peerRemoved,
            'grants_removed' => $grantsRemoved,
        ];

        if ($this->wantsJson()) {
            $this->line(json_encode([
                'success' => [
                    'data' => $data,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->line("Node '{$name}' removed");

        if ($isSelfRemoval) {
            $this->line('  This machine no longer has Orbit gateway access.');
        }

        return self::SUCCESS;
    }

    private function resolveNode(string $name): Node|int
    {
        $node = Node::query()
            ->where('name', $name)
            ->where('status', 'active')
            ->first();

        if (! $node instanceof Node) {
            return $this->failCommand(
                code: 'node.not_found',
                message: "Node '{$name}' not found.",
                meta: [
                    'name' => $name,
                ],
            );
        }

        return $node;
    }

    private function callerRole(): string
    {
        $localRole = Node::query()
            ->where('is_local', true)
            ->where('status', 'active')
            ->value('role');

        if (! is_string($localRole) || $localRole === '') {
            return 'control';
        }

        if (! in_array($localRole, ['gateway', 'app', 'control'], true)) {
            return 'unknown';
        }

        return $localRole;
    }

    protected function isInteractiveInput(): bool
    {
        return ! $this->option('json')
            && function_exists('posix_isatty')
            && @posix_isatty(STDOUT);
    }

    private function renderProgressTree(string $name): void
    {
        $this->line('┌ Remove Node');
        $this->line('○ Validate removal');
        $this->line('○ Remove node grants');
        $this->line('○ Remove WireGuard peer');
        $this->line('○ Remove node record');
        $this->line("└ Node `{$name}` removed");
    }

    private function confirmationMessage(string $name, bool $isSelfRemoval): string
    {
        if ($isSelfRemoval) {
            return 'Remove this control node from the fleet? This machine will lose Orbit gateway access.';
        }

        return "Remove node '{$name}'? This cannot be undone.";
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function failCommand(string $code, string $message, array $meta, ?string $humanMessage = null): int
    {
        if ($this->wantsJson()) {
            $this->line(json_encode([
                'error' => [
                    'code' => $code,
                    'message' => $message,
                    'meta' => empty($meta) ? (object) [] : $meta,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $output = $humanMessage ?? $message;
        $this->error($output);

        return self::FAILURE;
    }

    private function wantsJson(): bool
    {
        return (bool) $this->option('json');
    }
}
