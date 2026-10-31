<?php

declare(strict_types=1);

namespace App\Actions\Operations;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStore;
use App\Infrastructure\Swamp\StartSwampRun;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

/** Records validated intent, then submits its single native workflow after commit. */
final readonly class StartOperationAction
{
    public function __construct(private OperationStore $store, private OperationIdempotency $idempotency) {}

    /** @param array<string,mixed> $input
     * @param  list<string>  $supersedes
     */
    public function execute(string $kind, string $targetType, int $targetId, ?int $nodeId, array $input, ?string $triggeredBy, ?string $requestId, array $supersedes = [], ?string $expectedWriter = null): Operation
    {
        app(DirectOperation::class)->writer($kind);
        if (($existing = $this->idempotency->replay($kind)) !== null) {
            return $existing;
        }
        $operation = $this->store->create($kind, $targetType, $targetId, $nodeId, $input, $triggeredBy, $requestId);
        if ($operation->wasRecentlyCreated) {
            DB::afterCommit(static fn () => app(StartSwampRun::class)->start($operation));
        }

        return $operation;
    }
}
