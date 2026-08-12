<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Data\Doctor\DoctorIssue;
use App\Exceptions\RemoteShellFailed;
use App\Models\Node;
use App\Services\RemoteShell\RemoteLocalExecutorTransportFailed;

final readonly class DoctorFamilyProbeRunner
{
    public function __construct(
        private DoctorIssueFactory $doctorIssueFactory,
    ) {}

    /**
     * @param  (callable(string, 'running'|'done', list<array<string, mixed>>, ?int, ?int): void)|null  $onFamilyProgress
     * @param  callable(callable(DoctorIssue): void, callable(): void): void  $probe
     * @return list<DoctorIssue>
     * @mago-expect lint:excessive-parameter-list
     */
    public function run(
        Node $node,
        string $family,
        int $total,
        ?string $key,
        ?callable $onFamilyProgress,
        callable $probe,
    ): array {
        $issues = [];
        $completed = 0;

        $this->reportProgress(
            $onFamilyProgress,
            $family,
            'running',
            progress: $total === 0 ? null : ['completed' => 0, 'total' => $total],
        );

        $addIssue = static function (DoctorIssue $issue) use (&$issues): void {
            $issues[] = $issue;
        };
        $advance = function () use (&$completed, $onFamilyProgress, $family, $total): void {
            $completed++;

            if ($completed >= $total) {
                return;
            }

            $this->reportProgress(
                $onFamilyProgress,
                $family,
                'running',
                progress: ['completed' => $completed, 'total' => $total],
            );
        };

        try {
            $probe($addIssue, $total === 0 ? static function (): void {} : $advance);
        } catch (RemoteShellFailed|RemoteLocalExecutorTransportFailed $exception) {
            $this->appendProbeFailure($node, $family, $exception, $issues);
        }

        $this->reportProgress(
            $onFamilyProgress,
            $family,
            'done',
            $this->filterIssuesByKey($issues, $key),
        );

        return $issues;
    }

    /**
     * @param  list<DoctorIssue>  $issues
     */
    private function appendProbeFailure(
        Node $node,
        string $family,
        RemoteShellFailed|RemoteLocalExecutorTransportFailed $exception,
        array &$issues,
    ): void {
        $alreadyAttributed = array_any(
            $issues,
            static fn (DoctorIssue $issue): bool => str_ends_with($issue->key, 'probe_failed'),
        );

        if ($alreadyAttributed) {
            return;
        }

        $issues[] = $this->doctorIssueFactory->fromProbeFailure(
            family: $family,
            node: $node->name,
            key: $family === 'proxy' ? 'proxy.node_probe_failed' : "{$family}.remote_shell_probe_failed",
            exception: $exception,
            summary: "Doctor {$family} probe failed on node '{$node->name}': {$exception->getMessage()}",
        );
    }

    /**
     * @param  list<DoctorIssue>  $issues
     * @return list<DoctorIssue>
     */
    private function filterIssuesByKey(array $issues, ?string $key): array
    {
        if ($key === null) {
            return $issues;
        }

        return array_values(array_filter(
            $issues,
            static fn (DoctorIssue $issue): bool => $issue->key === $key,
        ));
    }

    /**
     * @param  (callable(string, 'running'|'done', list<array<string, mixed>>, ?int, ?int): void)|null  $onFamilyProgress
     * @param  'running'|'done'  $phase
     * @param  list<DoctorIssue>  $issues
     * @param  array{completed: int, total: int}|null  $progress
     */
    private function reportProgress(
        ?callable $onFamilyProgress,
        string $family,
        string $phase,
        array $issues = [],
        ?array $progress = null,
    ): void {
        if ($onFamilyProgress === null) {
            return;
        }

        $onFamilyProgress(
            $family,
            $phase,
            array_map(
                static fn (DoctorIssue $issue): array => $issue->toArray(),
                $issues,
            ),
            $progress['completed'] ?? null,
            $progress['total'] ?? null,
        );
    }
}
