<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\DatabaseServers\DatabaseDestroyOwnedMaterial;
use App\Domain\GitHub\GitReadEnvironment;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Operation;
use App\Support\ValidatedData;
use LogicException;

/** Existing custody sources produce execution copies; only names enter run inputs. */
final readonly class InstanceDestroyExecutionCopies
{
    public function __construct(private SwampCommandStep $steps) {}

    /** @return array<string, array{vault:string,key:string}> */
    public function forOperation(Operation $operation): array
    {
        $input = $operation->input;
        $removalId = ValidatedData::string($input['removal_id']);
        $copies = [];
        if ($input['force'] !== true && array_any(InstanceLifecycleInput::members($input), static fn (array $member): bool => ($member['failed_creation'] ?? false) !== true)) {
            $git = app(InstanceLifecycleGitSecret::class)->resolve($operation, ValidatedData::integer($input['project_id']), '', 'git_read')->value;
            if (! $git instanceof GitReadEnvironment) {
                throw new LogicException('Instance removal requires its canonical Git read source.');
            }
            if ($git->githubToken() !== null) {
                $copies['git_read'] = $this->steps->executionCopy('orbit-control-inputs', 'instance-removal-'.$removalId.'-git',
                    json_encode(['github_token' => $git->githubToken()], JSON_THROW_ON_ERROR));
            }
        }

        $teardown = app(InstanceDestroyTeardownSecret::class)->resolve($operation, ValidatedData::integer($input['instance_id']), '', 'teardown')->value;
        foreach (InstanceLifecycleInput::members(ValidatedData::object($teardown)) as $member) {
            foreach (InstanceLifecycleInput::members(['members' => $member['steps']]) as $index => $step) {
                $field = 'teardown_'.ValidatedData::integer($member['instance_id']).'_'.$index;
                $copies[$field] = $this->steps->executionCopy('orbit-control-inputs', 'instance-removal-'.$removalId.'-'.$field,
                    ValidatedData::string($step['command']));
            }
        }

        $detail = ValidatedData::object($input['direct_destroy']);
        $database = ValidatedData::object($detail['database']);
        foreach (InstanceLifecycleInput::members(['members' => $database['servers']]) as $server) {
            $serverId = ValidatedData::integer($server['server_id']);
            $material = app(DatabaseDestroyOwnedMaterial::class)->resolve($operation, ValidatedData::integer($server['node_id']));
            $value = ValidatedData::object($material->value);
            $passwords = (array) $value['passwords'];
            $copies['database_'.$serverId] = $this->steps->executionCopy('orbit-control-inputs', 'instance-removal-'.$removalId.'-database-'.$serverId,
                ValidatedData::string($passwords[$serverId]));
        }

        $php = app(InstanceDestroyPhpSecret::class)->resolve($operation, ValidatedData::integer($input['instance_id']), '', 'php')->value;
        $php = ValidatedData::object($php);
        if ((array) ($php['configurations'] ?? []) !== [] || ($php['production_runtime'] ?? []) !== []) {
            $copies['php'] = $this->steps->executionCopy('orbit-control-inputs', 'instance-removal-'.$removalId.'-php', json_encode($php, JSON_THROW_ON_ERROR));
        }

        return $copies;
    }
}
