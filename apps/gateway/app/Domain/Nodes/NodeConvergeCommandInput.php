<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\OperationSecrets;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Full public native input. Execution-copy references are added by the shared command-step helper. */
final readonly class NodeConvergeCommandInput
{
    /** @return array<string,mixed> */
    public function forOperation(Operation $operation): array
    {
        $intent = self::snapshot($operation);
        $references = [];
        if (array_any($intent['artifacts'] ?? [], static fn (array $artifact): bool => ($artifact['name'] ?? null) === 'agent' && ($artifact['required'] ?? false) === true)) {
            $value = app(OperationSecrets::class)->source('node.agent_secret_hash')
                ->resolve($operation, $operation->node_id, '', 'secret_hash')->value;
            $references['secret_hash'] = app(SwampCommandStep::class)->executionCopy(
                'orbit-node-inputs', 'operation-'.$operation->id.'-agent-secret-hash', json_encode($value, JSON_THROW_ON_ERROR),
            );
        }

        return ['intent' => $intent, 'execution_secrets' => $references];
    }

    /** Pure admitted snapshot; terminal projection must not synchronize execution copies again.
     * @return array<string,mixed>
     */
    public static function snapshot(Operation $operation): array
    {
        $intent = ValidatedData::object($operation->input['_converge'] ?? []);
        unset($intent['secret_hash']);

        return $intent;
    }
}
