<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\SwampNode;
use App\Domain\Nodes\WorkerKnownHostsPublisher;
use App\Domain\Operations\OperationBroadcaster;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStore;
use App\Infrastructure\Swamp\SwampWorkerKnownHostsInput;
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
        $swamp = SwampNode::id();

        // This automatic intent must not inherit the pin caller's HTTP idempotency scope.
        $idempotency = new OperationIdempotency;
        $operations = new StartOperationAction(new OperationStore($this->broadcaster, $idempotency), $idempotency);

        return $operations->execute(self::Kind, 'node', $swamp, $swamp, ['intent' => $this->input->capture()], 'node-pins', null);
    }
}
