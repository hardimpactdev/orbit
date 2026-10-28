<?php

declare(strict_types=1);

namespace App\Domain\Integrations;

use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Settings\SettingValueProtection;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;

final readonly class IntegrationSecretSource implements OperationSecretSource
{
    public function __construct(private SettingRepository $settings) {}

    public function name(): string
    {
        return 'integration.payload';
    }

    public function ownerId(Operation $operation): ?int
    {
        return isset($operation->input['effects']) && preg_match('/\A(?:ai_router|analytics|metrics)\./D', $operation->kind) === 1 ? $operation->target_id : null;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        if ($this->ownerId($operation) !== $id) {
            throw new ResourceOperationException('operation.execution_forbidden', 'This protected integration payload has another owner.', 422);
        }
        $stored = $this->settings->get(new SettingScope(SettingScopeType::Gateway), 'integration.operation.'.$operation->id);
        $values = $stored === null ? null : json_decode($stored, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($values) || ! array_key_exists($parameter, $values[$key] ?? [])) {
            throw new ResourceOperationException('operation.secret_unavailable', 'The admitted integration payload is unavailable.', 409);
        }

        return new OperationSecretValue($values[$key][$parameter]);
    }

    /** @param array<string, array<string, mixed>> $values */
    public function store(Operation $operation, #[\SensitiveParameter] array $values): void
    {
        $this->settings->put(new SettingScope(SettingScopeType::Gateway), 'integration.operation.'.$operation->id,
            json_encode($values, JSON_THROW_ON_ERROR), SettingValueProtection::Secret);
    }
}
