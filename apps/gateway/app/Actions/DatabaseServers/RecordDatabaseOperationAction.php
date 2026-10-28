<?php

declare(strict_types=1);

namespace App\Actions\DatabaseServers;

use App\Actions\Operations\StartOperationAction;
use App\Domain\DatabaseServers\PreparedDatabaseOperation;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseExecutionIntent;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/** Records protected intent; never invokes a Node or dispatches a PHP executor. */
final readonly class RecordDatabaseOperationAction
{
    public function __construct(private StartOperationAction $operations) {}

    /** @param array<string, mixed> $input
     * @param  array<string, array<string, mixed>>  $commands
     * @param  array<string, mixed>  $projection
     */
    public function execute(string $kind, string $targetType, int $targetId, int $nodeId, array $input, #[SensitiveParameter] array $commands, array $projection = []): Operation
    {
        return DB::transaction(fn (): Operation => $this->start($this->prepare($kind, $targetType, $targetId, $nodeId, $input, $commands, $projection)));
    }

    public function start(PreparedDatabaseOperation $prepared): Operation
    {
        if (! OperationKinds::supports('children') || ! OperationKinds::supports('receipts')) {
            throw new ResourceOperationException('operation.kind_unavailable', 'Database execution requires child Operations and effect receipts.', 503);
        }

        return $this->operations->execute($prepared->kind, $prepared->targetType, $prepared->targetId, $prepared->nodeId,
            $prepared->input, 'operator', request()->attributes->getString('orbit.request_id'));
    }

    /** @param array<string, mixed> $input
     * @param  array<string, array<string, mixed>>  $commands
     * @param  array<string, mixed>  $projection
     */
    public function prepare(string $kind, string $targetType, int $targetId, int $nodeId, array $input, #[SensitiveParameter] array $commands, array $projection = []): PreparedDatabaseOperation
    {
        $intent = DatabaseExecutionIntent::query()->create([
            'target_type' => $targetType, 'target_id' => $targetId, 'node_id' => $nodeId,
            'payload' => ['commands' => $commands, 'projection' => $projection, 'admission' => ['kind' => $kind, 'input' => $input]],
        ]);

        return new PreparedDatabaseOperation($kind, $targetType, $targetId, $nodeId,
            [...$input, 'intent_id' => $intent->id]);
    }
}
