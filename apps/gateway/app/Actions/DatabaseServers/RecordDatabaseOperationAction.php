<?php

declare(strict_types=1);

namespace App\Actions\DatabaseServers;

use App\Actions\Operations\StartOperationAction;
use App\Domain\DatabaseServers\PreparedDatabaseOperation;
use App\Domain\Operations\DirectOperation;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseExecutionIntent;
use App\Models\Operation;
use Illuminate\Support\Arr;
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
        $definition = app(DirectOperation::class)->definition($prepared->kind);
        if ($definition === null) {
            throw new ResourceOperationException('operation.kind_unavailable', 'This database kind has no native Swamp workflow.', 503);
        }

        $input = $prepared->input;
        $intent = DatabaseExecutionIntent::recorded($input['intent_id']);
        foreach (array_keys($definition['private_material'] ?? []) as $parameter) {
            if (in_array($parameter, $intent->payload['commands']['database-apply']['protected'] ?? [], true)) {
                Arr::set($input, $parameter, ['$secret' => 'database.intent', 'id' => $input['intent_id']]);
            }
        }

        return $this->operations->execute($prepared->kind, $prepared->targetType, $prepared->targetId, $prepared->nodeId,
            $input, 'operator', request()->attributes->getString('orbit.request_id'));
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
