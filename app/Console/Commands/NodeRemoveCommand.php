<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Nodes\RemoveNode;
use App\Concerns\PromptsForRegistryEntities;
use App\Concerns\WithSpinner;
use App\Concerns\WithStepTree;
use App\Exceptions\PromptAborted;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Nodes\RemoveNodeRequest;
use App\Http\Gateway\Responses\Nodes\NodeRemoveResponse;
use App\Models\Node;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

#[Signature('node:remove
    {name? : Name of the node to remove}
    {--force : Confirm destructive operation without prompting}
    {--json : Output as JSON}')]
#[Description('Remove a node from the registry')]
class NodeRemoveCommand extends Command
{
    use PromptsForRegistryEntities;
    use WithSpinner;
    use WithStepTree;

    public function handle(): int
    {
        $executionContext = (bool) config('orbit.is_gateway', false) ? 'gateway' : 'control';

        $name = $this->argument('name');
        if ($name === null) {
            if ($this->isInteractiveInput()) {
                try {
                    $selected = $this->promptForVisibleNode(label: 'Select a node to remove');

                    if ($selected instanceof GatewayApiException) {
                        return $this->failCommand(
                            code: $selected->errorCode() ?? 'gateway_unavailable',
                            message: $selected->getMessage(),
                            meta: $selected->errorMeta(),
                        );
                    }

                    $name = $selected;
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

        $isSelfRemoval = (bool) config('orbit.is_gateway', false)
            && $this->gatewayNodeExists($name);

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

        if ($executionContext === 'control') {
            return $this->forwardRemove($name, $isSelfRemoval);
        }

        $node = $this->resolveNode($name);
        if (is_int($node)) {
            return $node;
        }

        if (app(NodeRoleAssignments::class)->nodeIsGateway($node)) {
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

        $dto = null;
        $operation = function () use ($node, $isSelfRemoval, &$dto): string {
            $dto = app(RemoveNode::class)->handle($node, $isSelfRemoval);

            return $dto->warnings === [] ? 'removed' : 'removed with drift';
        };

        if (! $this->wantsJson()) {
            $exitCode = $this->runNodeRemoveTree($name, $operation);

            if ($exitCode !== self::SUCCESS) {
                return self::FAILURE;
            }
        } else {
            $operation();
        }

        if (! $dto instanceof NodeRemoveResponse) {
            return self::FAILURE;
        }

        return $this->respondSuccess($name, $dto);
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

        return $this->respondSuccess($name, $dto);
    }

    private function respondSuccess(string $fallbackName, NodeRemoveResponse $dto): int
    {
        $responseData = [
            'name' => $dto->name !== '' ? $dto->name : $fallbackName,
            'action' => 'removed',
            'removed_self' => $dto->removedSelf,
            'wireguard_peer_removed' => $dto->wireguardPeerRemoved,
            'grants_removed' => $dto->grantsRemoved,
        ];

        if ($this->wantsJson()) {
            $success = [
                'data' => $responseData,
            ];

            if ($dto->warnings !== []) {
                $success['meta'] = [
                    'warnings' => $dto->warnings,
                ];
            }

            $this->line(json_encode([
                'success' => $success,
            ], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->line("Node '{$responseData['name']}' removed");

        if ($responseData['removed_self']) {
            $this->line('  This machine no longer has Orbit gateway access.');
        }

        $this->renderWarnings($dto->warnings);

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

    private function gatewayNodeExists(string $name): bool
    {
        return Node::query()
            ->where('name', $name)
            ->where('status', 'active')
            ->where(function (Builder $query): void {
                $query
                    ->where('role', 'gateway')
                    ->orWhereIn('id', app(NodeRoleAssignments::class)->activeNodeIdsForRole('gateway'));
            })
            ->exists();
    }

    private function confirmationMessage(string $name, bool $isSelfRemoval): string
    {
        if ($isSelfRemoval) {
            return 'Remove this operator node from the fleet? This machine will lose Orbit gateway access.';
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

    /**
     * @param  list<array<string, string>>  $warnings
     */
    private function renderWarnings(array $warnings): void
    {
        foreach ($warnings as $warning) {
            $this->line('  Drift detected: '.$this->warningLabel((string) ($warning['code'] ?? '')).': '.(string) ($warning['message'] ?? 'Warning'));

            if (isset($warning['next_command']) && is_string($warning['next_command'])) {
                $this->line('  Run: orbit '.$warning['next_command']);
            }
        }
    }

    private function warningLabel(string $code): string
    {
        return match ($code) {
            RemoveNode::DevelopmentDnsWarningCode => 'Development DNS',
            'node.wireguard_peer_extra' => 'WireGuard',
            default => 'Node',
        };
    }
}
