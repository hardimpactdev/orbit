<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Operations\OperationStatus;
use App\Domain\Processes\Artifacts\GithubReleases;
use App\Domain\Processes\Artifacts\ProcessArtifactFolder;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessAdmissionLock;
use App\Domain\Processes\ProcessOperationAdmission;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Models\ProcessArtifact;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Admits `process.upgrade` (docs/reference/processes-and-schedules.md#upgrade-a-process). The Gateway resolves the release; the
 * admitted input carries the download URL and SHA-256 only. An upgrade to the recorded version succeeds at once with no run.
 */
final readonly class UpgradeProcessAction
{
    public const string Kind = 'process.upgrade';

    public function __construct(
        private StartOperationAction $operations,
        private GithubReleases $releases,
        private ProcessRuntimeManager $runtime,
        private ProcessAdmissionLock $admissions,
    ) {}

    public function admit(Process $process, string $requested, ?Node $caller = null, ?string $requestId = null): Operation
    {
        $artifact = $process->artifact;
        if ($process->runtime !== ProcessRuntime::Systemd || ! $artifact instanceof ProcessArtifact) {
            throw new ResourceOperationException('process.artifact_missing', "Process [{$process->name}] has no release artifact. Record one with process:artifact:set.", 409);
        }
        $version = GithubReleases::normalize($requested)
            ?? throw new ResourceOperationException('process.version_unknown', "[{$requested}] is not a release version.", 422, details: ['repository' => $artifact->repository, 'version' => $requested]);
        if ($process->desired_state !== DesiredProcessState::Running) {
            throw new ResourceOperationException('process.not_running', "Process [{$process->name}] is not running. Start it before you upgrade it.", 409);
        }
        $folder = ProcessArtifactFolder::fromProcess($process, $artifact->binary);
        if ($artifact->version === $version) {
            return $this->unchanged($process, $artifact, $folder, $caller, $requestId);
        }
        $this->runtime->assertCanStart($process);
        $release = $this->releases->resolve($artifact, $version);
        $command = $process->runtime_config['command'] ?? null;

        return $this->locked($process, function () use ($process, $artifact, $command, $folder, $release, $caller, $requestId): Operation {
            // The GitHub read ran outside the lock: the input is built from the Process and artifact as they are now, and only
            // when the command, folder and artifact the release was resolved for still hold.
            $current = Process::query()->with('artifact')->findOrFail($process->id);
            if ($current->desired_state !== DesiredProcessState::Running) {
                throw new ResourceOperationException('process.not_running', "Process [{$current->name}] is not running. Start it before you upgrade it.", 409);
            }
            $currentArtifact = $current->artifact;
            if (! $currentArtifact instanceof ProcessArtifact || ! self::sameArtifact($currentArtifact, $artifact)
                || ($current->runtime_config['command'] ?? null) !== $command || ProcessArtifactFolder::fromProcess($current, $currentArtifact->binary) !== $folder) {
                throw new ResourceOperationException('operation.target_busy', "Process [{$current->name}] or its release artifact changed while the upgrade was admitted. Try again.", 409);
            }
            $this->assertIdle($current);
            $input = [...$this->placement($current, $caller), 'artifact_id' => $currentArtifact->id, 'repository' => $currentArtifact->repository,
                'asset_pattern' => $currentArtifact->asset, 'recorded_version' => $currentArtifact->version,
                'folder' => $folder, 'binary' => $currentArtifact->binary, 'version' => $release->version,
                'asset' => $release->asset, 'url' => $release->url, 'sha256' => $release->sha256, 'version_args' => $currentArtifact->version_args ?? []];

            // The Process keeps its lifecycle state: a failure before `Switch current` leaves it as it was.
            return $this->operations->execute(self::Kind, 'process', $current->id, ValidatedData::integer($input['node_id']), $input, 'operator', $requestId,
                lockKey: 'process:'.$current->id);
        });
    }

    /** The artifact a release was resolved for: the same record, source, checks and installed version. */
    public static function sameArtifact(ProcessArtifact $current, ProcessArtifact $admitted): bool
    {
        return $current->id === $admitted->id && $current->repository === $admitted->repository && $current->asset === $admitted->asset
            && $current->binary === $admitted->binary && $current->checksums === $admitted->checksums
            && $current->version_args === $admitted->version_args && $current->version === $admitted->version;
    }

    private function unchanged(Process $process, ProcessArtifact $artifact, string $folder, ?Node $caller, ?string $requestId): Operation
    {
        $input = [...$this->placement($process, $caller), 'folder' => $folder, 'binary' => $artifact->binary, 'version' => $artifact->version];
        $version = ValidatedData::string($artifact->version);

        return $this->locked($process, function () use ($process, $input, $version, $requestId): Operation {
            $this->assertIdle($process);

            return $this->operations->record(self::Kind, 'process', $process->id, ValidatedData::integer($input['node_id']), $input,
                ['before' => $version, 'after' => $version, 'outcome' => 'unchanged', 'pruned' => []], 'operator', $requestId, lockKey: 'process:'.$process->id);
        });
    }

    /** @return array<string,mixed> */
    private function placement(Process $process, ?Node $caller): array
    {
        $input = app(ProcessOperationAdmission::class)->inputFor($process, 'restart', caller: $caller);
        if (isset($input['container'])) {
            // operation-process-upgrade acts on a host systemd unit over SSH only.
            throw new ResourceOperationException('process.target_unsupported', "Process [{$process->name}] runs in a container; process:upgrade upgrades a host systemd Process.", 409);
        }

        return Arr::only($input, ['process_id', 'node_id', 'name', 'runtime', 'caller_node_id']);
    }

    /** No other Operation holds the Process. */
    private function assertIdle(Process $process): void
    {
        if (Operation::query()->where('target_type', 'process')->where('target_id', $process->id)
            ->whereIn('status', OperationStatus::openValues())->exists()) {
            throw new ResourceOperationException('operation.target_busy', "Another Operation holds Process [{$process->name}].", 409);
        }
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $admit
     * @return T
     */
    private function locked(Process $process, \Closure $admit): mixed
    {
        $transaction = static fn (): mixed => DB::transaction($admit);

        return Instance::isMorphType($process->owner_type) ? $this->admissions->run([$process->owner_id], $transaction) : $transaction();
    }
}
