<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Data\Instances\InstanceData;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\NativeOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Projects\ProjectType;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitRepositoryIdentity;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Checks the facts the native run read from the checkout and stores them on the reserved Instance. */
final readonly class InstanceRegisterResult implements DirectOperationProjection, NativeOperationProjection
{
    public function __construct(private DirectOperation $direct, private RecordEventBroadcaster $broadcaster) {}

    public function native(Operation $operation): bool
    {
        return true;
    }

    public function input(Operation $operation): array
    {
        return ['intent' => [...$operation->input, 'kind' => $operation->kind, 'ssh_execution' => app(SwampSshExecutionInput::class)->forNode(Node::query()->findOrFail($operation->node_id))], 'execution_secrets' => []];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $terminal = $operation->refresh()->status->terminal();
            $status = ValidatedData::string($data['status']);
            $code = ValidatedData::nullableString($data['error_code'] ?? null);
            $result = [];
            $facts = null;
            if (! $terminal && $status === OperationStatus::Succeeded->value) {
                try {
                    $result = $facts = $this->facts($operation->input, ValidatedData::object($data['result'] ?? []));
                } catch (ResourceOperationException $exception) {
                    [$status, $code, $result] = [OperationStatus::Failed->value, $exception->errorCode, ['error_code' => $exception->errorCode]];
                }
            } elseif (in_array($status, [OperationStatus::Failed->value, OperationStatus::Interrupted->value], true)) {
                // A read step that fails means the path is not a readable Git checkout.
                $code ??= 'instance.source_invalid';
                $result = ['error_code' => $code];
            }
            $projected = $this->direct->project($operation, [...$data, 'status' => $status, 'error_code' => $code], $result);
            if ($facts !== null) {
                $input = $operation->input;
                $instance = Instance::query()->findOrFail($operation->target_id);
                $instance->update([
                    'status' => InstanceState::Active, 'provisioning_step' => 'active',
                    'branch' => $facts['branch'], 'branch_override' => $facts['branch'],
                    'starting_commit' => $facts['commit'],
                    'selected_php_version' => $facts['php_version'],
                    'source_is_laravel' => $facts['laravel'],
                    'registration_repository_url' => $input['repository'], 'registration_repository_identity' => $input['repository_identity'],
                    'registration_source_digest' => $facts['digest'],
                    'registration_detached' => $facts['detached'],
                    'registration_default_branch' => $facts['default_branch'],
                    'registration_inferred_slug' => $facts['inferred_slug'],
                    'registration_common_repository_path' => $facts['common'], 'registration_worktree_paths' => [$input['checkout']],
                    'registration_relocation_state' => 'relocated', 'registration_completed_at' => now(), 'failed_step' => null, 'error_code' => null,
                ]);
                $this->broadcaster->broadcast(RecordEventType::InstanceUpdated, $instance->id,
                    InstanceData::fromModel($instance->load(['project', 'node', 'routes.targets']))->toArray());
            }

            return $projected;
        });
    }

    /**
     * Compares the read facts with the admitted checkout: one plain checkout of the Project's repository.
     *
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $run
     * @return array{registered:true,branch:?string,commit:string,detached:bool,digest:string,common:string,default_branch:?string,inferred_slug:string,php_version:?string,laravel:bool}
     */
    private function facts(array $input, array $run): array
    {
        $read = static fn (string $name): string => is_string($run[$name] ?? null) ? rtrim($run[$name]) : throw new ResourceOperationException('instance.source_invalid', 'The checkout read is incomplete.', 422);
        $checkout = ValidatedData::string($input['checkout']);
        $trees = array_values(array_filter(explode("\n", $read('worktrees')), static fn (string $line): bool => str_starts_with($line, 'worktree ')));
        if ($read('top') !== $checkout || $read('common') !== $checkout.'/.git' || $trees !== ['worktree '.$checkout]) {
            throw new ResourceOperationException('instance.registration_conflict', 'The path is not one plain checkout.', 409);
        }
        // The read masks embedded credentials; a masked origin is refused.
        $origin = $read('origin');
        try {
            $identity = str_contains($origin, 'REDACTED@') ? null : GitRepositoryIdentity::derive($origin);
        } catch (\InvalidArgumentException) {
            $identity = null;
        }
        if ($identity !== $input['repository_identity']) {
            throw new ResourceOperationException('instance.source_identity_invalid', 'The checkout origin is not the Project repository.', 409);
        }
        $commit = $read('commit');
        $lines = explode("\n", $read('filesystem'));
        $digest = array_shift($lines);
        if (preg_match('/\A[a-f0-9]{40}(?:[a-f0-9]{24})?\z/D', $commit) !== 1 || preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1) {
            throw new ResourceOperationException('instance.source_invalid', 'The checkout read is incomplete.', 422);
        }
        $branch = $read('branch') === '' ? null : $read('branch');
        $segments = preg_split('#[/:]#', rtrim($origin, '/')) ?: [''];
        $slug = (string) preg_replace(['/\.git\z/', '/[^a-z0-9-]+/', '/\A-|-\z/'], ['', '-', ''], strtolower(end($segments)));

        return ['registered' => true, 'branch' => $branch, 'commit' => $commit, 'detached' => $branch === null, 'digest' => $digest,
            'common' => $checkout.'/.git', 'default_branch' => preg_replace('#\Arefs/remotes/origin/#', '', $read('default')) ?: null,
            'inferred_slug' => $slug,
            ...$this->profile($lines, ProjectType::from(ValidatedData::string($input['project_type'])))];
    }

    /**
     * The artisan entry point and composer.json lines after the digest; a monorepo or a source without composer.json has no profile.
     *
     * @param  list<string>  $lines
     * @return array{php_version:?string,laravel:bool}
     */
    private function profile(array $lines, ProjectType $type): array
    {
        $entryPoint = array_shift($lines);
        $composer = implode("\n", $lines);
        if ($type === ProjectType::Monorepo || $entryPoint === null || trim($composer) === '') {
            return ['php_version' => null, 'laravel' => false];
        }
        if (! in_array($entryPoint, ['unsafe', 'regular', 'absent'], true) || strlen($composer) > 1048576) {
            throw new ResourceOperationException('app-dev.source_metadata_unsafe', 'The checkout metadata is unsafe.', 422);
        }
        try {
            $profile = app(ComposerSourceClassifier::class)->classify($composer, $type, $entryPoint);
        } catch (RuntimeConvergenceException $exception) {
            throw new ResourceOperationException($exception->errorCode, 'The development source metadata is invalid or unsupported.', 422);
        }

        return ['php_version' => $profile->phpVersion, 'laravel' => $profile->laravel];
    }
}
