<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Concerns\PromptsForRegistryEntities;
use App\Concerns\WithSpinner;
use App\Concerns\WithStepTree;
use App\Exceptions\PromptAborted;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Gateway\ShowGatewayIdentityRequest;
use App\Http\Gateway\Requests\Nodes\RevokeNodeRequest;
use App\Http\Gateway\Responses\Gateway\GatewayIdentityResponse;
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
    use PromptsForRegistryEntities;
    use WithSpinner;
    use WithStepTree;

    public function handle(): int
    {
        $executionContext = (bool) config('orbit.is_gateway', false) ? 'gateway' : 'control';

        if ($executionContext === 'control') {
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
                    $selected = $this->promptNodeSelection('Select the consuming node');

                    if ($selected instanceof GatewayApiException) {
                        return $this->failCommand(
                            code: $selected->errorCode() ?? 'gateway_unavailable',
                            message: $selected->getMessage(),
                            meta: $selected->errorMeta(),
                        );
                    }

                    $consumerName = $selected;
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
                    $selected = $this->promptNodeSelection('Select the serving node');

                    if ($selected instanceof GatewayApiException) {
                        return $this->failCommand(
                            code: $selected->errorCode() ?? 'gateway_unavailable',
                            message: $selected->getMessage(),
                            meta: $selected->errorMeta(),
                        );
                    }

                    $servingName = $selected;
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

        $isSelfLockout = false;

        if (! $this->option('force')) {
            if (! $this->isInteractiveInput()) {
                return $this->failCommand(
                    code: 'validation_failed',
                    message: 'Use --force to revoke this grant.',
                    meta: ['field' => 'force'],
                );
            }

            try {
                $isSelfLockout = $this->detectControlSelfLockout($consumerName, $servingName);
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
                $exitCode = $this->runNodeRevokeTree($consumerName, $servingName, false, $isSelfLockout, $operation);

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

        return $this->respondSuccess($dto->consumingNode, $dto->servingNode, $dto->alreadyAbsent, $dto->selfLockout, $dto->wasGatewayAdmin);
    }

    /**
     * @throws GatewayApiException
     */
    private function detectControlSelfLockout(string $consumerName, string $servingName): bool
    {
        $request = new ShowGatewayIdentityRequest;
        $response = app(GatewayConnector::class)->send($request);

        if ($response->clientError() || $response->serverError()) {
            $exception = $request->getRequestException($response, null);

            if ($exception instanceof GatewayApiException) {
                throw $exception;
            }

            throw new GatewayApiException("Gateway request failed with HTTP status {$response->status()}");
        }

        /** @var GatewayIdentityResponse $identity */
        $identity = $response->dto();

        $selfName = is_string($identity->self['name'] ?? null) ? $identity->self['name'] : null;
        $gatewayName = is_string($identity->gateway['name'] ?? null) ? $identity->gateway['name'] : null;

        if ($selfName === null || $gatewayName === null) {
            throw new GatewayApiException(
                'Gateway identity response is missing node identity.',
                'gateway_unavailable',
                ['endpoint' => '/api/me'],
            );
        }

        return $consumerName === $selfName && $servingName === $gatewayName;
    }

    private function respondSuccess(string $consumerName, string $servingName, bool $alreadyAbsent, bool $isSelfLockout, bool $wasGatewayAdmin = false): int
    {
        $data = [
            'consuming_node' => $consumerName,
            'serving_node' => $servingName,
            'action' => 'revoked',
            'already_absent' => $alreadyAbsent,
            'self_lockout' => $isSelfLockout,
            'was_gateway_admin' => $wasGatewayAdmin,
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

        if ($wasGatewayAdmin) {
            $this->line('  This revoked a gateway-admin grant.');
        }

        return self::SUCCESS;
    }

    private function handleGatewayLocal(): int
    {
        $consumerName = $this->argument('consuming_node');
        if ($consumerName === null) {
            if ($this->isInteractiveInput()) {
                try {
                    $selected = $this->promptNodeSelection('Select the consuming node');

                    if ($selected instanceof GatewayApiException) {
                        return $this->failCommand(
                            code: $selected->errorCode() ?? 'gateway_unavailable',
                            message: $selected->getMessage(),
                            meta: $selected->errorMeta(),
                        );
                    }

                    $consumerName = $selected;
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
                    $selected = $this->promptNodeSelection('Select the serving node');

                    if ($selected instanceof GatewayApiException) {
                        return $this->failCommand(
                            code: $selected->errorCode() ?? 'gateway_unavailable',
                            message: $selected->getMessage(),
                            meta: $selected->errorMeta(),
                        );
                    }

                    $servingName = $selected;
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

        $isSelfLockout = (bool) config('orbit.is_gateway', false)
            && $consumer->role === 'gateway'
            && $serving->role === 'gateway';

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

        $grant = NodeAccess::query()
            ->where('consumer_node_id', $consumer->id)
            ->where('serving_node_id', $serving->id)
            ->first();

        $alreadyAbsent = $grant === null;
        $wasGatewayAdmin = $grant !== null && in_array('*', $grant->permissions ?? ['*'], true);

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

        return $this->respondSuccess($consumerName, $servingName, $alreadyAbsent, $isSelfLockout, $wasGatewayAdmin);
    }

    /**
     * @throws PromptAborted
     */
    private function promptNodeSelection(string $label): string|GatewayApiException
    {
        return $this->promptForVisibleNode(label: $label);
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

    protected function isInteractiveInput(): bool
    {
        return ! $this->wantsJson() && $this->input->isInteractive();
    }

    private function confirmationMessage(string $consumerName, string $servingName, bool $isSelfLockout): string
    {
        if ($isSelfLockout) {
            return 'Revoke this operator node\'s gateway access? This machine will lose Orbit gateway access.';
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
