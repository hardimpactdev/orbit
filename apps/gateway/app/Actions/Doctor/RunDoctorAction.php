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

    /** Doctor's shared recording step; other signal sources remain request-only. */
    public function collectDoctor(bool $horizonOnly = false): DoctorReportData
    {
        $at = Carbon::now();
        $nodes = Node::query()->when($horizonOnly, fn ($query) => $query->whereKey($this->access->activeGatewayId()))->orderBy('name')->orderBy('id')->get();
        $report = $this->doctor->report(array_values($nodes->all()), $horizonOnly ? [DoctorFamily::Process] : []);
        DB::transaction(function () use ($report, $at, $horizonOnly): void {
            $this->problems->recordDoctor($report, $at);
            if (! $horizonOnly) {
                $state = ProblemCollectorState::query()->lockForUpdate()->orderBy('id')->firstOrCreate();
                $state->observation = [...($state->observation ?? []), 'doctor_collected_at' => Carbon::now()->toISOString()];
                $state->save();
            }
        });

        return $report;
    }

    /** One report per failure episode; a successful collection permits the next failure to be reported. */
    public function scheduled(): void
    {
        try {
            $this->collectDoctor();
            Cache::forget('doctor:collect:reported');
        } catch (Throwable $exception) {
            if (Cache::add('doctor:collect:reported', true)) {
                report($exception);
            }
        }
    }
}
