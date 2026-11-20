<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Domain\Processes\Artifacts\GithubReleases;
use App\Domain\Processes\Artifacts\ProcessArtifactFolder;
use App\Domain\Processes\Artifacts\ProcessArtifactPresets;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Process;
use App\Models\ProcessArtifact;
use Illuminate\Support\Facades\DB;

/** Records a Process's release artifact in the Gateway only; it changes nothing on the Node and starts no Operation. */
final readonly class SetProcessArtifactAction
{
    public function __construct(private ProcessArtifactPresets $presets) {}

    /** @param array<string,mixed> $fields preset, repository, asset, binary, checksums, version_args and version */
    public function execute(Process $process, array $fields): ProcessArtifact
    {
        $preset = is_string($fields['preset'] ?? null) ? $this->presets->require($fields['preset']) : [];
        $value = static fn (string $name): mixed => array_key_exists($name, $fields) && $fields[$name] !== null ? $fields[$name] : ($preset[$name] ?? null);
        $repository = $value('repository');
        $asset = $value('asset');
        $binary = $value('binary');
        $checksums = $value('checksums');
        $versionArgs = $value('version_args');
        if (! is_string($repository) || preg_match(GithubReleases::RepositoryPattern, $repository) !== 1) {
            throw self::invalid('The repository must be OWNER/REPO on GitHub.');
        }
        if (! is_string($asset) || substr_count($asset, '{version}') !== 1 || preg_match('/\A[A-Za-z0-9._+{}-]+\z/D', $asset) !== 1) {
            throw self::invalid('The asset must be a file name with {version} once, such as NAME_{version}_linux_amd64.tar.gz.');
        }
        if (! is_string($binary) || preg_match('#\A[A-Za-z0-9._+-]+(?:/[A-Za-z0-9._+-]+)*\z#D', $binary) !== 1 || preg_match('#(?:\A|/)\.\.?(?:/|\z)#', $binary) === 1) {
            throw self::invalid('The binary must be a relative path inside the unpacked archive.');
        }
        if ($checksums !== null && (! is_string($checksums) || preg_match('/\A[A-Za-z0-9._+-]+\z/D', $checksums) !== 1)) {
            throw self::invalid('The checksums asset must be a file name.');
        }
        if ($versionArgs !== null && (! is_array($versionArgs) || ! array_is_list($versionArgs) || count($versionArgs) > 8
            || array_any($versionArgs, static fn (mixed $argument): bool => ! is_string($argument) || $argument === '' || strlen($argument) > 64 || preg_match('/[\x00-\x1f\x7f]/', $argument) === 1))) {
            throw self::invalid('The version arguments must be a list of at most 8 plain arguments.');
        }
        $version = is_string($fields['version'] ?? null) ? GithubReleases::normalize($fields['version']) : null;
        if (isset($fields['version']) && $version === null) {
            throw self::invalid('The version is not a release version.');
        }
        ProcessArtifactFolder::fromProcess($process, $binary);

        return DB::transaction(function () use ($process, $repository, $asset, $binary, $checksums, $versionArgs, $version): ProcessArtifact {
            $existing = ProcessArtifact::query()->where('process_id', $process->id)->lockForUpdate()->first();
            $sameRepository = $existing?->repository === $repository;
            $attributes = ['source' => ProcessArtifact::SourceGithubRelease, 'repository' => $repository, 'asset' => $asset, 'binary' => $binary,
                'checksums' => $checksums, 'version_args' => $versionArgs === [] ? null : $versionArgs];
            if ($version !== null && $version !== $existing?->version) {
                $attributes += ['version' => $version, 'previous_version' => null, 'sha256' => null];
            }
            if (! $sameRepository) {
                $attributes += ['version' => $version, 'previous_version' => null, 'sha256' => null, 'latest_version' => null, 'latest_checked_at' => null];
            }

            return ProcessArtifact::query()->updateOrCreate(['process_id' => $process->id], $attributes)->refresh();
        });
    }

    private static function invalid(string $message): ResourceOperationException
    {
        return new ResourceOperationException('process.artifact_invalid', $message, 422);
    }
}
