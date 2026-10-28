<?php

declare(strict_types=1);

namespace Orbit\Extensions\Vault\Domain;

use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Settings\SettingValueProtection;
use App\Models\Operation;
use App\Support\ValidatedData;
use SensitiveParameter;

/** Immutable encrypted admission input. The shared executor alone hydrates this secret reference. */
final readonly class VaultOperationSecrets implements OperationSecretSource
{
    public function __construct(private SettingRepository $settings) {}

    /** @param array<string, mixed> $configuration */
    public function store(Operation $operation, #[SensitiveParameter] array $configuration): void
    {
        $this->settings->put($this->scope(), $this->key($operation), json_encode($configuration, JSON_THROW_ON_ERROR), SettingValueProtection::Secret);
    }

    public function name(): string
    {
        return 'vault.credentials';
    }

    public function ownerId(Operation $operation): ?int
    {
        return str_starts_with($operation->kind, 'vault.') && $operation->node_id === $operation->target_id ? $operation->target_id : null;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        $configuration = $this->snapshot($operation, ['$secret' => $this->name(), 'id' => $id]);
        $redactions = [$configuration['client_secret'] ?? '', $configuration['master_password'] ?? ''];
        foreach ($configuration['wanted'] ?? [] as $item) {
            foreach ($item['cipher']['fields'] ?? [] as $field) {
                if (($field['hidden'] ?? false) === true && is_string($field['value'] ?? null)) {
                    $redactions[] = $field['value'];
                }
            }
        }

        return new OperationSecretValue(new VaultExecutionInput($configuration), array_values(array_filter($redactions, is_string(...))));
    }

    /** @param array<string, mixed> $reference
     * @return array<string, mixed>
     */
    public function snapshot(Operation $operation, array $reference): array
    {
        if (! str_starts_with($operation->kind, 'vault.') || $reference !== ['$secret' => 'vault.credentials', 'id' => $operation->target_id]
            || $operation->node_id !== $operation->target_id) {
            throw new VaultException('operation.execution_forbidden', 'The Vault reference does not belong to this Operation.', 422);
        }
        $stored = $this->settings->get($this->scope(), $this->key($operation));
        if ($stored === null) {
            throw new VaultException('vault.intent_missing', 'The Vault admission input is missing.', 409);
        }

        return ValidatedData::object(json_decode($stored, true, flags: JSON_THROW_ON_ERROR));
    }

    public function forget(Operation $operation): void
    {
        $this->settings->delete($this->scope(), $this->key($operation));
    }

    private function scope(): SettingScope
    {
        return new SettingScope(SettingScopeType::Gateway);
    }

    private function key(Operation $operation): string
    {
        return 'vault.operation.'.$operation->id;
    }
}
