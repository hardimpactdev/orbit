<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Nodes\NodeExecutorInput;
use App\Domain\Nodes\NodeRoleRuntimeInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\MachineSoftware\MachineRecipe;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\MachineSoftware\ToolPin;
use App\Models\Node;
use App\Support\ValidatedData;

/** Fingerprints of the declared inputs to release-time Node work. Agent and CLI delivery have their own pins. */
final readonly class ReleaseNodeInputs
{
    private const string Recipe = 'apps/gateway/resources/machine-software/recipe.json';

    /** These affect the host as a whole. A release may report their drift but may not apply them. */
    private const array HostFiles = [
        'apps/gateway/app/Domain/Nodes/NodeKernelPolicy.php',
        'apps/gateway/app/Domain/Nodes/NodeUpdatePolicy.php',
        'apps/gateway/app/Domain/Nodes/NodeNetworkConvergeInput.php',
        'swamp/ansible/tasks/node-kernel-policy.yml',
        'swamp/ansible/tasks/node-update-policy.yml',
        'swamp/ansible/tasks/node-converge-network.yml',
        'swamp/ansible/tasks/node-converge-hub.yml',
        'apps/gateway/app/Domain/Nodes/GatewayRuntimeFiles.php',
        'swamp/ansible/tasks/gateway-runtime.yml',
    ];

    private const array RoleFiles = [
        'app-dev' => ['swamp/ansible/tasks/node-executor.yml', 'apps/gateway/app/Domain/Instances/InstanceHostIntent.php', 'apps/gateway/app/Domain/Nodes/AppDevRuntimeFootprint.php',
            'apps/gateway/app/Infrastructure/Fleet/Footprint/TmpfilesFootprintArtifact.php',
            'swamp/ansible/tasks/instance-host*.yml', 'swamp/ansible/tasks/instance-folder*.yml', 'swamp/ansible/instance_container_files.py'],
        'app-prod' => ['swamp/ansible/tasks/php.yml'],
        'swamp' => ['apps/gateway/app/Domain/Nodes/SwampRoleUnits.php', 'swamp/ansible/tasks/swamp-start-cache.yml', 'swamp/ansible/tasks/swamp-worker-renewal.yml'],
        'websocket' => ['apps/gateway/app/Infrastructure/WebSocket/WebSocketServiceUnitRenderer.php', 'swamp/ansible/tasks/websocket.yml'],
        'metrics' => ['swamp/ansible/tasks/metrics.yml'],
        'ci-runner' => ['swamp/ansible/tasks/ci.yml'],
    ];

    public function host(?string $root = null): string
    {
        $root ??= dirname(base_path(), 2);
        $recipe = new RecipeLoader()->source($root.'/'.self::Recipe);
        $raw = ValidatedData::object(json_decode($recipe->json, true, flags: JSON_THROW_ON_ERROR));

        return self::digest([$raw['ubuntu'], $raw['architectures'], $raw['artifact_mirror'], $raw['git'],
            $this->pins($recipe, $recipe->profile('base')), $this->files($root, self::HostFiles)]);
    }

    /**
     * @param list<string>|null $selected
     * @return array<int, array{role:string,digest:string}> Assignment IDs keep a recreated role from inheriting an apply.
     */
    public function roles(Node $node, bool $release = true, ?array $selected = null): array
    {
        $root = dirname(base_path(), 2);
        $recipe = new RecipeLoader()->source($root.'/'.self::Recipe);
        $raw = ValidatedData::object(json_decode($recipe->json, true, flags: JSON_THROW_ON_ERROR));
        $roles = [];
        foreach ($node->roles()->where('status', LifecycleStatus::Active)
            ->when($release, static fn ($query) => $query->where('role', '!=', RoleName::Gateway))
            ->when($selected !== null, static fn ($query) => $query->whereIn('role', $selected ?? []))->orderBy('id')->get() as $assignment) {
            $role = $assignment->role->value;
            $profile = array_values(array_diff($recipe->profiles[$role] ?? [], ValidatedData::stringList($raw['common'])));
            $versions = array_keys($recipe->phpExtensions($profile));
            $php = array_intersect_key($recipe->php, array_flip($versions));
            $runtime = app(NodeRoleRuntimeInput::class)->capture($node, $assignment);
            // Transport, exporter publication, and the pinned binary are separate inputs to their own Operations.
            unset($runtime['agent_sha256'], $runtime['ssh_execution'], $runtime['fleet']);
            $files = [...(self::RoleFiles[$role] ?? []), ...($php !== [] ? ['swamp/ansible/tasks/php.yml'] : [])];
            $roles[$assignment->id] = ['role' => $role, 'digest' => self::digest([
                $this->pins($recipe, $profile), $php, in_array($role, $recipe->phpPolicy->pcovProfiles, true),
                $role === RoleName::AppDev->value ? $raw['instances'] : null,
                $runtime, isset(NodeExecutorInput::RoleFamilies[$role]) ? NodeExecutorInput::for($node) : null,
                $this->files($root, $files),
            ])];
        }

        return $roles;
    }

    /**
     * @param array<int,string> $names
     * @return array<string,ToolPin>
     */
    private function pins(MachineRecipe $recipe, array $names): array
    {
        return array_intersect_key($recipe->tools, array_flip($names));
    }

    /**
     * @param list<string> $patterns
     * @return array<string,string>
     */
    private function files(string $root, array $patterns): array
    {
        $result = [];
        foreach ($patterns as $pattern) {
            foreach (glob($root.'/'.$pattern) ?: [] as $file) {
                $result[substr($file, strlen($root) + 1)] = hash_file('sha256', $file) ?: throw new \RuntimeException('Cannot hash a release input.');
            }
        }

        return $result;
    }

    private static function digest(mixed $input): string
    {
        return hash('sha256', json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
