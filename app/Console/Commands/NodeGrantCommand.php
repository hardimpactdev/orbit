<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Node;
use App\Models\NodeAccess;
use App\Services\Gateway\GatewayRequestSender;
use App\Services\Gateway\Requests\GrantNodeRequest;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('node:grant
    {consuming_node : Name of the node requesting access}
    {serving_node : Name of the node providing access}
    {--json : Output as JSON}')]
#[Description('Grant one node access to another')]
class NodeGrantCommand extends Command
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
            return $this->forwardGrant();
        }

        return $this->handleGatewayLocal();
    }

    private function forwardGrant(): int
    {
        $consumerName = (string) $this->argument('consuming_node');
        $servingName = (string) $this->argument('serving_node');

        try {
            $response = GatewayRequestSender::make()->send(
                new GrantNodeRequest($consumerName, $servingName),
            );
        } catch (Throwable) {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway connection is required to grant node access.',
                meta: [],
            );
        }

        if (! $response->isSuccess()) {
            return $this->failCommand(
                code: $response->errorCode() ?? 'gateway_unavailable',
                message: $response->errorMessage() ?? 'Gateway connection is required to grant node access.',
                meta: $response->errorMeta(),
            );
        }

        return $this->respondForwardedSuccess($consumerName, $servingName, $response->data());
    }

    private function handleGatewayLocal(): int
    {
        $consumerName = (string) $this->argument('consuming_node');
        $servingName = (string) $this->argument('serving_node');

        $consumer = $this->resolveNode($consumerName, 'consuming_node');
        if (is_int($consumer)) {
            return $consumer;
        }

        $serving = $this->resolveNode($servingName, 'serving_node');
        if (is_int($serving)) {
            return $serving;
        }

        if ($consumer->id === $serving->id) {
            return $this->failCommand(
                code: 'node.grant_policy_violation',
                message: 'A node cannot be granted access to itself.',
                meta: [
                    'consuming_node' => $consumerName,
                    'serving_node' => $servingName,
                    'reason' => 'self_grant',
                ],
                humanMessage: "Grant from '{$consumerName}' to '{$servingName}' violates node access policy.",
            );
        }

        $grant = NodeAccess::query()->firstOrCreate([
            'consumer_node_id' => $consumer->id,
            'serving_node_id' => $serving->id,
        ]);

        $alreadyGranted = ! $grant->wasRecentlyCreated;

        return $this->respondSuccess($consumerName, $servingName, $alreadyGranted);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function respondForwardedSuccess(string $fallbackConsumerName, string $fallbackServingName, array $data): int
    {
        $consumerName = $data['consuming_node'] ?? $fallbackConsumerName;
        $servingName = $data['serving_node'] ?? $fallbackServingName;
        $alreadyGranted = $data['already_granted'] ?? false;

        return $this->respondSuccess(
            is_string($consumerName) && $consumerName !== '' ? $consumerName : $fallbackConsumerName,
            is_string($servingName) && $servingName !== '' ? $servingName : $fallbackServingName,
            is_bool($alreadyGranted) ? $alreadyGranted : false,
        );
    }

    private function respondSuccess(string $consumerName, string $servingName, bool $alreadyGranted): int
    {
        $data = [
            'consuming_node' => $consumerName,
            'serving_node' => $servingName,
            'action' => 'granted',
            'already_granted' => $alreadyGranted,
        ];

        if ($this->wantsJson()) {
            $this->line(json_encode([
                'success' => [
                    'data' => $data,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        if ($alreadyGranted) {
            $this->line("'{$consumerName}' already has access to '{$servingName}'");
        } else {
            $this->line("Granted '{$consumerName}' access to '{$servingName}'");
        }

        return self::SUCCESS;
    }

    private function resolveNode(string $name, string $field): Node|int
    {
        $node = Node::query()
            ->where('name', $name)
            ->where('status', 'active')
            ->first();

        if (! $node instanceof Node) {
            $label = $field === 'consuming_node' ? 'Consuming' : 'Serving';

            return $this->failCommand(
                code: 'node.not_found',
                message: "{$label} node '{$name}' not found.",
                meta: [
                    'field' => $field,
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
