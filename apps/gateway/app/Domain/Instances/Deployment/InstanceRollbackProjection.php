<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationTimeout;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Instance;
use App\Models\InstanceDeployment;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/**
 * The full rollback intent for the native run, and its outcome from Swamp's own run data (docs/cli/instance/rollback.mmd).
 * The rollback row records the result; its commit and the commits it moved away from come from admission and history.
 */
final readonly class InstanceRollbackProjection implements DirectOperationProjection, DirectOperationTimeout
{
    /** Each chart step and the error code its failure records. */
    private const array Codes = [
        'Activate release' => 'rollback.release_invalid',
        'Swap current' => 'deployment.selection_invalid',
        'Refresh PHP cache' => 'deployment.php_runtime_unconfirmed',
        'Restart Processes' => 'process.restart_failed',
        'Read current back' => 'deployment.selection_invalid',
        'Check the selection' => 'deployment.selection_invalid',
    ];

    public function __construct(
        private DirectOperation $direct,
        private InstanceDeploymentRecorder $history,
        private SwampSshExecutionInput $ssh,
    ) {}

    public function input(Operation $operation): array
    {
        $release = ValidatedData::string($operation->input['release']);
        $deployment = ValidatedData::object($operation->input['deployment']);
        $home = ValidatedData::string($deployment['home']);
        $links = [];
        // Each served application links its .env in the release; `current.next` comes last, so a removed release fails first.
        // The Swap current step then renames `current.next` over `current` in one atomic rename.
        $served = $deployment['served'];
        if (! is_array($served) || ! array_is_list($served)) {
            throw new \InvalidArgumentException('The admitted served applications must be a list.');
        }
        foreach ($served as $application) {
            $directory = ValidatedData::string(ValidatedData::object($application)['directory']);
            $links[] = ['path' => "{$home}/releases/{$release}/{$directory}/.env",
                'target' => str_repeat('../', 3 + substr_count($directory, '/'))."env/{$directory}/.env"];
        }
        $links[] = ['path' => "{$home}/current.next", 'target' => "releases/{$release}"];
        $instance = Instance::query()->with('node')->findOrFail($operation->target_id);

        return ['intent' => [
            'rollback' => ['instance_id' => $operation->target_id, 'release' => $release, 'user' => ValidatedData::string($deployment['user']), 'home' => $home, 'links' => $links],
            'php_input' => $operation->input['php_input'], 'runtime_definitions' => $operation->input['runtime_definitions'],
            'ssh_execution' => $this->ssh->forNode($instance->node),
        ], 'execution_secrets' => []];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $locked = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            $wasTerminal = $locked->status->terminal();
            $status = ValidatedData::string($data['status']);
            if ($status === 'running') {
                return $this->direct->project($locked, $data, []);
            }
            $run = ValidatedData::object($data['result'] ?? []);
            $steps = ValidatedData::object($run['steps'] ?? []);
            // A forEach step reports each item under the step's name with an index.
            $of = static fn (string $label): array => array_values(array_map(static fn (mixed $step): ?string => is_array($step) && is_string($step['status'] ?? null) ? $step['status'] : null,
                array_filter($steps, static fn (string|int $name): bool => str_starts_with((string) $name, $label), ARRAY_FILTER_USE_KEY)));
            $activation = $of('Activate release');
            // `current` moved only when every link held and the rename over it succeeded.
            $activated = $activation !== [] && array_diff($activation, ['succeeded']) === [] && $of('Swap current') === ['succeeded'];
            $code = null;
            $failedStep = null;
            if ($status === 'failed') {
                $failed = ValidatedData::stringList(ValidatedData::object($run['summary'] ?? [])['failed_steps'] ?? []);
                $failedStep = 'native_execution';
                foreach (array_keys(self::Codes) as $label) {
                    if (array_any($failed, static fn (string $name): bool => str_starts_with($name, $label))) {
                        $failedStep = $label;
                        break;
                    }
                }
                $code = self::Codes[$failedStep] ?? ValidatedData::nullableString($data['error_code'] ?? null) ?? 'operation.native_failed';
            }
            $release = ValidatedData::string($locked->input['release']);
            $facts = ['deployment_id' => $locked->state['deployment_id'] ?? null, 'release' => $release,
                'selected_release' => $activated ? $release : null, 'commit' => ValidatedData::nullableString($locked->input['commit'] ?? null),
                'failed_step' => $failedStep];
            $projected = $this->direct->project($locked, [...$data, 'error_code' => $code ?? ValidatedData::nullableString($data['error_code'] ?? null)], $facts);
            if ($wasTerminal) {
                return $projected;
            }
            if ($activated) {
                $home = ValidatedData::string(ValidatedData::object($locked->input['deployment'])['home']);
                Instance::query()->whereKey($locked->target_id)->update(['checkout_path' => "{$home}/releases/{$release}"]);
            }
            $this->history->recordNative($this->row($locked), $status, $code, $facts);

            return $projected;
        });
    }

    public function timedOut(Operation $operation): void
    {
        $this->history->recordNative($this->row($operation), 'failed', ValidatedData::string($operation->error_code),
            ['release' => ValidatedData::string($operation->input['release']), 'selected_release' => null,
                'commit' => ValidatedData::nullableString($operation->input['commit'] ?? null), 'failed_step' => null]);
    }

    private function row(Operation $operation): InstanceDeployment
    {
        return InstanceDeployment::query()->where('instance_id', $operation->target_id)
            ->findOrFail(ValidatedData::integer($operation->state['deployment_id'] ?? null));
    }
}
