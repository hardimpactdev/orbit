<?php

declare(strict_types=1);

namespace App\Actions\Php;

use App\Contracts\PhpRuntimeArtifactConverger;
use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Data\Php\PhpRuntimeOperation;
use App\Enums\Apps\InstanceDriver;
use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Php\PhpRuntimeManager;
use App\Services\Workspaces\WorkspacePlacement;
use RuntimeException;

final readonly class UsePhpRuntime
{
    public function __construct(
        private PhpRuntimeManager $runtime,
        private PhpRuntimeArtifactConverger $artifacts,
    ) {}

    /** @mago-expect lint:excessive-parameter-list */
    public function handle(
        ?string $version,
        ?string $instance = null,
        ?string $workspace = null,
        ?string $node = null,
        bool $inherit = false,
        bool $cli = false,
        ?Node $caller = null,
    ): PhpRuntimeOperation {
        $operation = $this->runtime->use(
            version: $version,
            instance: $instance,
            workspace: $workspace,
            node: $node,
            inherit: $inherit,
            cli: $cli,
            caller: $caller,
        );

        if ($operation->failed()) {
            return $operation;
        }

        $result = $operation->payload['result'] ?? null;

        if (! is_array($result)) {
            throw new RuntimeException('PHP runtime selection result is missing target context.');
        }

        /** @var array<string, mixed> $result */

        $warnings = match ($result['target'] ?? null) {
            'instance' => [
                ...$this->sharedPolicyFanoutWarnings($result),
                ...$this->convergeProject($result),
            ],
            'workspace' => $this->convergeWorkspace($result),
            'node_cli' => [],
            default => throw new RuntimeException('PHP runtime selection result has an invalid target.'),
        };

        return new PhpRuntimeOperation(
            payload: $operation->payload,
            meta: [
                ...$operation->meta,
                'warnings' => $warnings,
            ],
        );
    }

    /**
     * The shared app PHP policy fans out to every sibling instance; name each
     * one so the operator sees exactly which other runtimes this run changed.
     *
     * @param  array<string, mixed>  $result
     * @return list<array<string, string>>
     */
    private function sharedPolicyFanoutWarnings(array $result): array
    {
        if (($result['changed'] ?? null) !== true) {
            return [];
        }

        $appName = $this->requiredString($result, 'app');
        $selectedInstance = $this->requiredString($result, 'instance');
        $version = $this->requiredString($result, 'version');
        $app = App::query()->with('instances')->where('name', $appName)->first();

        if (! $app instanceof App) {
            return [];
        }

        $warnings = [];

        foreach ($app->instances as $sibling) {
            if ($sibling->name === $selectedInstance || $sibling->driver !== InstanceDriver::Orbit) {
                continue;
            }

            $config = $sibling->driver_config;
            $nodeName = $config instanceof OrbitInstanceDriverConfigData ? $config->node : null;
            $placement = $nodeName === null ? '' : " on node '{$nodeName}'";

            $warnings[] = [
                'code' => 'instance.shared_php_policy_applied',
                'family' => 'instance',
                'message' => "Sibling instance '{$appName}.{$sibling->name}'{$placement} now follows the shared "
                    ."app PHP policy {$version} changed by this run.",
                'next_command' => 'doctor --family=instance',
            ];
        }

        return $warnings;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<array<string, string>>
     */
    private function convergeProject(array $result): array
    {
        $name = $this->requiredString($result, 'app');
        $app = App::query()->with('node')->where('name', $name)->first();

        if (! $app instanceof App) {
            throw new RuntimeException("PHP runtime target project '{$name}' disappeared before convergence.");
        }

        return $this->artifacts->forApp($app);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<array<string, string>>
     */
    private function convergeWorkspace(array $result): array
    {
        $workspaceName = $this->requiredString($result, 'workspace');
        $appName = $this->requiredString($result, 'app');
        $workspace = Workspace::query()
            ->with(['app', 'instance'])
            ->where('name', $workspaceName)
            ->whereHas('app', static fn ($query) => $query->where('name', $appName))
            ->first();
        $node = $workspace instanceof Workspace
            ? app(WorkspacePlacement::class)->nodeForWorkspace($workspace)
            : null;

        if (! $workspace instanceof Workspace || ! $node instanceof Node) {
            throw new RuntimeException(
                "PHP runtime target workspace '{$workspaceName}' disappeared before convergence.",
            );
        }

        return $this->artifacts->forWorkspace($workspace, $node);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function requiredString(array $result, string $key): string
    {
        $value = $result[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("PHP runtime selection result is missing '{$key}'.");
        }

        return trim($value);
    }
}
