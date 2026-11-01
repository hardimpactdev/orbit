<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Instances\Environment\InstanceEnvironmentContextResolver;
use App\Domain\Instances\Environment\InstanceEnvironmentStore;
use App\Domain\Instances\VpEnvironment\InstanceVpEnvironmentMaterial;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\VpEnvironment;
use App\Infrastructure\Swamp\SwampVaultExecutionCopy;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Project the original native terminal receipt once; no running phase callbacks. */
final readonly class DevelopmentInstanceCreateProjection implements DirectOperationProjection
{
    public function __construct(private DirectOperation $direct) {}

    public function input(Operation $operation): array
    {
        return app(NativeDevelopmentInstanceInput::class)->create($operation);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation->refresh();
            $this->direct->assertRun($operation, ValidatedData::string($data['run_id']));
            $result = ValidatedData::object($data['result'] ?? []);
            $instance = app(InstanceLifecycleState::class)->instance($operation);
            if ((ValidatedData::object($operation->input['direct_provisioning'] ?? [])['already_active'] ?? false) === true) {
                return $this->direct->project($operation, $data, $result);
            }
            if ($data['status'] === 'succeeded' && (($result['ready'] ?? false) !== true || ($result['instance_id'] ?? null) !== $instance->id || ! isset($result['source'], $result['environment_keys'], $result['environment_seeded_keys']) || ! array_key_exists('vp_environment', $result))) {
                throw new ResourceOperationException('instance.result_invalid', 'Native creation did not prove its complete terminal result.');
            }
            if (! $operation->status->terminal()) {
                if (isset($result['source'])) {
                    $source = ValidatedData::object($result['source']);
                    $instance->update(['status' => InstanceState::SourceResolved, 'provisioning_step' => 'php-selected',
                        'branch' => ValidatedData::string($source['branch']), 'starting_commit' => ValidatedData::string($source['commit']),
                        'selected_php_version' => $source['php_version'], 'source_is_laravel' => $source['laravel'],
                        ...(($operation->input['seed_commit'] ?? null) !== null ? ['source_layout' => InstanceSourceLayout::Worktree] : [])]);
                    if (isset($result['environment_seeded_keys'])) {
                        $reference = NativeDevelopmentInstanceInput::environmentOutput($operation);
                        $private = ValidatedData::object(json_decode(app(SwampVaultExecutionCopy::class)->readSecret($reference['vault'], $reference['key']), true, flags: JSON_THROW_ON_ERROR));
                        $context = app(InstanceEnvironmentContextResolver::class)->resolve($instance->refresh(), true, createOperationId: $operation->id);
                        $values = ValidatedData::stringMap($private['values']);
                        app(InstanceEnvironmentStore::class)->putMany($context, $values, 'create');
                        if (isset($result['environment_keys'])) {
                            $keys = ValidatedData::stringList($result['environment_keys']);
                            $actualKeys = array_keys($values);
                            sort($keys);
                            sort($actualKeys);
                            if ($keys !== $actualKeys) {
                                throw new ResourceOperationException('instance.result_invalid', 'The native environment keys do not match their private output.');
                            }
                            $instance->forceFill(['environment_owned_keys' => $actualKeys])->save();
                        }
                    }
                    if (is_array($result['vp_environment'] ?? null)) {
                        app(InstanceVpEnvironmentMaterial::class)->recordReference($instance, ValidatedData::string($operation->input['checkout']), VpEnvironment::fromArray($result['vp_environment']), 'active');
                    }
                }
                if ($data['status'] === 'succeeded') {
                    $instance->update(['status' => InstanceState::Active, 'provisioning_step' => 'active', 'first_setup_pending' => false, 'failed_step' => null, 'error_code' => null]);
                } elseif (in_array($data['status'], ['failed', 'cancelled'], true)) {
                    $instance->update(['failed_step' => $result['failed_step'] ?? 'source', 'error_code' => $data['error_code'] ?? 'operation.cancelled']);
                }
            }

            return $this->direct->project($operation, $data, $result);
        });
    }
}
