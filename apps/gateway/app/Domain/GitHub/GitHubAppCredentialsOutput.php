<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

use App\Domain\Gateway\GatewayServingHost;
use App\Domain\Operations\OperationSecretOutputStore;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Stores the existing App credential type; never exports the retained signing key. */
final readonly class GitHubAppCredentialsOutput implements OperationSecretOutputStore
{
    public function __construct(private GitHubAppStore $store, private GatewayServingHost $gateway) {}

    public function name(): string
    {
        return 'github.app.credentials';
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

    public function store(Operation $operation, int $ownerId, #[\SensitiveParameter] mixed $value): void
    {
        $exchange = $this->store->exchange();
        $fields = ['app_id', 'slug', 'name', 'owner', 'owner_type', 'url', 'private_key'];
        if ($this->ownerId($operation) !== $ownerId || $operation->status->terminal() || $operation->cancel_requested_at !== null
            || ($exchange['operation_id'] ?? null) !== $operation->id
            || ($exchange['registration_id'] ?? null) !== ($operation->input['registration_id'] ?? null)
            || ! is_array($value) || count($value) !== count($fields) || array_diff($fields, array_keys($value)) !== []
            || ! is_int($value['app_id']) || $value['app_id'] < 1
            || array_any(array_diff($fields, ['app_id']), static fn (string $field): bool => ! is_string($value[$field]) || $value[$field] === '')
            || ! in_array($value['owner_type'], ['user', 'organization'], true)) {
            throw new ResourceOperationException('github.registration_invalid', 'The protected App output does not belong to this registration.', 422);
        }
        $privateKey = ValidatedData::string($value['private_key']);
        $key = openssl_pkey_get_private($privateKey);
        if ($key === false || (openssl_pkey_get_details($key)['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
            throw new ResourceOperationException('github.registration_invalid', 'The App signing key is invalid.', 422);
        }
        $this->store->put(new GitHubAppCredentials($value['app_id'], ValidatedData::string($value['slug']), ValidatedData::string($value['name']), ValidatedData::string($value['owner']), $value['owner_type'], ValidatedData::string($value['url']), $privateKey));
        $this->store->consumeExchangeCode($operation->id);
    }
}
