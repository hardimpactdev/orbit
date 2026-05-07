<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Concerns\HandlesPromptCancellation;
use App\Concerns\WithSpinner;
use App\Concerns\WithStepTree;
use App\Exceptions\PromptAborted;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Nodes\RemoveNodeRequest;
use App\Http\Gateway\Responses\Nodes\NodeRemoveResponse;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\WireGuardPeer;
use App\Services\Nodes\DevelopmentDnsMappingEnactor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('node:remove
    {name? : Name of the node to remove}
    {--force : Confirm destructive operation without prompting}
    {--json : Output as JSON}')]
#[Description('Remove a node from the registry')]
class NodeRemoveCommand extends Command
{
    use HandlesPromptCancellation;
    use WithSpinner;
    use WithStepTree;

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

        $name = $this->argument('name');
        if ($name === null) {
            if ($this->isInteractiveInput()) {
                try {
                    $name = $this->promptText('Node name', required: true);
                } catch (PromptAborted) {
                    return $this->promptAborted();
                }
            } else {
                return $this->failCommand(
                    code: 'validation_failed',
                    message: 'Node name is required.',
                    meta: ['field' => 'name'],
                );
            }
        }

        $name = (string) $name;

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

            try {
                $confirmed = $this->promptConfirm($confirmMessage, default: false);
            } catch (PromptAborted) {
                return $this->promptAborted();
            }

            if (! $confirmed) {
                return $this->failCommand(
                    code: 'validation_failed',
                    message: 'Operation cancelled.',
                    meta: [],
                );
            }
        }

        if ($callerRole === 'control') {
            return $this->forwardRemove($name, $isSelfRemoval);
        }

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

        return $this->handleGatewayLocal($name, $isSelfRemoval);
    }

    private function handleGatewayLocal(string $name, bool $isSelfRemoval): int
    {
        $node = $this->resolveNode($name);
        if (is_int($node)) {
            return $node;
        }

        $grantsRemoved = 0;
        $peerRemoved = false;
        $operation = function () use ($node, &$grantsRemoved, &$peerRemoved): string {
            $grantsRemoved = NodeAccess::query()
                ->where('consumer_node_id', $node->id)
                ->orWhere('serving_node_id', $node->id)
                ->delete();

            $peerRemoved = WireGuardPeer::query()
                ->where('node_id', $node->id)
                ->delete() > 0;

            app(DevelopmentDnsMappingEnactor::class)->remove($node);

            $node->delete();

            return 'removed';
        };

        if (! $this->wantsJson()) {
            $exitCode = $this->runNodeRemoveTree($name, $operation);

            if ($exitCode !== self::SUCCESS) {
                return self::FAILURE;
            }
        } else {
            $operation();
        }

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

    private function forwardRemove(string $name, bool $isSelfRemoval): int
    {
        try {
            $dto = null;
            $operation = function () use ($name, &$dto): string {
                /** @var NodeRemoveResponse $dto */
                $dto = app(GatewayConnector::class)
                    ->send(new RemoveNodeRequest($name, $this->option('force') ? 'force' : 'interactive_confirm'))
                    ->dto();

                return 'removed';
            };

            if (! $this->wantsJson()) {
                $exitCode = $this->runNodeRemoveTree($name, $operation);

                if ($exitCode !== self::SUCCESS || ! $dto instanceof NodeRemoveResponse) {
                    return self::FAILURE;
                }
            } else {
                $operation();
            }
        } catch (GatewayApiException $e) {
            return $this->failCommand(
                code: $e->errorCode() ?? 'gateway_unavailable',
                message: $e->getMessage() !== ''
                    ? $e->getMessage()
                    : 'Gateway connection is required to remove a node.',
                meta: $e->errorMeta(),
            );
        } catch (Throwable) {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway connection is required to remove a node.',
                meta: [],
            );
        }

        return $this->respondForwardedSuccess($name, $isSelfRemoval, $dto);
    }

    private function respondForwardedSuccess(string $fallbackName, bool $fallbackIsSelfRemoval, NodeRemoveResponse $dto): int
    {
        $responseData = [
            'name' => $dto->name !== '' ? $dto->name : $fallbackName,
            'action' => 'removed',
            'removed_self' => $dto->removedSelf,
            'wireguard_peer_removed' => $dto->wireguardPeerRemoved,
            'grants_removed' => $dto->grantsRemoved,
        ];

        if ($this->wantsJson()) {
            $this->line(json_encode([
                'success' => [
                    'data' => $responseData,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->line("Node '{$responseData['name']}' removed");

        if ($responseData['removed_self']) {
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
        return ! $this->wantsJson() && $this->input->isInteractive();
    }

    private function runNodeRemoveTree(string $name, callable $operation): int
    {
        return $this->runStepTree(
            'Remove Node',
            [
                [
                    'label' => 'Validate removal',
                    'doneLabel' => 'Validated removal',
                    'run' => fn (): string => $name,
                ],
                [
                    'label' => 'Remove node grants',
                    'doneLabel' => 'Removed node grants',
                    'run' => fn (): string => 'ready',
                ],
                [
                    'label' => 'Remove WireGuard peer',
                    'doneLabel' => 'Removed WireGuard peer',
                    'run' => fn (): string => 'ready',
                ],
                [
                    'label' => 'Remove node record',
                    'doneLabel' => 'Removed node record',
                    'run' => $operation,
                ],
            ],
            doneFooter: "Node `{$name}` removed",
            failFooter: "Failed to remove node `{$name}`",
        );
    }

    private function confirmationMessage(string $name, bool $isSelfRemoval): string
    {
        if ($isSelfRemoval) {
            return 'Remove this control node from the fleet? This machine will lose Orbit gateway access.';
        }

        return "Remove node '{$name}'? This cannot be undone.";
    }

    private function promptAborted(): int
    {
        return $this->failCommand(
            code: 'validation_failed',
            message: 'Operation cancelled.',
            meta: [],
        );
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
