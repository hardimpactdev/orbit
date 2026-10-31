<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\WorkerKnownHostsPublisher;
use App\Domain\Operations\OperationBroadcaster;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStore;
use App\Infrastructure\Swamp\SwampWorkerKnownHostsInput;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Admit public pin publication; coalesce changes while its one native run is unfinished. */
final readonly class AdmitWorkerKnownHostsAction implements WorkerKnownHostsPublisher
{
    public const string Kind = 'node.worker_known_hosts';

    public function __construct(private SwampWorkerKnownHostsInput $input, private OperationBroadcaster $broadcaster) {}

    public function publish(): void
    {
        $this->execute();
    }

    public function execute(): Operation
    {
        return DB::transaction(function (): Operation {
            Node::query()->whereKey(209)->lockForUpdate()->firstOrFail();
            $latest = Operation::query()->where('kind', self::Kind)->latest('id')->first();
            if ($latest !== null && ! $latest->status->terminal()) {
                return $latest;
            }
            $intent = $this->input->capture();
            if ($latest !== null && (ValidatedData::object($latest->input['intent'])['digest'] ?? null) === $intent['digest']) {
                return $latest;
            }

            // This automatic intent must not inherit the pin caller's HTTP idempotency scope.
            $idempotency = new OperationIdempotency;
            $operations = new StartOperationAction(new OperationStore($this->broadcaster, $idempotency), $idempotency);

            return $operations->execute(self::Kind, 'node', 209, 209, ['intent' => $intent], 'node-pins', null);
        });
    }
}
