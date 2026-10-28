<?php

declare(strict_types=1);

namespace App\Domain\Integrations;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Operations\OperationChildAdmission;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Shared B binds the intent and scopes the child target hold around this admission. */
final readonly class IntegrationRoleAdmission implements OperationChildAdmission
{
    public function __construct(private StartOperationAction $operations) {}

    public function admit(Operation $parent, array $intent): Operation
    {
        $kind = ValidatedData::string($intent['kind']);
        $input = ValidatedData::object($intent['input']);
        $remove = str_ends_with($kind, '.role_remove');
        if (preg_match('/\A(?:metrics|analytics|websocket)\.role_(?:converge|remove)\z/D', $kind) !== 1
            || ($remove ? (count($input) !== 2 || ! is_bool($input['purge_data'] ?? null) || ! is_bool($input['offline'] ?? null)) : $input !== [])) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The child has another integration role contract.', 422);
        }
        $nodeId = ValidatedData::integer($intent['node_id']);

        return $this->operations->execute($kind, 'node', $nodeId, $nodeId, $input, 'integration', null);
    }
}
