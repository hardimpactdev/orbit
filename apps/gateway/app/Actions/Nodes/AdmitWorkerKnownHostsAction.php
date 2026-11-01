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

/** Admit a complete current public pin snapshot for each publication request. */
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
        Node::query()->findOrFail(209);

        // This automatic intent must not inherit the pin caller's HTTP idempotency scope.
        $idempotency = new OperationIdempotency;
        $operations = new StartOperationAction(new OperationStore($this->broadcaster, $idempotency), $idempotency);

        return $operations->execute(self::Kind, 'node', 209, 209, ['intent' => $this->input->capture()], 'node-pins', null);
    }
}
