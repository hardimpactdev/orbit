<?php

declare(strict_types=1);

namespace App\Actions\Instances\Dependencies;

use App\Data\Instances\Dependencies\DependencyAttemptData;
use App\Data\Instances\Dependencies\DependencyBatchPrData;
use App\Data\Instances\Dependencies\DependencyFindingData;
use App\Data\Instances\Dependencies\DependencyIndexData;
use App\Data\Instances\Dependencies\DependencyIndexedSourceData;
use App\Data\Instances\Dependencies\DependencyLinksData;
use App\Data\Instances\Dependencies\DependencyPackageData;
use App\Data\Instances\Dependencies\DependencyPullRequestData;
use App\Data\Instances\Dependencies\DependencyReasonData;
use App\Data\Instances\Dependencies\DependencyReportData;
use App\Data\Instances\Dependencies\DependencyReportPointerData;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Support\StrictJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

final readonly class DecodeSwampDependenciesAction
{
    public function __construct(private CommandActivityInputSanitizer $sanitizer) {}

    public function index(string $json, int $projectId, string $repository, string $ref): DependencyIndexData
    {
        $v = StrictJson::fields(StrictJson::decode($json, 65_536), ['schema_version', 'publication_id', 'project_id', 'repository', 'source_ref', 'source_commit', 'generated_at', 'last_attempt', 'sources', 'batch_pr']);
        $this->identity($v, $projectId, $repository);
        $this->require($v['source_ref'] === $ref);
        $this->text($v['source_ref'], 1_024);
        $this->text($v['repository'], 512);
        $sources = [];
        foreach ($this->rows($v['sources'], 64) as $raw) {
            $s = StrictJson::fields($raw, ['path', 'ecosystem', 'package_manager', 'last_attempt', 'report']);
            [$path, $ecosystem, $manager, $id] = $this->sourceIdentity($s);
            $pointer = null;
            if ($s['report'] !== null) {
                $p = StrictJson::fields($s['report'], ['modelIdOrName', 'dataName', 'version', 'source_commit', 'observed_at', 'publication_id', 'path', 'ecosystem', 'package_manager']);
                $this->require($p['modelIdOrName'] === 'orbit-gateway' && array_slice($this->sourceIdentity($p), 0, 3) === [$path, $ecosystem, $manager]);
                $publication = $this->uuid($p['publication_id']);
                $this->require($p['dataName'] === 'dependencies-v1-report-'.$publication.'-'.$id);
                $pointer = new DependencyReportPointerData($p['dataName'], $this->integer($p['version']), $this->commit($p['source_commit']), $this->date($p['observed_at']), $publication);
            }
            $this->require(! isset($sources[$id]));
            $attempt = $this->attempt($s['last_attempt']);
            $this->require(in_array($attempt->status, ['running', 'succeeded', 'failed'], true));
            $this->require($attempt->status !== 'succeeded' || $pointer !== null);
            $sources[$id] = new DependencyIndexedSourceData($id, $path, $ecosystem, $manager, $attempt, $pointer);
        }
        $commit = $v['source_commit'] === null ? null : $this->commit($v['source_commit']);
        $attempt = $this->attempt($v['last_attempt']);
        if ($attempt->status === 'succeeded') {
            $this->require($commit !== null);
            foreach ($sources as $source) {
                $this->require($source->attempt->status === 'succeeded' && $source->report?->sourceCommit === $commit);
            }
        }

        return new DependencyIndexData($projectId, $repository, $ref, $commit, $this->date($v['generated_at']), $attempt, array_values($sources), $this->pr($v['batch_pr'], $repository));
    }

    public function report(string $json, DependencyIndexData $index, DependencyIndexedSourceData $source): DependencyReportData
    {
        $v = StrictJson::fields(StrictJson::decode($json, 1_048_576), ['schema_version', 'publication_id', 'published_at', 'project_id', 'repository', 'source_commit', 'path', 'ecosystem', 'package_manager', 'observed_at', 'run_id', 'basis', 'observed_on', 'instance_id', 'packages', 'findings']);
        $this->identity($v, $index->projectId, $index->repository);
        $pointer = $source->report;
        $this->require($pointer !== null && $v['publication_id'] === $pointer->publicationId && $v['source_commit'] === $pointer->sourceCommit && $v['observed_at'] === $pointer->observedAt);
        $this->require($this->sourceIdentity($v)[3] === $source->id);
        $this->date($v['published_at']);
        $basis = $this->choice($v['basis'], ['locked', 'installed']);
        $observedOn = $this->choice($v['observed_on'], ['repository', 'task_worker', 'instance']);
        $instanceId = $v['instance_id'] === null ? null : $this->integer($v['instance_id']);
        $this->require(($observedOn === 'instance') === ($instanceId !== null));
        $this->require($observedOn !== 'repository' || $basis === 'locked');
        $packages = [];
        $versionsByPackage = [];
        foreach ($this->rows($v['packages'], 5_000) as $raw) {
            $p = StrictJson::fields($raw, ['id', 'name', 'version', 'direct', 'scopes', 'origin']);
            $id = $this->text($p['id'], 512);
            $this->require(! isset($packages[$id]) && is_bool($p['direct']));
            $scopes = $this->strings($p['scopes'], 2, 32);
            $this->require($scopes !== [] && count(array_unique($scopes)) === count($scopes) && array_diff($scopes, ['regular', 'development']) === []);
            $packages[$id] = new DependencyPackageData($id, $this->text($p['name'], 512), $this->text($p['version'], 256), $p['direct'], $scopes, $this->choice($p['origin'], ['registry', 'git', 'path']));
        }
        foreach ($packages as $package) {
            $versionsByPackage[$package->name][$package->version] = true;
        }
        $findings = [];
        foreach ($this->rows($v['findings'], 500) as $raw) {
            $f = StrictJson::fields($raw, ['id', 'package', 'kind', 'current_versions', 'target_version', 'advisory_ids', 'state', 'reason', 'links']);
            $id = $this->text($f['id'], 512);
            $this->require(! isset($findings[$id]));
            $kind = $this->choice($f['kind'], ['major', 'advisory']);
            $state = $this->choice($f['state'], ['ready', 'blocked', 'in_batch']);
            $target = $f['target_version'] === null ? null : $this->text($f['target_version'], 256);
            $advisories = $this->strings($f['advisory_ids'], 1_048_576, 512);
            $versions = $this->strings($f['current_versions'], 5_000, 256);
            $package = $this->text($f['package'], 512);
            $resolved = $versionsByPackage[$package] ?? [];
            $this->require($resolved !== [] && count(array_unique($versions)) === count($versions));
            foreach ($versions as $version) {
                $this->require(isset($resolved[$version]));
            }
            $this->require($kind !== 'major' || $advisories === []);
            $this->require($versions !== [] && ($kind !== 'advisory' || $advisories !== []) && ($target !== null || $state === 'blocked'));
            $r = StrictJson::fields($f['reason'], ['code', 'text']);
            $links = $f['links'] === null ? null : StrictJson::fields($f['links'], ['group_id', 'run_id']);
            $this->require($state !== 'in_batch' || $links !== null);
            $findings[$id] = new DependencyFindingData($id, $this->text($f['package'], 512), $kind, $versions, $target, $advisories, $state,
                new DependencyReasonData($this->code($r['code']), $this->text($r['text'], 1_024)),
                $links === null ? null : new DependencyLinksData($this->integer($links['group_id']), $this->text($links['run_id'], 256)));
        }

        return new DependencyReportData($this->commit($v['source_commit']), $pointer->publicationId, $source->path, $source->ecosystem, $source->manager, $this->date($v['observed_at']), $this->text($v['run_id'], 256), $basis, $observedOn, $instanceId, 'current', array_values($packages), count($packages), 1, array_values($findings), count($findings), 1);
    }

    /** @param array<string, mixed> $v */
    private function identity(array $v, int $projectId, string $repository): void
    {
        $this->require($v['schema_version'] === 1 && $v['project_id'] === $projectId && $v['repository'] === $repository);
        $this->uuid($v['publication_id']);
    }

    /** @param array<string, mixed> $v
     * @return array{string, string, string, string} */
    private function sourceIdentity(array $v): array
    {
        $path = $this->text($v['path'], 1_024);
        $this->require($path === '.' || (! str_starts_with($path, '/') && ! str_contains($path, '\\') && array_intersect(explode('/', $path), ['', '.', '..']) === []));
        $ecosystem = $this->choice($v['ecosystem'], ['composer', 'npm']);
        $manager = $this->choice($v['package_manager'], $ecosystem === 'composer' ? ['composer'] : ['npm', 'pnpm', 'bun']);

        return [$path, $ecosystem, $manager, hash('sha256', json_encode([$path, $ecosystem, $manager], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR))];
    }

    private function attempt(mixed $raw): DependencyAttemptData
    {
        $v = StrictJson::fields($raw, ['run_id', 'started_at', 'finished_at', 'status', 'error_code']);
        $status = $this->choice($v['status'], ['running', 'succeeded', 'partial', 'failed']);
        $finished = $v['finished_at'] === null ? null : $this->date($v['finished_at']);
        $error = $v['error_code'] === null ? null : $this->code($v['error_code']);
        $this->require(($status === 'running') === ($finished === null) && (in_array($status, ['running', 'succeeded'], true) ? $error === null : $error !== null));

        return new DependencyAttemptData($this->text($v['run_id'], 256), $this->date($v['started_at']), $finished, $status, $error);
    }

    private function pr(mixed $raw, string $repository): DependencyBatchPrData
    {
        $v = StrictJson::fields($raw, ['status', 'checked_at', 'last_success_at', 'error_code', 'value']);
        $status = $this->choice($v['status'], ['open', 'absent', 'unknown']);
        $checked = $this->date($v['checked_at']);
        $success = $v['last_success_at'] === null ? null : $this->date($v['last_success_at']);
        $error = $v['error_code'] === null ? null : $this->code($v['error_code']);
        $value = null;
        if ($v['value'] !== null) {
            $p = StrictJson::fields($v['value'], ['repository', 'number', 'url', 'head_commit', 'group_id', 'run_id']);
            $number = $this->integer($p['number']);
            $this->require($p['repository'] === $repository && str_starts_with($repository, 'github.com/') && $p['url'] === 'https://'.$repository.'/pull/'.$number);
            $value = new DependencyPullRequestData($repository, $number, $p['url'], $this->commit($p['head_commit']), $this->integer($p['group_id']), $this->text($p['run_id'], 256));
        }
        $this->require($status !== 'open' || $value !== null);
        $this->require($status !== 'absent' || $value === null);
        $this->require($status === 'unknown' ? $error !== null : ($error === null && $success === $checked));
        $this->require($value === null || $success !== null);

        return new DependencyBatchPrData($status, $checked, $success, $error, $value);
    }

    /** @return list<mixed> */
    private function rows(mixed $v, int $max): array
    {
        $this->require(is_array($v) && array_is_list($v) && count($v) <= $max);

        return $v;
    }

    /** @return list<string> */
    private function strings(mixed $v, int $max, int $bytes): array
    {
        return array_map(fn (mixed $s): string => $this->text($s, $bytes), $this->rows($v, $max));
    }

    private function text(mixed $v, int $max): string
    {
        $this->require(is_string($v) && $v !== '' && mb_strlen($v, 'UTF-8') <= $max && preg_match('/[\x00-\x1f\x7f]/', $v) !== 1);
        $this->require(preg_match('~(?:\bfile://|(?<![\pL\pN_./@-])/(?:home|fast|etc|tmp|root|var|Users|srv|opt|private|mnt|media|usr|run|dev|proc|sys|Volumes|workspace|workspaces)/|(?<![\pL\pN_])[a-z]:[\\\\/]|\\\\\\\\)~iu', $v) !== 1);
        $token = Config::string('swamp.read_token');
        $safe = $this->sanitizer->sanitize($token === '' ? $v : str_replace($token, '[REDACTED]', $v));
        $this->require(is_string($safe));

        return $safe;
    }

    private function date(mixed $v): string
    {
        $v = $this->text($v, 40);
        $this->require(preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|\+00:00)\z/D', $v) === 1);
        $this->require(CarbonImmutable::parse($v)->format('Y-m-d\\TH:i:s') === substr($v, 0, 19));

        return $v;
    }

    private function commit(mixed $v): string
    {
        $v = $this->text($v, 64);
        $this->require(preg_match('/\A(?:[a-f0-9]{40}|[a-f0-9]{64})\z/D', $v) === 1);

        return $v;
    }

    private function uuid(mixed $v): string
    {
        $v = $this->text($v, 36);
        $this->require(preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D', $v) === 1);

        return $v;
    }

    private function code(mixed $v): string
    {
        $v = $this->text($v, 128);
        $this->require(preg_match('/\A[a-z][a-z0-9_]*\z/D', $v) === 1);

        return $v;
    }

    /** @param list<string> $values */
    private function choice(mixed $v, array $values): string
    {
        $this->require(is_string($v) && in_array($v, $values, true));

        return $v;
    }

    private function integer(mixed $v): int
    {
        $this->require(is_int($v) && $v > 0);

        return $v;
    }

    /** @phpstan-assert true $condition */
    private function require(bool $condition): void
    {
        if (! $condition) {
            throw new InvalidArgumentException('Invalid Swamp dependency evidence.');
        }
    }
}
