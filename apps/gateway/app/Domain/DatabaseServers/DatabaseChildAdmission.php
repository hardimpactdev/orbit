<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Actions\DatabaseServers\RecordDatabaseOperationAction;
use App\Domain\Operations\OperationChildAdmission;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseExecutionIntent;
use App\Models\Operation;

/** Kit B supplies the proven keyed intent; admission preserves its recorded target and Node. */
final readonly class DatabaseChildAdmission implements OperationChildAdmission
{
    public function __construct(private RecordDatabaseOperationAction $records) {}

    public function admit(Operation $parent, array $intent): Operation
    {
        $record = DatabaseExecutionIntent::recorded($intent['input']['intent_id'] ?? null);
        $admission = $record->payload['admission'] ?? [];
        $input = [...($admission['input'] ?? []), 'intent_id' => $record->id];
        if (($admission['kind'] ?? null) !== $intent['kind'] || $record->node_id !== $intent['node_id']
            || $input !== $intent['input']) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The child does not match recorded database intent.', 422);
        }

        return $this->records->start(new PreparedDatabaseOperation($intent['kind'], $record->target_type, $record->target_id, $record->node_id, $input));
    }
}
