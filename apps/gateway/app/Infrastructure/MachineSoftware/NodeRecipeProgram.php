<?php

declare(strict_types=1);

namespace App\Infrastructure\MachineSoftware;

use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Node;
use RuntimeException;

/** Fixed recipe operations over the same pinned SSH transport as fleet convergence. */
final readonly class NodeRecipeProgram
{
    /** The tools `recipe.install-javascript` installs. */
    public const array JavascriptTools = ['bun', 'node', 'pnpm', 'vp', 'vp-cli'];

    public function __construct(private AgentCommandRunner $agents, private PhpBuildInstaller $php, private PrivateDnsManager $dns) {}

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
        if (is_array($node->settings['task_vms'] ?? null)) {
            $names = [...$names, ...RecipeLoader::IncusPackages];
        }
        $tools = [];
        foreach (array_unique($names) as $name) {
            $pin = $recipe->tools[$name] ?? throw new ResourceOperationException('node.recipe_pin_missing', "The recipe has no pin for role prerequisite [{$name}]. Nothing was changed.", 422);
            if ($pin->source !== 'ubuntu' && ! isset($pin->artifacts[$architecture])) {
                throw new ResourceOperationException('node.recipe_artifact_missing', "The recipe has no {$architecture} artifact for [{$name}].", 422);
            }
            $tools[$name] = $pin->forArchitecture($architecture);
        }
        ksort($tools);

        return $tools;
    }

    /**
     * @param  array<string, ToolPin>  $tools
     * @return array{tools: array<string, array{source: string, version: string, installed: ?string, changed: bool, status: string, refusal?: string}>, actions: list<string>}
     */
    public function inspect(Node $node, MachineRecipe $recipe, array $tools): array
    {
        $report = $this->run($node, $recipe, $tools, 'inspect');
        $assessment = [];
        foreach ($tools as $name => $pin) {
            $value = $report[$name] ?? null;
            if (! is_array($value) || ($value['source'] ?? null) !== $pin->source || ($value['version'] ?? null) !== $pin->version
                || ! array_key_exists('installed', $value) || ($value['installed'] !== null && ! is_string($value['installed']))
                || ! is_bool($value['changed'] ?? null) || ! is_string($value['status'] ?? null)) {
                throw new RuntimeException('Recipe inspection returned an invalid assessment.');
            }
            $assessment[$name] = ['source' => $pin->source, 'version' => $pin->version, 'installed' => $value['installed'], 'changed' => $value['changed'], 'status' => $value['status']];
            if ($value['status'] === 'unsupported_newer') {
                $refusal = $value['refusal'] ?? 'origin:'.$name;
                $allowed = array_map(static fn (string $reason): string => 'origin:'.$name.$reason, [
                    '', ':dpkg-log-missing', ':snapshot-unavailable', ':snapshot-unproved', ':snapshot-lookup-limit',
                ]);
                if (! in_array($refusal, $allowed, true)) {
                    throw new RuntimeException('Recipe inspection returned an invalid refusal.');
                }
                $assessment[$name]['refusal'] = $refusal;
            }
        }

        $actions = $report['_actions'] ?? [];
        if (! is_array($actions) || ! array_is_list($actions) || array_diff($actions, ['repair_ubuntu_sources', 'retire_git_source', 'pin_git', 'retire_incus_source', 'pin_incus', 'remove_incus_agent', 'remove_nodejs_package', 'retire_node_source', 'retire_node_config', 'remove_corepack_links']) !== []) {
            throw new RuntimeException('Recipe inspection returned invalid planned actions.');
        }

        return ['tools' => $assessment, 'actions' => $actions];
    }

    /** @param array<string, ToolPin> $tools */
    public function incusPreflight(Node $node, MachineRecipe $recipe, array $tools): void
    {
        if (isset($tools['incus'])) {
            $this->run($node, $recipe, $tools, 'incus-preflight');
        }
    }

    /**
     * Checks the PHP build before any change: another PHP apt source, or PHP packages outside the recipe that this
     * profile does not purge, refuse the convergence before a download. Dry runs call it too.
     *
     * @param  array<string, ToolPin>  $tools
     * @return array<string, mixed> The installer's plan, or [] without a PHP set.
     */
    public function phpPreflight(Node $node, MachineRecipe $recipe, array $tools): array
    {
        $versions = self::phpVersions($recipe, $tools);
        if ($versions === []) {
            return [];
        }
        $result = $this->php->run($node, $recipe, $versions, 'plan', $this->pcov($node, $recipe), 'refuse', $this->fpm($node), $this->opentelemetry($node, $recipe));
        if ($result->outcome !== 'planned') {
            throw new ResourceOperationException('node.recipe_php_refused', PhpBuildInstaller::describe($result).' Nothing was changed.', 409);
        }

        return $result->data;
    }

    /**
     * @param  array<string, ToolPin>  $tools
     * @return array<string, mixed>
     */
    public function apply(Node $node, MachineRecipe $recipe, array $tools): array
    {
        $this->phpPreflight($node, $recipe, $tools);
        $versions = self::phpVersions($recipe, $tools);
        // The PHP build has one installer, php.install-build (ADR 0216); the recipe program installs the rest.
        $others = array_diff_key($tools, array_flip(self::phpPackages($recipe, $versions)));
        $report = $this->run($node, $recipe, $others, 'apply');
        if (($report['healthy'] ?? false) !== true) {
            throw new ResourceOperationException('node.recipe_health_failed', 'Recipe convergence did not return a successful health check. No automatic rollback was attempted.', 502);
        }
        if ($versions === []) {
            return $report;
        }
        $result = $this->php->run($node, $recipe, $versions, 'apply', $this->pcov($node, $recipe), 'refuse', $this->fpm($node), $this->opentelemetry($node, $recipe));
        if (! in_array($result->outcome, PhpBuildInstaller::Succeeded, true)) {
            // Only the installer's own check of the old versions proves a restore (ADR 0216).
            $restored = match (true) {
                ($result->data['restored'] ?? false) === true => 'The installer restored the previous PHP build and checked it.',
                $result->outcome === 'rollback_failed' => 'The rollback of the previous PHP build failed too. The Node may run a mixed PHP build; inspect its PHP packages and PHP-FPM now.',
                default => 'The PHP packages were not changed.',
            };
            throw new ResourceOperationException('node.recipe_php_failed', 'Recipe convergence stopped at the PHP build: '.PhpBuildInstaller::describe($result).' '.$restored, 502);
        }
        $names = static fn (mixed $list): array => is_array($list) ? array_values(array_filter($list, is_string(...))) : [];
        $report['changed_packages'] = array_values(array_unique([...$names($report['changed_packages'] ?? null), ...$names($result->data['changed'] ?? null)]));
        $report['php'] = $result->data;

        if ($result->outcome === 'applied' && $node->roles()->where('role', RoleName::Vpn)->where('status', LifecycleStatus::Active)->exists()) {
            $this->dns->converge();
        }

        return $report;
    }

    /**
     * The PHP versions whose whole set the tools hold.
     *
     * @param  array<string, ToolPin>  $tools
     * @return list<string>
     */
    public static function phpVersions(MachineRecipe $recipe, array $tools): array
    {
        return array_map(strval(...), array_keys($recipe->phpExtensions(array_keys($tools))));
    }

    /**
     * @param  list<string>  $versions
     * @return list<string>
     */
    private static function phpPackages(MachineRecipe $recipe, array $versions): array
    {
        $names = [];
        foreach ($versions as $version) {
            $names = [...$names, ...$recipe->php($version)->packages];
        }

        return array_values(array_unique($names));
    }

    /** @return 'disabled'|'all' */
    private function fpm(Node $node): string
    {
        $profiles = $node->roles->map(fn ($role): string => $role->role->value)->all();

        return PhpBuildInstaller::fpmForProfiles(array_values($profiles));
    }

    /** PCOV loads only for the recipe's PCOV profiles, such as app-dev and ci-runner. */
    private function pcov(Node $node, MachineRecipe $recipe): bool
    {
        $profiles = $node->roles->map(fn ($role): string => $role->role->value)->all();

        return array_intersect($profiles, $recipe->phpPolicy->pcovProfiles) !== [];
    }

    private function opentelemetry(Node $node, MachineRecipe $recipe): bool
    {
        $profiles = $node->roles->map(fn ($role): string => $role->role->value)->all();

        return array_intersect($profiles, $recipe->phpPolicy->opentelemetryProfiles) !== [];
    }

    /**
     * The typed recipe input for the selected tools. `recipe.inspect`, `recipe.apply`, and `recipe.install-javascript`
     * take the same shape.
     *
     * @param  array<string, ToolPin>  $tools
     * @return array<string, mixed>
     */
    public static function payload(Node $node, MachineRecipe $recipe, array $tools): array
    {
        $data = json_decode($recipe->json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($data) || ! is_array($data['tools'] ?? null)) {
            throw new RuntimeException('The recipe document is invalid.');
        }
        foreach ($tools as $name => $pin) {
            if (! is_array($data['tools'][$name] ?? null)) {
                throw new RuntimeException('A selected recipe tool is invalid.');
            }
            $data['tools'][$name]['version'] = $pin->version;
        }
        return [
            'user' => $node->user,
            'snapshot' => $recipe->snapshot,
            'git_packages' => $recipe->gitPackages, 'artifact_mirror' => $recipe->artifactMirror,
            'architecture' => in_array($node->architecture, ['x86_64', 'amd64'], true) ? 'amd64' : 'arm64',
            'tools' => array_intersect_key($data['tools'], $tools),
            'vp_lock' => isset($tools['vp']) ? VitePlusRuntimeLock::read($tools['vp']) : null,
        ];
    }

    /**
     * The `recipe.install-javascript` input for the JavaScript runtimes a role's recipe profile pins, or null when it
     * pins none. Role convergence and image programs install them with the same verified archives and launchers as
     * recipe convergence.
     *
     * @return array<string, mixed>|null
     */
    public static function javascript(Node $node, RoleName $role): ?array
    {
        $recipe = new RecipeLoader()->load();
        $architecture = in_array($node->architecture, ['x86_64', 'amd64'], true) ? 'amd64' : 'arm64';
        $tools = [];
        foreach (array_intersect($recipe->profile($role->value), self::JavascriptTools) as $name) {
            $tools[$name] = $recipe->tools[$name]->forArchitecture($architecture);
        }

        return $tools === [] ? null : self::payload($node, $recipe, $tools);
    }

    /**
     * @param  array<string, ToolPin>  $tools
     * @return array<string, mixed>
     */
    private function run(Node $node, MachineRecipe $recipe, array $tools, string $mode): array
    {
        $payload = self::payload($node, $recipe, $tools);
        try {
            return $this->agents->run($node, 'recipe.'.$mode, $payload);
        } catch (AgentCommandRefused $error) {
            if ($error->errorCode !== 'recipe.failed') {
                throw $error;
            }
            $failure = $error->details;
            $stage = is_string($failure['stage'] ?? null) ? $failure['stage'] : 'recipe-stage:remote';
            if ($stage === 'recipe-stage:incus:running') {
                throw new ResourceOperationException('node.recipe_incus_busy', 'Incus changes require a drained host. '.new CommandActivityInputSanitizer()->redactText(is_string($failure['reason'] ?? null) ? $failure['reason'] : '').' Nothing was changed.', 409);
            }
            if ($stage === 'recipe-stage:docker-provider:docker-ce') {
                throw new ResourceOperationException('node.recipe_docker_provider', 'docker-provider:docker-ce: Docker CE requires an explicit planned-window migration. Run php artisan node:migrate-docker '.$node->id.' --dry-run on the Gateway. Nothing was changed by recipe convergence.', 409);
            }
            if (preg_match('/^recipe-stage:platform:([A-Za-z0-9._-]+):([A-Za-z0-9._-]+)$/D', $stage, $platform) === 1) {
                throw new ResourceOperationException('node.recipe_platform_unsupported', "Node operating system [{$platform[1]}/{$platform[2]}] is not supported.", 422);
            }
            $detail = new CommandActivityInputSanitizer()->redactText(json_encode($failure, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
            throw new ResourceOperationException('node.recipe_failed', 'Recipe convergence stopped at '.$stage.'. '.mb_substr($detail, 0, 30000).' The Node may be partly changed. No automatic rollback was attempted; inspect the Node before retrying.', 502);
        }
    }
}
