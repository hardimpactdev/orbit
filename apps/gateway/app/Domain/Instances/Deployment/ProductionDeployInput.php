<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\Instances\Environment\InstanceEnvironmentContextResolver;
use App\Domain\Instances\Environment\InstanceEnvironmentRenderer;
use App\Domain\Instances\Environment\InstanceEnvironmentStore;
use App\Domain\Instances\Environment\NativeInstanceEnvironmentSync;
use App\Domain\Instances\InstanceGitHubReadTokens;
use App\Domain\Instances\ProductionPhpRuntimeIdentity;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Instance;
use App\Models\Operation;
use App\Support\ValidatedData;
use Dotenv\Dotenv;

/**
 * The full production deploy intent for the native run (docs/cli/instance/deploy.mmd). Every Node action is a public
 * argv list or link the Gateway derives from the frozen intent; the read token, each deploy step and the rendered `.env`
 * travel only as vault copies.
 */
final readonly class ProductionDeployInput
{
    /** Suppress output before parsing private commands; the compound command receives empty stdin. */
    public const string QuietStep = "exec >/dev/null 2>&1\n{\n";

    public function __construct(private InstanceDeployStepStore $steps, private SwampCommandStep $copies) {}

    /** The first deploy creates the dedicated master; later deployments reload the recorded master.
     * @param  array<string,mixed>  $deployment
     * @return array<string,mixed>|null
     */
    public function phpInput(Instance $instance, array $deployment): ?array
    {
        if ($deployment['php'] !== true) {
            return null;
        }
        $configure = $instance->production_php_service === null && $instance->production_php_pool === null && $instance->production_php_socket === null;
        $identity = $configure ? ProductionPhpRuntimeIdentity::forProvisioning($instance, ValidatedData::string($deployment['php_version'])) : ProductionPhpRuntimeIdentity::from($instance);
        $runtime = ValidatedData::object($deployment['php_runtime']);
        $configuration = ValidatedData::object($runtime['configuration']);

        return ['service' => $identity->service, 'configure' => $configure, 'identity' => $identity->attributes(), 'version' => $identity->version,
            'directories' => [$identity->runtimeDirectory, $identity->generatedDirectory],
            'main' => $identity->generatedDirectory.'/php-fpm.conf', 'local_path' => $identity->localTuningPath,
            'local_defaults' => ValidatedData::string($configuration['local_defaults']), 'unit' => ValidatedData::string($configuration['unit']),
            'files' => [
                ['path' => $identity->generatedDirectory.'/php-fpm.conf', 'content' => ValidatedData::string($configuration['main']), 'mode' => '0644'],
                ['path' => $identity->generatedDirectory.'/pool.conf', 'content' => ValidatedData::string($configuration['pool']), 'mode' => '0644'],
                ['path' => $identity->generatedDirectory.'/99-orbit-runtime.ini', 'content' => ValidatedData::string($configuration['master_ini']), 'mode' => '0644'],
                ['path' => $identity->markerPath, 'content' => $identity->marker(), 'mode' => '0600'],
            ]];
    }

    /**
     * The production keys a development run carries empty: Swamp resolves an expression that reads only inputs when
     * the run starts, also for a step its guard skips.
     *
     * @return array<string,mixed>
     */
    public static function placeholder(): array
    {
        return ['production' => ['instance_id' => 0, 'user' => '', 'home' => '', 'release' => '', 'release_path' => '', 'commit' => null,
            'branch' => '', 'credential' => '', 'prepare' => [], 'shared' => [], 'links' => [], 'grants' => [], 'steps' => [],
            'before' => [], 'after' => [], 'cleanup' => []],
            'environment' => ['instance_id' => 0, 'environment' => 'production', 'base' => '', 'user' => '', 'keys' => [], 'owned_keys' => [], 'sha256' => '', 'testing' => null],
            'process_members' => [], 'runtime_definitions' => ['restart' => []], 'php_input' => null];
    }

    /** @return array{intent:array<string,mixed>,execution_secrets:array<string,array{vault:string,key:string}>} */
    public function workflowInput(Operation $operation): array
    {
        $admitted = app(InstanceDeploymentDirectInput::class)->admitted($operation);
        $instance = $this->owner($operation);
        $deployment = InstanceDeploymentIntent::admitted($operation);
        $raw = ValidatedData::object($operation->input['deployment']);
        $user = ValidatedData::string($deployment['user']);
        $home = ValidatedData::string($deployment['home']);
        $release = InstanceDeploymentIntent::releaseName($operation);
        $directory = "{$home}/releases/{$release}";
        $suffix = ValidatedData::string($deployment['application_suffix']);
        $application = $directory.$suffix;
        $secrets = [];
        $steps = $this->commands($operation, $instance, $deployment['steps'], $secrets);
        $secrets['credential'] = app(InstanceGitHubReadTokens::class)->credentialCopy($deployment['repository'], "instance-deploy-operation-{$operation->id}-credential");
        $environment = app(NativeInstanceEnvironmentSync::class)->inputs($operation);
        $secrets = [...$secrets, ...$environment['execution_secrets']];
        $definitions = app(InstanceRuntimeDefinitions::class);
        $processes = $definitions->processWorkflowInput($operation);
        $secrets = [...$secrets, ...$processes['execution_secrets']];
        $credential = "/tmp/orbit-deploy-operation-{$operation->id}-credential";
        [$prepare, $links, $storage] = $this->prepare($raw, $directory, $application, $credential, InstanceDeploymentIntent::commit($operation));

        return ['intent' => [...$admitted, 'deployment' => $raw,
            'application' => $application, 'account_home' => $home,
            'vp_setup' => $operation->input['vp_setup'] ?? null,
            'ssh_execution' => app(SwampSshExecutionInput::class)->forNode($instance->node),
            'environment' => $environment['intent']['environment'],
            'process_members' => $processes['members'],
            'runtime_definitions' => ['restart' => $this->restart($operation)],
            'php_input' => $operation->input['php_input'] ?? null,
            'production' => ['instance_id' => $instance->id, 'user' => $user, 'home' => $home, 'release' => $release,
                'release_path' => $directory, 'commit' => InstanceDeploymentIntent::commit($operation), 'branch' => $deployment['branch'],
                'credential' => $credential,
                'prepare' => array_slice($prepare, 0, 2), 'shared' => array_map(static fn (array $action): array => isset($action['empty'], $action['source']) ? [...$action, 'empty' => $action['empty'] - 2, 'source' => $action['source'] - 2] : $action, array_slice($prepare, 2)), 'links' => $links, 'grants' => $this->grants($raw, $directory, $home, $storage),
                'steps' => $steps, 'before' => array_values(array_filter($steps, static fn (array $step): bool => $step['phase'] === 'before_activation')),
                'after' => array_values(array_filter($steps, static fn (array $step): bool => $step['phase'] === 'after_activation')),
                'cleanup' => array_map(static fn (string $name): array => ['name' => $name, 'path' => "{$home}/releases/{$name}"],
                    RecordedReleases::afterDeploy($instance, $release)['remove'])],
        ], 'execution_secrets' => $secrets];
    }

    /**
     * Each admitted step with its index, after its stored command still matches the digest admission froze; the
     * command, after the quiet first line, goes to its own vault key.
     *
     * @param  list<array{name:string,phase:string,required:bool,timeout_seconds:int,command_digest:string}>  $admitted
     * @param  array<string,array{vault:string,key:string}>  $secrets
     * @return list<array{index:int,name:string,phase:string,timeout_seconds:int}>
     */
    private function commands(Operation $operation, Instance $instance, array $admitted, array &$secrets): array
    {
        $commands = $this->steps->ordered($instance);
        $context = app(InstanceEnvironmentContextResolver::class)->resolve($instance, true);
        $rendered = app(InstanceEnvironmentRenderer::class)->render($context, app(InstanceEnvironmentStore::class)->values($instance));
        $prefix = self::QuietStep;
        foreach (Dotenv::parse($rendered) as $key => $value) {
            if (in_array($key, ['HOME', 'USER', 'LOGNAME', 'PATH', 'VP_HOME'], true)) {
                continue;
            }
            $prefix .= 'export '.$key."='".str_replace("'", "'\"'\"'", $value ?? '')."'\n";
        }
        if (count($commands) !== count($admitted)) {
            throw $this->forbidden();
        }
        $steps = [];
        foreach ($commands as $index => $command) {
            $entry = $admitted[$index];
            if ($entry['name'] !== $command->name || $entry['phase'] !== $command->phase->value || $entry['timeout_seconds'] !== $command->timeoutSeconds
                || $entry['command_digest'] !== hash('sha256', $command->command)) {
                throw $this->forbidden();
            }
            // The step's output stays on the Node: run history, logs and the worker's journal never hold it.
            $secrets['step_'.$index] = $this->copies->executionCopy('orbit-control-inputs', "instance-deploy-operation-{$operation->id}-step-{$index}",
                $prefix.$command->command."\n} </dev/null\n");
            $steps[] = ['index' => $index, 'name' => $command->name, 'phase' => $command->phase->value, 'timeout_seconds' => $command->timeoutSeconds];
        }

        return $steps;
    }

    /**
     * The release preparation as the production account: clone, check out, seed the shared paths; then each link.
     *
     * @param  array<string,mixed>  $deployment
     * @return array{0:list<array{argv:list<string>,ok:list<int>,empty?:int,source?:int}>,1:list<array{path:string,target:string}>,2:?string}
     */
    private function prepare(array $deployment, string $directory, string $application, string $credential, ?string $commit): array
    {
        $home = ValidatedData::string($deployment['home']);
        $suffix = ValidatedData::string($deployment['application_suffix']);
        $git = ['/usr/bin/env', 'GIT_TERMINAL_PROMPT=0', 'GIT_CONFIG_NOSYSTEM=1', 'GIT_CONFIG_GLOBAL=/dev/null', '/usr/bin/git', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false'];
        $protocols = array_merge(...array_map(static fn (string $protocol): array => ['-c', "protocol.{$protocol}.allow=always"], ValidatedData::stringList($deployment['protocols'])));
        $prepare = [
            ['argv' => [...$git, '-c', 'credential.helper=', '-c', 'credential.helper=store --file='.$credential, '-c', 'protocol.allow=never', ...$protocols,
                '-c', 'http.followRedirects=false', '-c', 'url.https://github.com/.insteadOf=git@github.com:', '-c', 'url.https://github.com/.insteadOf=ssh://git@github.com/',
                'clone', '--quiet', '--no-checkout', '--origin', 'origin', '--', ValidatedData::string($deployment['repository']), $directory], 'ok' => [0]],
            ['argv' => [...$git, '-C', $directory, 'checkout', '--quiet', '--detach', $commit ?? 'refs/remotes/origin/'.ValidatedData::string($deployment['branch'])], 'ok' => [0]],
        ];
        $links = [['path' => "{$application}/.env", 'target' => str_repeat('../', 2 + substr_count($suffix, '/')).'.env']];
        $shared = ValidatedData::stringList($deployment['shared_paths']);
        foreach ($shared as $path) {
            // Only an empty persistent directory is seeded; a missing release directory seeds nothing.
            $prepare[] = ['argv' => ['/usr/bin/mkdir', '-p', '--', "{$home}/{$path}"], 'ok' => [0]];
            $empty = count($prepare);
            $prepare[] = ['argv' => ['/usr/bin/find', "{$home}/{$path}", '-mindepth', '1', '-maxdepth', '1', '-print', '-quit'], 'ok' => [0]];
            $source = count($prepare);
            $prepare[] = ['argv' => ['/usr/bin/test', '-d', "{$directory}/{$path}"], 'ok' => [0, 1]];
            $prepare[] = ['argv' => ['/usr/bin/cp', '-a', '-T', '--', "{$directory}/{$path}", "{$home}/{$path}"], 'ok' => [0], 'empty' => $empty, 'source' => $source];
            $prepare[] = ['argv' => ['/usr/bin/rm', '-rf', '--', "{$directory}/{$path}"], 'ok' => [0]];
            $prepare[] = ['argv' => ['/usr/bin/mkdir', '-p', '--', dirname("{$directory}/{$path}")], 'ok' => [0]];
            $links[] = ['path' => "{$directory}/{$path}", 'target' => "{$home}/{$path}"];
        }
        // A shared `storage` beside a served `public` gets its public storage link.
        $relative = ltrim($suffix, '/');
        $storage = null;
        if (in_array(($relative === '' ? '' : "{$relative}/").'storage', $shared, true)) {
            $storage = "{$home}/".($relative === '' ? '' : "{$relative}/").'storage/app/public';
            $prepare[] = ['argv' => ['/usr/bin/mkdir', '-p', '--', $storage], 'ok' => [0]];
            $links[] = ['path' => "{$application}/public/storage", 'target' => '../storage/app/public'];
        }
        foreach (self::served($deployment) as $served) {
            $name = ValidatedData::string($served['directory']);
            $links[] = ['path' => $directory.($name === '' ? '' : "/{$name}").'/.env',
                'target' => str_repeat('../', $name === '' ? 2 : 3 + substr_count($name, '/')).'env/'.($name === '' ? '' : "{$name}/").'.env'];
        }

        return [$prepare, $links, $storage];
    }

    /**
     * Caddy's access to each served web root of the new release, and to the public storage it links.
     *
     * @param  array<string,mixed>  $deployment
     * @return list<list<string>>
     */
    private function grants(array $deployment, string $directory, string $home, ?string $storage): array
    {
        $roots = [ValidatedData::string($deployment['relative_root'])];
        foreach (self::served($deployment) as $served) {
            $roots[] = ValidatedData::string($served['web_root']);
        }
        $grants = [];
        foreach (array_values(array_unique($roots)) as $root) {
            $parts = explode('/', $root);
            $ancestors = [$directory];
            foreach (array_slice($parts, 0, -1) as $index => $part) {
                $ancestors[] = $directory.'/'.implode('/', array_slice($parts, 0, $index + 1));
            }
            $grants = [...$grants, ...$this->grant($ancestors, "{$directory}/{$root}")];
        }
        if ($storage !== null) {
            $ancestors = [];
            for ($path = dirname($storage); $path !== $home; $path = dirname($path)) {
                array_unshift($ancestors, $path);
            }
            $grants = [...$grants, ...$this->grant($ancestors, $storage)];
        }

        return $grants;
    }

    /**
     * @param  list<string>  $ancestors
     * @return list<list<string>>
     */
    private function grant(array $ancestors, string $tree): array
    {
        return [['/usr/bin/setfacl', '-m', 'u:caddy:--x', '--', ...$ancestors],
            ['/usr/bin/setfacl', '-P', '-R', '-m', 'u:caddy:r-X', '--', $tree],
            ['/usr/bin/find', $tree, '-type', 'd', '-exec', '/usr/bin/setfacl', '-m', 'd:u:caddy:r-x', '--', '{}', '+']];
    }

    /**
     * @param  array<string,mixed>  $deployment
     * @return list<array<string,mixed>>
     */
    private static function served(array $deployment): array
    {
        $served = $deployment['served'];
        if (! is_array($served) || ! array_is_list($served)) {
            throw new \InvalidArgumentException('The admitted served applications must be a list.');
        }

        return array_map(ValidatedData::object(...), $served);
    }

    /** @return list<array{process_id:int,runtime:string,name:string}> */
    private function restart(Operation $operation): array
    {
        $restart = ValidatedData::object($operation->input['runtime_definitions'])['restart'];
        if (! is_array($restart) || ! array_is_list($restart)) {
            throw $this->forbidden();
        }

        return array_map(static function (mixed $process): array {
            $process = ValidatedData::object($process);

            return ['process_id' => ValidatedData::integer($process['process_id']), 'runtime' => ValidatedData::string($process['runtime']),
                'name' => ValidatedData::string($process['name'])];
        }, $restart);
    }

    private function owner(Operation $operation): Instance
    {
        $instance = Instance::query()->with(['node', 'project'])->findOrFail($operation->target_id);
        $intent = ValidatedData::object($operation->input['deployment']);
        if ($operation->kind !== 'instance.deploy' || $operation->target_type !== 'instance' || $intent['variant'] !== 'production'
            || $operation->node_id !== $instance->node_id || ! $instance->placedOnAppProd()
            || $intent['user'] !== $instance->production_user || $intent['home'] !== $instance->production_home
            || $intent['project_id'] !== $instance->project_id || $intent['repository'] !== $instance->project->repository_url
            || $intent['node_user'] !== $instance->node->user) {
            throw $this->forbidden();
        }

        return $instance;
    }

    private function forbidden(): ResourceOperationException
    {
        return new ResourceOperationException('operation.execution_forbidden', 'The production deployment no longer matches its admitted owner.', 403);
    }
}
