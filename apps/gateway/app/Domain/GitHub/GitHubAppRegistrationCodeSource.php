<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

use App\Domain\Gateway\GatewayServingHost;
use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;

final readonly class GitHubAppRegistrationCodeSource implements OperationSecretSource
{
    public function __construct(private GitHubAppStore $store, private GatewayServingHost $gateway) {}

    public function name(): string
    {
        return 'github.app.registration_code';
    }

    public function targetTypes(): array
    {
        return ['node'];
    }

    public function ownerId(Operation $operation): ?int
    {
        return $operation->kind === 'github.app_register' && $operation->node_id === $operation->target_id
            && $operation->node_id === $this->gateway->nodeId() ? $operation->node_id : null;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        $exchange = $this->store->exchange();
        if ($this->ownerId($operation) !== $id || $key !== '' || $parameter !== 'registration_code'
            || $operation->status->terminal() || $operation->cancel_requested_at !== null
            || ($exchange['operation_id'] ?? null) !== $operation->id
            || ($exchange['registration_id'] ?? null) !== ($operation->input['registration_id'] ?? null)
            || ! is_string($exchange['code'] ?? null) || $exchange['code'] === '') {
            throw new ResourceOperationException('github.registration_invalid', 'The private code does not belong to this registration.', 403);
        }

        return new OperationSecretValue($exchange['code']);
    }
}
