<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Concerns\HandlesPromptCancellation;
use App\Concerns\WithSpinner;
use App\Concerns\WithStepTree;
use App\Exceptions\PromptAborted;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Nodes\RevokeNodeRequest;
use App\Http\Gateway\Responses\Nodes\NodeRevokeResponse;
use App\Models\Node;
use App\Models\NodeAccess;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('node:revoke
    {consuming_node? : Name of the node whose access is being revoked}
    {serving_node? : Name of the node providing access}
    {--force : Confirm destructive operation without prompting}
    {--json : Output as JSON}')]
#[Description('Revoke one node\'s access to another')]
class NodeRevokeCommand extends Command
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

        if ($callerRole === 'control') {
            return $this->handleControl();
        }

        return $this->handleGatewayLocal();
    }

    private function handleControl(): int
    {
        $consumerName = $this->argument('consuming_node');
        if ($consumerName === null) {
            if ($this->isInteractiveInput()) {
                try {
                    $consumerName = $this->promptText('Consuming node', required: true);
                } catch (PromptAborted) {
                    return $this->promptAborted();
                }
            } else {
                return $this->failCommand(
                    code: 'validation_failed',
                    message: 'Consuming node is required.',
                    meta: ['field' => 'consuming_node'],
                );
            }
        }

        $servingName = $this->argument('serving_node');
        if ($servingName === null) {
            if ($this->isInteractiveInput()) {
                try {
                    $servingName = $this->promptText('Serving node', required: true);
                } catch (PromptAborted) {
                    return $this->promptAborted();
                }
            } else {
                return $this->failCommand(
                    code: 'validation_failed',
                    message: 'Serving node is required.',
                    meta: ['field' => 'serving_node'],
                );
            }
        }

        if (! $this->option('force')) {
            if (! $this->isInteractiveInput()) {
                return $this->failCommand(
                    code: 'validation_failed',
                    message: 'Use --force to revoke this grant.',
                    meta: ['field' => 'force'],
                );
            }

            $confirmMessage = $this->confirmationMessage($consumerName, $servingName, false);

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

        try {
            $dto = null;
            $operation = function () use ($consumerName, $servingName, &$dto): string {
                /** @var NodeRevokeResponse $dto */
                $dto = app(GatewayConnector::class)
                    ->send(new RevokeNodeRequest($consumerName, $servingName))
                    ->dto();

                return 'revoked';
            };

            if (! $this->wantsJson()) {
                $exitCode = $this->runNodeRevokeTree($consumerName, $servingName, false, false, $operation);

                if ($exitCode !== self::SUCCESS || ! $dto instanceof NodeRevokeResponse) {
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
                    : 'Gateway connection is required to revoke a grant.',
                meta: $e->errorMeta(),
            );
        } catch (Throwable) {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway connection is required to revoke a grant.',
                meta: [],
            );
        }

        return $this->respondSuccess($dto->consumingNode, $dto->servingNode, $dto->alreadyAbsent, $dto->selfLockout);
    }

    private function respondSuccess(string $consumerName, string $servingName, bool $alreadyAbsent, bool $isSelfLockout): int
    {
        $data = [
            'consuming_node' => $consumerName,
            'serving_node' => $servingName,
            'action' => 'revoked',
            'already_absent' => $alreadyAbsent,
            'self_lockout' => $isSelfLockout,
        ];

        if ($this->wantsJson()) {
            $this->line(json_encode([
                'success' => [
                    'data' => $data,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $footer = $alreadyAbsent
            ? "Access from '{$consumerName}' to '{$servingName}' was already revoked"
            : "Access from '{$consumerName}' to '{$servingName}' revoked";

        $this->line($footer);

        if ($isSelfLockout) {
            $this->line('  This machine no longer has Orbit gateway access.');
        }

        return self::SUCCESS;
    }

    private function handleGatewayLocal(): int
    {
        $consumerName = $this->argument('consuming_node');
        if ($consumerName === null) {
            if ($this->isInteractiveInput()) {
                try {
                    $consumerName = $this->promptText('Consuming node', required: true);
                } catch (PromptAborted) {
                    return $this->promptAborted();
                }
            } else {
                return $this->failCommand(
                    code: 'validation_failed',
                    message: 'Consuming node is required.',
                    meta: ['field' => 'consuming_node'],
                );
            }
        }

        $servingName = $this->argument('serving_node');
        if ($servingName === null) {
            if ($this->isInteractiveInput()) {
                try {
                    $servingName = $this->promptText('Serving node', required: true);
                } catch (PromptAborted) {
                    return $this->promptAborted();
                }
            } else {
                return $this->failCommand(
                    code: 'validation_failed',
                    message: 'Serving node is required.',
                    meta: ['field' => 'serving_node'],
                );
            }
        }

        $consumer = $this->resolveNode($consumerName, 'consuming_node');
        if (is_int($consumer)) {
            return $consumer;
        }

        $serving = $this->resolveNode($servingName, 'serving_node');
        if (is_int($serving)) {
            return $serving;
        }

        $localNodeName = Node::query()
            ->where('is_local', true)
            ->value('name');

        $isSelfLockout = $consumer->name === $localNodeName && $serving->role === 'gateway';

        if (! $this->option('force')) {
            if (! $this->isInteractiveInput()) {
                return $this->failCommand(
                    code: 'validation_failed',
                    message: 'Use --force to revoke this grant.',
                    meta: ['field' => 'force'],
                );
            }

            $confirmMessage = $this->confirmationMessage($consumerName, $servingName, $isSelfLockout);

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

        $alreadyAbsent = ! NodeAccess::query()
            ->where('consumer_node_id', $consumer->id)
            ->where('serving_node_id', $serving->id)
            ->exists();

        $operation = function () use ($consumer, $serving): string {
            NodeAccess::query()
                ->where('consumer_node_id', $consumer->id)
                ->where('serving_node_id', $serving->id)
                ->delete();

            return 'revoked';
        };

        if (! $this->wantsJson()) {
            $exitCode = $this->runNodeRevokeTree($consumerName, $servingName, $alreadyAbsent, $isSelfLockout, $operation);

            if ($exitCode !== self::SUCCESS) {
                return self::FAILURE;
            }
        } else {
            $operation();
        }

        return $this->respondSuccess($consumerName, $servingName, $alreadyAbsent, $isSelfLockout);
    }

    private function resolveNode(string $name, string $field): Node|int
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

    private function confirmationMessage(string $consumerName, string $servingName, bool $isSelfLockout): string
    {
        if ($isSelfLockout) {
            return 'Revoke this control node\'s gateway access? This machine will lose Orbit gateway access.';
        }

        return "Revoke access from '{$consumerName}' to '{$servingName}'? This cannot be undone.";
    }

    private function promptAborted(): int
    {
        return $this->failCommand(
            code: 'validation_failed',
            message: 'Operation cancelled.',
            meta: [],
        );
    }

    private function runNodeRevokeTree(string $consumerName, string $servingName, bool $alreadyAbsent, bool $isSelfLockout, callable $operation): int
    {
        $footer = $alreadyAbsent
            ? "Access from '{$consumerName}' to '{$servingName}' was already revoked"
            : "Access from '{$consumerName}' to '{$servingName}' revoked";

        return $this->runStepTree(
            'Revoke Grant',
            [
                [
                    'label' => 'Validate nodes',
                    'doneLabel' => 'Validated nodes',
                    'run' => fn (): string => "{$consumerName} -> {$servingName}",
                ],
                [
                    'label' => 'Verify authorization',
                    'doneLabel' => 'Verified authorization',
                    'run' => fn (): string => 'authorized',
                ],
                [
                    'label' => 'Revoke access',
                    'doneLabel' => $alreadyAbsent ? 'Access already revoked' : 'Revoked access',
                    'run' => $operation,
                ],
            ],
            doneFooter: $footer,
            failFooter: "Failed to revoke access from '{$consumerName}' to '{$servingName}'",
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
