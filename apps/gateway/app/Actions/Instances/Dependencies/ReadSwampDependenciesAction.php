<?php

declare(strict_types=1);

namespace App\Actions\Instances\Dependencies;

use App\Data\Instances\Dependencies\DependencyBatchPrData;
use App\Data\Instances\Dependencies\DependencyIndexData;
use App\Data\Instances\Dependencies\DependencyIndexedSourceData;
use App\Data\Instances\Dependencies\DependencyReportData;
use App\Data\Instances\Dependencies\DependencyResultsData;
use App\Data\Instances\Dependencies\DependencySourceData;
use App\Domain\Instances\Dependencies\SwampDependencyReader;
use App\Domain\Instances\Dependencies\SwampDependencyReadException;
use App\Models\Instance;
use App\Models\InstanceDeployment;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

final readonly class ReadSwampDependenciesAction
{
    private const int RetryDelaySeconds = 30;

    public function __construct(private SwampDependencyReader $reader, private DecodeSwampDependenciesAction $decode) {}

    public function execute(Project $project, ?Instance $instance, ?string $selected, int $packagesPage, int $findingsPage): DependencyResultsData
    {
        $branch = $instance === null || ($instance->name === 'default' && $instance->placedOnAppDev()) ? $project->default_branch : ($instance->placedOnAppProd() ? ($instance->deployment_branch ?? $instance->branch) : $instance->branch);
        $ref = is_string($branch) && $branch !== '' ? (str_starts_with($branch, 'refs/') ? $branch : 'refs/heads/'.$branch) : '';
        $repository = str_starts_with($project->repository_identity, 'github.com/') ? strtolower($project->repository_identity) : $project->repository_identity;
        $commit = $instance === null ? null : $this->instanceCommit($instance);
        $namespace = hash('sha256', Config::string('swamp.address').Config::string('swamp.read_token').$repository);
        $key = 'swamp-dependencies:'.$namespace.':'.$project->id.':'.hash('sha256', $ref);
        $cached = Cache::get($key);
        $index = is_array($cached) && is_array($cached['index'] ?? null) ? DependencyIndexData::from($cached['index']) : null;
        $readAt = is_array($cached) && is_string($cached['read_at'] ?? null) ? $cached['read_at'] : null;
        $available = ! Cache::has($key.':retry');
        if ($available && ($readAt === null || CarbonImmutable::parse($readAt)->lte(now()->subSeconds(60)))) {
            try {
                if ($ref === '') {
                    throw new \RuntimeException('Source ref is unknown.');
                }
                $json = $this->reader->read('dependencies-v1-project-'.$project->id.'-'.hash('sha256', $ref), null, 65_536);
                $index = $this->decode->index($json, $project->id, $repository, $ref);
                $readAt = now()->toIso8601String();
                Cache::put($key, ['index' => $index->toArray(), 'read_at' => $readAt], 604_800);
            } catch (Throwable $exception) {
                $this->failure($exception, $project->id, 'index');
                Cache::put($key.':retry', true, self::RetryDelaySeconds);
                $available = false;
            }
        }
        if ($index === null) {
            return new DependencyResultsData($project->id, $instance?->id, $ref, $commit, 'unavailable', null, null, null, null, [], null,
                new DependencyBatchPrData('unknown', null, null, 'unavailable', null));
        }
        $sources = [];
        $source = null;
        foreach ($index->sources as $candidate) {
            $freshness = $this->freshness($candidate, $index, $commit, $available);
            $sources[] = new DependencySourceData($candidate->id, $candidate->path, $candidate->ecosystem, $candidate->manager, $candidate->attempt, $candidate->report?->sourceCommit, $candidate->report?->observedAt, $freshness);
            if (($selected === null && $source === null) || $candidate->id === $selected) {
                $source = $candidate;
            }
        }
        if ($selected !== null) {
            abort_unless($source !== null, 422, 'The dependency source is not in this project index.');
        }
        $report = null;
        if ($source?->report !== null) {
            $pointer = $source->report;
            $lastReportKey = $key.':last-report:'.$source->id;
            $reportKey = $key.':report:'.hash('sha256', $pointer->dataName.':'.$pointer->version);
            if (! $available || Cache::has($reportKey.':retry')) {
                $available = false;
                $stored = Cache::get($lastReportKey);
                $report = is_array($stored) ? DependencyReportData::from($stored) : null;
            } else {
                $stored = Cache::get($reportKey);
                try {
                    if (is_array($stored)) {
                        $report = DependencyReportData::from($stored);
                        if ($report->source_commit !== $pointer->sourceCommit || $report->observed_at !== $pointer->observedAt || $report->publication_id !== $pointer->publicationId
                            || $report->path !== $source->path || $report->ecosystem !== $source->ecosystem || $report->package_manager !== $source->manager) {
                            throw new InvalidArgumentException('Cached report differs from its pointer.');
                        }
                    } else {
                        $report = $this->decode->report($this->reader->read($pointer->dataName, $pointer->version, 1_048_576), $index, $source);
                        Cache::put($reportKey, $report->toArray(), 604_800);
                        Cache::put($lastReportKey, $report->toArray(), 604_800);
                    }
                } catch (Throwable $exception) {
                    $this->failure($exception, $project->id, 'report');
                    Cache::put($reportKey.':retry', true, self::RetryDelaySeconds);
                    $available = false;
                    $lastReport = Cache::get($lastReportKey);
                    $report = is_array($lastReport) ? DependencyReportData::from($lastReport) : null;
                }
            }
        }
        if ($report !== null) {
            $report = new DependencyReportData($report->source_commit, $report->publication_id, $report->path, $report->ecosystem, $report->package_manager, $report->observed_at, $report->run_id, $report->basis, $report->observed_on, $report->instance_id,
                $this->freshness($source, $index, $commit, $available), array_slice($report->packages, ($packagesPage - 1) * 100, 100), $report->package_count, $packagesPage,
                array_slice($report->findings, ($findingsPage - 1) * 100, 100), $report->finding_count, $findingsPage);
        }
        if (! $available) {
            $sources = array_map(static fn (DependencySourceData $summary): DependencySourceData => new DependencySourceData(
                $summary->id, $summary->path, $summary->ecosystem, $summary->package_manager, $summary->last_attempt,
                $summary->id === $source?->id && $report !== null ? $report->source_commit : $summary->source_commit,
                $summary->id === $source?->id && $report !== null ? $report->observed_at : $summary->observed_at,
                $summary->source_commit === null ? 'unknown' : 'stale',
            ), $sources);
        }
        $pr = $index->pr;
        if (! $available || $pr->checked_at === null || CarbonImmutable::parse($pr->checked_at)->lte(now()->subMinutes(5))) {
            $pr = new DependencyBatchPrData('unknown', $pr->checked_at, $pr->last_success_at, $pr->error_code ?? 'stale_evidence', $pr->value);
        }

        return new DependencyResultsData($project->id, $instance?->id, $ref, $commit, $available ? 'available' : ($source?->report !== null && $report === null ? 'unavailable' : 'stale'), $readAt,
            $index->generatedAt, $index->commit, $index->attempt, $sources, $report, $pr);
    }

    private function freshness(DependencyIndexedSourceData $source, DependencyIndexData $index, ?string $instanceCommit, bool $available): string
    {
        if ($source->report === null) {
            return 'unknown';
        }

        return ! $available || $source->attempt->status === 'failed'
            || CarbonImmutable::parse($source->report->observedAt)->lte(now()->subHours(26))
            || ($index->commit !== null && $source->report->sourceCommit !== $index->commit)
            || ($instanceCommit !== null && $source->report->sourceCommit !== $instanceCommit) ? 'stale' : 'current';
    }

    private function failure(Throwable $exception, int $projectId, string $stage): void
    {
        Log::warning('Swamp dependency read failed.', [
            'project_id' => $projectId,
            'stage' => $stage,
            'code' => $exception instanceof SwampDependencyReadException ? $exception->failure->value : ($exception instanceof InvalidArgumentException ? 'invalid' : 'unavailable'),
        ]);
    }

    private function instanceCommit(Instance $instance): ?string
    {
        $deployment = InstanceDeployment::query()->where('instance_id', $instance->id)->where('status', 'succeeded')->latest('id')->first();

        return $deployment->commit ?? ($instance->name === 'default' ? $instance->seed_commit : $instance->starting_commit);
    }
}
