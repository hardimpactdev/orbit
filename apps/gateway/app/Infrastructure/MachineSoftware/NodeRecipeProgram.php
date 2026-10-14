<?php

declare(strict_types=1);

namespace App\Infrastructure\MachineSoftware;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Fleet\NodeShell;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Node;
use RuntimeException;

/** Fixed recipe operations over the same pinned SSH transport as fleet convergence. */
final readonly class NodeRecipeProgram
{
    public function __construct(private NodeShell $shell) {}

    /** @return array<string, ToolPin> */
    public function tools(Node $node, MachineRecipe $recipe): array
    {
        $architecture = match ($node->architecture) {
            'x86_64', 'amd64' => 'amd64',
            'aarch64', 'arm64' => 'arm64',
            default => throw new ResourceOperationException('node.recipe_architecture', 'The Node architecture is not supported by the recipe.', 422),
        };
        $node->load('roles');
        $names = $node->roles->isEmpty() ? $recipe->profile('operator', $architecture) : [];
        foreach ($node->roles as $role) {
            if ($role->status !== LifecycleStatus::Active) {
                throw new ResourceOperationException('node.recipe_role_inactive', 'Every assigned role must be active before recipe convergence.', 409);
            }
            $names = [...$names, ...$recipe->profile($role->role->value, $architecture)];
        }
        $tools = [];
        foreach (array_unique($names) as $name) {
            $pin = $recipe->tools[$name] ?? throw new ResourceOperationException('node.recipe_pin_missing', "The recipe has no pin for role prerequisite [{$name}]. Nothing was changed.", 422);
            if ($pin->source !== 'ubuntu' && ! isset($pin->artifacts[$architecture])) {
                throw new ResourceOperationException('node.recipe_artifact_missing', "The recipe has no {$architecture} artifact for [{$name}].", 422);
            }
            $tools[$name] = $pin;
        }
        ksort($tools);

        return $tools;
    }

    /**
     * @param  array<string, ToolPin>  $tools
     * @return array<string, ?string>
     */
    public function inspect(Node $node, MachineRecipe $recipe, array $tools): array
    {
        $report = $this->run($node, $recipe, $tools, 'inspect');
        $versions = [];
        foreach ($tools as $name => $pin) {
            if (! array_key_exists($name, $report)) {
                throw new RuntimeException('Recipe inspection omitted a selected tool.');
            }
            $value = $report[$name];
            if ($value !== null && ! is_string($value)) {
                throw new RuntimeException('Recipe inspection returned an invalid version.');
            }
            $versions[$name] = $value;
        }

        return $versions;
    }

    /**
     * @param  array<string, ToolPin>  $tools
     * @return array<string, mixed>
     */
    public function apply(Node $node, MachineRecipe $recipe, array $tools): array
    {
        $report = $this->run($node, $recipe, $tools, 'apply');
        if (($report['healthy'] ?? false) !== true) {
            throw new ResourceOperationException('node.recipe_health_failed', 'Recipe convergence did not return a successful health check. No automatic rollback was attempted.', 502);
        }

        return $report;
    }

    /**
     * @param  array<string, ToolPin>  $tools
     * @return array<string, mixed>
     */
    private function run(Node $node, MachineRecipe $recipe, array $tools, string $mode): array
    {
        $program = file_get_contents(resource_path('machine-software/converge-node.py'));
        if ($program === false) {
            throw new RuntimeException('The recipe converge program is missing.');
        }
        $data = json_decode($recipe->json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($data) || ! is_array($data['tools'] ?? null)) {
            throw new RuntimeException('The recipe document is invalid.');
        }
        $payload = json_encode([
            'snapshot' => $recipe->snapshot,
            'architecture' => in_array($node->architecture, ['x86_64', 'amd64'], true) ? 'amd64' : 'arm64',
            'tools' => array_intersect_key($data['tools'], $tools),
        ], JSON_THROW_ON_ERROR);
        $result = $this->shell->run($node, new RemoteCommand(
            $mode === 'inspect' ? ['python3', '-', $mode, $payload] : ['sudo', 'timeout', '--signal=TERM', '--kill-after=10', '840', 'python3', '-', $mode, $payload],
            input: $program,
            maxOutputBytes: 65536,
            timeout: $mode === 'inspect' ? 60 : 900,
        ));
        if (! $result->succeeded() || $result->truncated) {
            // Manager output is never exposed. The fixed program emits only a stage identifier on failure.
            $stage = trim($result->stdout);
            if (preg_match('/^recipe-stage:[a-z-]+(?::[A-Za-z0-9@_.:-]+)?$/D', $stage) !== 1) {
                $stage = 'recipe-stage:remote';
            }
            if (preg_match('/^recipe-stage:platform:([A-Za-z0-9._-]+):([A-Za-z0-9._-]+)$/D', $stage, $platform) === 1) {
                throw new ResourceOperationException('node.recipe_platform_unsupported', "Node operating system [{$platform[1]}/{$platform[2]}] is not supported.", 422);
            }
            throw new ResourceOperationException('node.recipe_failed', "Recipe convergence stopped at {$stage}. The Node may be partly changed. No automatic rollback was attempted; inspect the Node before retrying.", 502);
        }
        $report = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($report)) {
            throw new RuntimeException('Recipe convergence returned an invalid report.');
        }

        $fields = [];
        foreach ($report as $key => $value) {
            if (! is_string($key)) {
                throw new RuntimeException('Recipe convergence returned an invalid report key.');
            }
            $fields[$key] = $value;
        }

        return $fields;
    }
}
