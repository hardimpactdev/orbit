<?php

declare(strict_types=1);

namespace App\Domain\Problems;

use App\Actions\Doctor\DatabaseConnectionDoctorProbe;
use App\Actions\Doctor\RouteDoctorProbe;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Gateway\GatewayQueue;
use App\Domain\Shared\StoredInteger;
use App\Infrastructure\Gateway\GatewayQueueRedis;
use App\Jobs\Problems\CollectDoctorResource;
use App\Jobs\Problems\CollectProblemSignals;
use App\Jobs\Problems\CompleteDoctorResource;
use App\Jobs\Problems\DispatchProblemBatch;
use App\Jobs\Problems\ObserveProblemNode;
use App\Models\FirewallRule;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Process;
use App\Models\Project;
use App\Models\RouteCustomProxy;
use App\Models\Schedule;
use App\Models\Tool;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use RuntimeException;
use UnexpectedValueException;

/** Each queued inspection yields to release handoff within five seconds. */
final readonly class ProblemCollection
{
    public function dispatch(): void
    {
        if (! GatewayQueueRedis::configured()) {
            throw new RuntimeException('Problem collection needs the Gateway queue, but ORBIT_GATEWAY_QUEUE_CONNECTION is empty.');
        }
        $collection = (string) Str::uuid();
        $ids = StoredInteger::listFrom(Node::query()->orderBy('id')->pluck('id')->all());
        $observations = [];
        $families = [];
        $completion = [];
        foreach ($ids as $id) {
            $observations[] = new ObserveProblemNode($collection, $ids, $id);
            foreach (DoctorFamily::cases() as $family) {
                foreach ($this->resources($id, $family) as $resource) {
                    $job = new CollectDoctorResource($collection, $ids, $id, $family, $resource);
                    $families[] = $job;
                    $completion[] = new CompleteDoctorResource($job);
                }
            }
        }
        $jobs = [];
        if ($observations !== []) {
            $jobs[] = new DispatchProblemBatch(Bus::batch($observations)->allowFailures()->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::Tasks->value));
            $jobs[] = new DispatchProblemBatch(Bus::batch($families)->allowFailures()->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::Tasks->value));
            $jobs[] = new DispatchProblemBatch(Bus::batch($completion)->allowFailures()->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::Tasks->value));
        }
        $jobs[] = new CollectProblemSignals($collection, $ids);
        Bus::chain($jobs)->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::Tasks->value)->dispatch();
    }

    /** @return list<int|string> */
    private function resources(int $nodeId, DoctorFamily $family): array
    {
        $instances = Instance::query()->where('node_id', $nodeId)->pluck('id');

        return array_map(static fn (mixed $id): int|string => is_int($id) || is_string($id) ? $id : throw new UnexpectedValueException('Invalid Doctor resource ID.'), array_values(match ($family) {
            DoctorFamily::Node => [$nodeId],
            DoctorFamily::Role => NodeRole::query()->where('node_id', $nodeId)->pluck('id')->all(),
            DoctorFamily::Project => Project::query()->whereIn('id', Instance::query()->where('node_id', $nodeId)->select('project_id'))->pluck('id')->all(),
            DoctorFamily::Instance => $instances->all(),
            DoctorFamily::Process => Process::query()->where(fn ($query) => $query
                ->where(fn ($query) => $query->where('owner_type', Node::class)->where('owner_id', $nodeId))
                ->orWhere(fn ($query) => $query->whereIn('owner_type', Instance::morphTypes())->whereIn('owner_id', $instances)))
                ->pluck('id')->all(),
            DoctorFamily::Schedule => [...Schedule::query()->where('host_node_id', $nodeId)->pluck('id')->all(), 'inventory'],
            DoctorFamily::Tool => [...Tool::query()->where('node_id', $nodeId)->pluck('id')->all(), ...Instance::query()->where('node_id', $nodeId)->whereNotNull('vp_environment')->get(['id'])->map(static fn (Instance $instance): string => 'instance:'.$instance->id)->all(), 'inventory'],
            DoctorFamily::Firewall => [...FirewallRule::query()->where('node_id', $nodeId)->pluck('id')->all(), 'inventory'],
            DoctorFamily::DatabaseConnection => app(DatabaseConnectionDoctorProbe::class)->resources(Node::query()->findOrFail($nodeId)),
            DoctorFamily::Route => array_values(array_unique(StoredInteger::listFrom([...app(RouteDoctorProbe::class)->servedRoutes(Node::query()->findOrFail($nodeId))->pluck('id')->all(), ...RouteCustomProxy::query()->where('node_id', $nodeId)->pluck('route_id')->all()]))),
        }));
    }

    public static function contextKey(string $collection, int $node): string
    {
        return 'problems:collection:'.$collection.':node:'.$node;
    }
}
