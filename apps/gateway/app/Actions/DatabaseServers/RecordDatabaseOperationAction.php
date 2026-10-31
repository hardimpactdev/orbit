<?php

declare(strict_types=1);

namespace App\Actions\DatabaseServers;

use App\Actions\Operations\StartOperationAction;
use App\Domain\DatabaseServers\PreparedDatabaseOperation;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Models\DatabaseExecutionIntent;
use App\Models\Node;
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
        if (! app(OperationKinds::class)->directOnly($prepared->kind) && (! OperationKinds::supports('children') || ! OperationKinds::supports('receipts')
            || ($prepared->kind === 'database.sqlite_seed_abandon' && ! OperationKinds::supports('compensations')))) {
            throw new ResourceOperationException('operation.kind_unavailable', 'This database kind requires its kit execution capabilities.', 503);
        }

        $input = $prepared->input;
        if (app(OperationKinds::class)->directOnly($prepared->kind)) {
            if ($prepared->kind !== 'database.create' || ($input['driver'] ?? null) !== 'redis') {
                $input['agent_sha256'] = app(AgentDelivery::class)->pin(Node::query()->findOrFail($prepared->nodeId));
            }
            foreach (array_keys(app(DirectOperation::class)->definition($prepared->kind)['private_material'] ?? []) as $parameter) {
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
