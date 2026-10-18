<?php

declare(strict_types=1);

namespace App\Domain\Problems;

use App\Actions\Doctor\RunDoctorAction;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Gateway\GatewayQueue;
use App\Domain\Shared\StoredInteger;
use App\Infrastructure\Gateway\GatewayQueueRedis;
use App\Jobs\Problems\CollectDoctorResource;
use App\Jobs\Problems\CollectProblemSignals;
use App\Jobs\Problems\DispatchProblemBatch;
use App\Jobs\Problems\ObserveProblemNode;
use App\Models\Node;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use RuntimeException;

/** Collection yields between bounded probe groups, and never scans in the scheduler. */
final readonly class ProblemCollection
{
    public function __construct(private RunDoctorAction $doctor) {}

    public function dispatch(): void
    {
        if (! GatewayQueueRedis::configured()) {
            throw new RuntimeException('Problem collection needs the Gateway queue, but ORBIT_GATEWAY_QUEUE_CONNECTION is empty.');
        }
        $collection = (string) Str::uuid();
        $nodes = Node::query()->orderBy('id')->get();
        $ids = StoredInteger::listFrom($nodes->modelKeys());
        $observations = [];
        $families = [];
        foreach ($nodes as $node) {
            $id = $node->id;
            $observations[] = new ObserveProblemNode($collection, $ids, $id);
            foreach (DoctorFamily::cases() as $family) {
                foreach ($this->doctor->probe($family)->resources($node) as $resource) {
                    $job = new CollectDoctorResource($collection, $ids, $id, $family, $resource);
                    $families[] = $job;
                }
            }
        }
        $jobs = [];
        if ($observations !== []) {
            $jobs[] = new DispatchProblemBatch(Bus::batch($observations)->allowFailures()->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::Problems->value));
            $jobs[] = new DispatchProblemBatch(Bus::batch($families)->allowFailures()->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::Problems->value));
        }
        $jobs[] = new CollectProblemSignals($collection, $ids);
        Bus::chain($jobs)->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::Problems->value)->dispatch();
    }

    public static function contextKey(string $collection, int $node): string
    {
        return 'problems:collection:'.$collection.':node:'.$node;
    }
}
