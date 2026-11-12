<?php

declare(strict_types=1);

namespace App\Actions\Doctor;

use App\Data\Doctor\DoctorReportData;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\FactDoctor;
use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Domain\Problems\ProblemCollector;
use App\Models\Node;
use App\Models\ProblemCollectorState;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Doctor answers from the Gateway's state, the facts each Node's agent reported: no run, no SSH. */
final readonly class RunDoctorAction
{
    public function __construct(private NodeAccessAuthorizer $access, private FactDoctor $doctor, private ProblemCollector $problems) {}

    /**
     * Every Node the caller can reach, or one Node.
     *
     * @param  list<DoctorFamily>  $families  all families when empty
     */
    public function execute(Node $consumer, ?int $nodeId, array $families): DoctorReportData
    {
        if ($nodeId !== null && ! $this->access->allows($consumer, Node::query()->findOrFail($nodeId))) {
            throw new AuthorizationException;
        }
        $nodes = Node::query()->whereIn('id', $nodeId === null ? $this->access->accessibleNodeIds($consumer) : [$nodeId])->orderBy('name')->orderBy('id')->get();

        return $this->doctor->report(array_values($nodes->all()), $families);
    }

    /**
     * The fleet's Problems: Doctor's findings for every Node, or only the Gateway's Processes, then the Gateway's logs
     * and the activity and assistance signals.
     */
    public function collect(bool $horizonOnly = false): DoctorReportData
    {
        $report = $this->collectDoctor($horizonOnly);
        $this->problems->collectLogs();
        $this->problems->recordSignals($this->problems->signals());

        return $report;
    }

    /**
     * Doctor's shared recording step; other signal sources remain request-only.
     *
     * @param  array{write_ms: float, nodes: int, issues: int}|null  $cost
     *
     * @param-out array{write_ms: float, nodes: int, issues: int} $cost
     */
    public function collectDoctor(bool $horizonOnly = false, ?array &$cost = null): DoctorReportData
    {
        $started = hrtime(true);
        $cost = ['write_ms' => 0.0, 'nodes' => 0, 'issues' => 0];
        $at = Carbon::now();
        $nodes = Node::query()->when($horizonOnly, fn ($query) => $query->whereKey($this->access->activeGatewayId()))->orderBy('name')->orderBy('id')->get();
        $cost['nodes'] = $nodes->count();
        $report = $this->doctor->report(array_values($nodes->all()), $horizonOnly ? [DoctorFamily::Process] : []);
        foreach ($report->nodes as $nodeReport) {
            foreach ($nodeReport->families as $family) {
                foreach ($family->issues as $issue) {
                    $cost['issues'] += (int) ProblemCollector::recordsDoctorIssue($issue);
                }
            }
        }
        $writeStarted = hrtime(true);
        try {
            DB::transaction(function () use ($report, $at, $horizonOnly, $started, $writeStarted): void {
                $this->problems->recordDoctor($report, $at);
                if (! $horizonOnly) {
                    $state = ProblemCollectorState::query()->lockForUpdate()->orderBy('id')->firstOrCreate();
                    $measured = hrtime(true);
                    // Stored durations end before this state save: collection start to here, and transaction start to here.
                    // The log below measures the whole scheduled call and the transaction through its commit or rollback.
                    // doctor_started_at equals the doctor_observed_at this run wrote on every finding it saw.
                    $state->observation = [...($state->observation ?? []), 'doctor_collected_at' => Carbon::now()->toISOString(),
                        'doctor_started_at' => $at->toISOString(),
                        'doctor_duration_ms' => round(($measured - $started) / 1_000_000, 3),
                        'doctor_write_ms' => round(($measured - $writeStarted) / 1_000_000, 3)];
                    $state->save();
                }
            });
        } finally {
            $cost['write_ms'] = round((hrtime(true) - $writeStarted) / 1_000_000, 3);
        }

        return $report;
    }

    /** One report per failure episode; a successful collection permits the next failure to be reported. */
    public function scheduled(): void
    {
        $started = hrtime(true);
        $cost = ['write_ms' => 0.0, 'nodes' => 0, 'issues' => 0];
        try {
            $this->collectDoctor(cost: $cost);
            Cache::forget('doctor:collect:reported');
        } catch (Throwable $exception) {
            if (Cache::add('doctor:collect:reported', true)) {
                report($exception);
            }
        } finally {
            Log::info('Doctor collection ran.', ['duration_ms' => round((hrtime(true) - $started) / 1_000_000, 3), ...$cost]);
        }
    }
}
