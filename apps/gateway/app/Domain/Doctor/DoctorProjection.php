<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

use App\Data\Doctor\DoctorFamilyReportData;
use App\Data\Doctor\DoctorIssueData;
use App\Data\Doctor\DoctorNodeReportData;
use App\Data\Doctor\DoctorReportData;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Problems\ProblemCollector;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Records native reports and their fingerprint recovery scope, without executing probes. */
final readonly class DoctorProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        return array_intersect_key($operation->input, array_flip(['nodes', 'families', 'collect_problems', 'logs', 'participant_node_ids']));
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation = Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail();
            $wasTerminal = $operation->status->terminal();
            $native = ValidatedData::object($data['result'] ?? []);
            $report = isset($native['report']) ? $this->report(ValidatedData::object($native['report'])) : null;
            $result = $report === null ? [] : ['report' => $report->toArray()];
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if (! $wasTerminal && $projected->status === OperationStatus::Succeeded && ($operation->input['collect_problems'] ?? false)) {
                $collector = app(ProblemCollector::class);
                if ($report !== null) {
                    $collector->recordDoctor($report, $operation->started_at ?? Carbon::parse(Arr::string($data, 'started_at')));
                }
                if (isset($native['logs'])) {
                    $input = ValidatedData::object($operation->input['logs'] ?? []);
                    $collector->recordLogs(ValidatedData::object($native['logs']), isset($input['cursor']) ? ValidatedData::object($input['cursor']) : null);
                }
                foreach ($collector->collect() as $error) {
                    report($error);
                }
            }

            return $projected;
        });
    }

    /** @param array<string, mixed> $native */
    private function report(array $native): DoctorReportData
    {
        $nodes = [];
        foreach (Arr::array($native, 'nodes') as $value) {
            $node = ValidatedData::object($value);
            $families = [];
            foreach (Arr::array($node, 'families') as $value) {
                $row = ValidatedData::object($value);
                $family = DoctorFamily::from(Arr::string($row, 'family'));
                $issues = [];
                foreach (Arr::array($row, 'issues') as $value) {
                    $issue = ValidatedData::object($value);
                    $issues[] = DoctorIssueData::fromInternal($family,
                        Arr::string($issue, 'code'), DoctorIssueKind::from(Arr::string($issue, 'kind')),
                        isset($issue['resource_id']) ? $this->resourceId($issue['resource_id']) : null,
                        ValidatedData::nullableString($issue['resource_name'] ?? null), Arr::string($issue, 'summary'),
                        $this->fact($issue['expected'] ?? null), $this->fact($issue['observed'] ?? null));
                }
                $families[] = DoctorFamilyReportData::fromIssues($family, Arr::integer($row, 'checked'), $issues,
                    $this->resourceIds($row['observed_resources'] ?? []),
                    array_map(ValidatedData::stringList(...), Arr::array($row, 'observed_checks', [])),
                    array_map($this->resourceIds(...), ValidatedData::object($row['observed_inventories'] ?? [])));
            }
            $nodes[] = DoctorNodeReportData::fromFamilies(Arr::integer($node, 'node_id'), Arr::string($node, 'node_name'), $families);
        }

        return DoctorReportData::fromNodes($nodes);
    }

    private function resourceId(mixed $value): int|string
    {
        return is_int($value) ? $value : ValidatedData::string($value);
    }

    /** @return list<int|string> */
    private function resourceIds(mixed $values): array
    {
        return array_values(array_map($this->resourceId(...), Arr::array(['values' => $values], 'values')));
    }

    private function fact(mixed $value): bool|string|null
    {
        return is_bool($value) || $value === null ? $value : ValidatedData::string($value);
    }
}
