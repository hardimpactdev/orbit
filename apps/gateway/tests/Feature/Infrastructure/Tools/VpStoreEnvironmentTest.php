<?php

declare(strict_types=1);

use App\Actions\Doctor\ToolDoctorProbe;
use App\Actions\Tools\PrepareVpEnvironmentAction;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Instances\Dependencies\NpmVersionRange;
use App\Domain\Instances\Deployment\DeploymentPhase;
use App\Domain\Instances\Deployment\DeploymentRelease;
use App\Domain\Instances\Deployment\DeploymentRequest;
use App\Domain\Instances\Deployment\DeploymentStep;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\Storage\CheckoutRemovalBoundary;
use App\Domain\Projects\DevelopmentDeployStep;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\SemverVersionNormalizer;
use App\Domain\Tools\ToolInspector;
use App\Domain\Tools\ToolManagerScopeLock;
use App\Domain\Tools\VersionConstraint;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\AppProd\ProductionSshExecutor;
use App\Infrastructure\Instances\RemoteDevelopmentDeployment;
use App\Infrastructure\Instances\RemoteProductionDeployment;
use App\Infrastructure\RemotePrograms\RemoteProgramCatalog;
use App\Infrastructure\RemotePrograms\SshProgramRunner;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tools\RemoteToolCommandRunner;
use App\Infrastructure\Tools\VpToolManager;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Tool;
use App\Models\ToolManagerRecord;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\UnsupportedPackageInventory;
use Tests\Support\VpStoreSshExecutor;

/** @return array{PrepareVpEnvironmentAction, VpStoreSshExecutor, Instance, RemoteProductionDeployment|RemoteDevelopmentDeployment, VpToolManager} */
function vp_store_fixture(bool $registryUnavailable = false, bool $development = false): array
{
    Http::preventStrayRequests();
    Http::fake([
        'https://registry.npmjs.org/vite-plus' => $registryUnavailable ? Http::response('unavailable', 503) : Http::response([
            'dist-tags' => ['latest' => '0.3.3'],
            'versions' => [
                '0.3.0' => ['engines' => ['node' => '>=20.19.0']],
                '0.3.3' => ['engines' => ['node' => '^20.19.0 || ^22.18.0 || >=24.11.0']],
            ],
        ]),
    ]);
    $node = Node::query()->create(['name' => 'vp-store', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '192.0.2.35', 'wireguard_ip' => '10.44.0.35', 'user' => 'orbit']);
    $node->roles()->create(['role' => $development ? 'app-dev' : 'app-prod', 'status' => 'active']);
    $project = Project::query()->create(['name' => 'Vp store', 'slug' => 'vp-store', 'repository_url' => 'https://example.test/vp-store.git', 'default_branch' => 'main', 'root' => 'public']);
    $instance = Instance::query()->create([
        'project_id' => $project->id, 'node_id' => $node->id, 'name' => $development ? 'default' : 'production',
        'source_layout' => $development ? 'checkout' : 'release', 'checkout_path' => $development ? '/home/orbit/apps/vp-store/default' : '/home/orbit-app-1/releases/initial',
        'production_user' => 'orbit-app-1', 'production_home' => '/home/orbit-app-1',
        'root' => 'public', 'branch' => 'main', 'provisioning_step' => 'active', 'status' => 'active',
    ]);
    $ssh = new VpStoreSshExecutor;
    $keys = new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/tmp/orbit-vp-test-key';
        }

        public function publicKey(): string
        {
            return 'ssh-ed25519 test';
        }
    };
    $hosts = new class implements KnownHostsStore
    {
        public function path(): string
        {
            return '/tmp/orbit-vp-test-hosts';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    };
    $vp = new VpToolManager(new RemoteToolCommandRunner($ssh, $keys, $hosts), new SemverVersionNormalizer);
    $catalog = new RemoteProgramCatalog;
    $prepare = new PrepareVpEnvironmentAction($vp, new SshProgramRunner($ssh, $keys, $hosts, $catalog), app(ToolManagerScopeLock::class), $catalog);
    $accounts = new class implements ManagedUserAccountResolver
    {
        public function resolve(Node $node): ManagedUserAccount
        {
            return new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
        }
    };
    $deployment = $development
        ? new RemoteDevelopmentDeployment(new DevelopmentSshExecutor($ssh, $keys, $hosts), $accounts, app(CheckoutRemovalBoundary::class), app(RepositoryReadAccess::class), $prepare)
        : new RemoteProductionDeployment(new ProductionSshExecutor($ssh, $keys, $hosts), app(RepositoryReadAccess::class), environments: $prepare);

    return [$prepare, $ssh, $instance, $deployment, $vp];
}

function vp_store_build(RemoteProductionDeployment|RemoteDevelopmentDeployment $deployment, Instance $instance, string $name = 'npm-build', string $command = '/old/node /old/npm-cli.js run build'): void
{
    if ($deployment instanceof RemoteDevelopmentDeployment) {
        $deployment->executeStep($instance, new DevelopmentDeployStep($name, $command, 60), new DeploymentRequest);

        return;
    }
    $deployment->executeStep($instance, new DeploymentRelease('candidate', '/home/orbit-app-1/releases/candidate', str_repeat('a', 40)), new DeploymentStep($name, DeploymentPhase::BeforeActivation, $command, 60), new DeploymentRequest);
}

describe('vp store deployment prerequisite', function (): void {
    it('installs and records the runtime and package manager as the store owner before building through vp', function (bool $development): void {
        [, $ssh, $instance, $deployment] = vp_store_fixture(development: $development);
        $ssh->onInstall = function () use ($instance): void {
            expect($instance->fresh()->getAttribute('vp_environment'))->toMatchArray([
                'status' => 'installing', 'node_version' => '24.22.0', 'package_manager' => 'npm', 'package_manager_version' => '11.12.0',
            ]);
        };

        vp_store_build($deployment, $instance);

        expect($ssh->operations)->toContain('vp-upgrade', 'install', 'build');
        expect(array_search('install', $ssh->operations))->toBeLessThan(array_search('build', $ssh->operations));
        expect($instance->fresh()->getAttribute('vp_environment'))->toMatchArray([
            'status' => 'active', 'store' => '/opt/orbit/vite-plus', 'vp_version' => '0.3.3',
            'node_version' => '24.22.0', 'package_manager' => 'npm', 'package_manager_version' => '11.12.0',
        ]);
        $install = array_values(array_filter($ssh->commands, fn ($command): bool => in_array('install', $command->arguments, true)))[0];
        expect($install->arguments)->toBe(['env', 'VP_HOME=/opt/orbit/vite-plus', '/opt/orbit/vite-plus/bin/vp', 'env', 'install', '24.22.0', 'npm@11.12.0']);
        foreach ($ssh->connections as $connection) {
            expect($connection->user)->toBe('orbit');
        }
        if ($development) {
            expect($ssh->commands[0]->arguments)->not->toContain('sudo');
        } else {
            expect($ssh->commands[0]->arguments)->toContain('sudo', 'orbit-app-1');
        }
        $build = $ssh->commands[array_key_last($ssh->commands)];
        $protected = stream_get_contents($build->protectedInput->stream());
        preg_match("/printf '%s' '([^']+)' \\| base64 --decode/", $protected, $match);
        $command = base64_decode($match[1], true);
        expect($command)->toContain("'/opt/orbit/vite-plus/bin/vp' 'env' 'exec' '--node' '24.22.0' '--package-manager' 'npm@11.12.0'", 'npm run build')->not->toContain('/old/node', '/old/npm-cli.js');
        expect(Tool::query()->count())->toBe(0);
        Http::assertSentCount(1);
    })->with([false, true]);

    it('adopts the matching manual Node and npm install instead of reinstalling it and is idempotent', function (): void {
        [$prepare, $ssh, $instance] = vp_store_fixture();
        $ssh->installed = true;
        $ssh->vpVersion = '0.3.3';

        $first = $prepare->command($instance, $instance->checkout_path, 'npm-build', 'node /old/npm-cli.js run build');
        $second = $prepare->command($instance, $instance->checkout_path, 'npm-build', 'node /old/npm-cli.js run build');

        expect($second)->toBe($first)
            ->and($ssh->operations)->not->toContain('install', 'vp-upgrade')
            ->and($instance->fresh()->getAttribute('vp_environment'))->toMatchArray(['status' => 'active', 'node_version' => '24.22.0', 'package_manager_version' => '11.12.0']);
    });

    it('fails clearly before the build when a missing runtime cannot be installed or verified', function (bool $incomplete): void {
        [, $ssh, $instance, $deployment] = vp_store_fixture();
        $ssh->installFails = ! $incomplete;
        $ssh->installIncomplete = $incomplete;

        try {
            vp_store_build($deployment, $instance);
            test()->fail('A missing runtime must stop the build.');
        } catch (ResourceOperationException $exception) {
            expect($exception->errorCode)->toBe('deployment.vp_store_failed');
        }
        expect($ssh->operations)->not->toContain('build')
            ->and($instance->fresh()->getAttribute('vp_environment'))->toMatchArray(['status' => 'failed', 'node_version' => '24.22.0']);
    })->with([false, true]);

    it('updates vp through the Tool Manager path and records the observed version', function (): void {
        [$prepare, $ssh, $instance] = vp_store_fixture();
        $ssh->installed = true;

        $prepare->command($instance, $instance->checkout_path, 'npm-build', 'npm run build');

        $record = ToolManagerRecord::query()->where('node_id', $instance->node_id)->where('name', 'vp')->sole();
        expect($record->installed_version)->toBe('vp v0.3.3')->and($record->status->value)->toBe('active');
        $updates = array_values(array_filter($ssh->commands, fn ($command): bool => in_array('upgrade', $command->arguments, true)));
        expect($updates)->toHaveCount(1)->and($updates[0]->arguments)->toBe(['env', 'VP_HOME=/opt/orbit/vite-plus', '/opt/orbit/vite-plus/bin/vp', 'upgrade', '0.3.3']);
    });

    it('does not downgrade a newer shared vp and stops before building if an upgrade fails', function (bool $fail): void {
        [, $ssh, $instance, $deployment] = vp_store_fixture();
        $ssh->installed = true;
        $ssh->vpVersion = $fail ? '0.3.0' : '0.4.0';
        $ssh->updateFails = $fail;
        if ($fail) {
            expect(fn () => vp_store_build($deployment, $instance))->toThrow(ResourceOperationException::class);
            expect($ssh->operations)->not->toContain('build');
            expect(ToolManagerRecord::query()->sole()->error_code)->toBe('tool.manager_update_failed');
        } else {
            vp_store_build($deployment, $instance);
            expect($ssh->operations)->not->toContain('vp-upgrade')->toContain('build');
        }
    })->with([false, true]);

    it('resolves a vite-plus range and package-manager engines without passing Project scripts to the store owner', function (): void {
        [$prepare, $ssh, $instance] = vp_store_fixture();
        $ssh->configuration['package']['devDependencies']['vite-plus'] = '^0.3.0';
        unset($ssh->configuration['package']['packageManager']);
        $ssh->configuration['package']['engines']['npm'] = '^11.0.0';

        $prepare->command($instance, $instance->checkout_path, 'build', 'npm ci && npm run build');

        $resolve = array_values(array_filter($ssh->commands, fn ($command): bool => str_contains(implode(' ', $command->arguments), 'TemporaryDirectory')))[0];
        $configuration = json_decode($resolve->input, true, flags: JSON_THROW_ON_ERROR);
        expect($configuration['package']['packageManager'])->toBe('npm@^11.0.0');
        $constraint = NpmVersionRange::parse($configuration['package']['engines']['node']);
        expect($constraint->satisfies('24.22.0'))->toBeTrue()->and($constraint->satisfies('18.20.0'))->toBeFalse();
        expect($resolve->input)->not->toContain('preinstall', 'must-not-run-as-owner');
        expect($instance->fresh()->getAttribute('vp_environment'))->toMatchArray(['vp_version' => '0.3.3']);
    });

    it('preserves vp Node setting precedence without injecting an engine above a file pin', function (?string $nodeFile, ?string $nvmrc, ?string $engine, string $selected): void {
        [$prepare, $ssh, $instance] = vp_store_fixture();
        unset($ssh->configuration['package']['engines']['node']);
        if ($engine !== null) {
            $ssh->configuration['package']['engines']['node'] = $engine;
        }
        $ssh->configuration['.node-version'] = $nodeFile;
        $ssh->configuration['.nvmrc'] = $nvmrc;
        $ssh->nodeVersion = $selected;

        $prepare->command($instance, $instance->checkout_path, 'npm-build', 'npm run build');

        $resolve = array_values(array_filter($ssh->commands, fn ($command): bool => str_contains(implode(' ', $command->arguments), 'TemporaryDirectory')))[0];
        $configuration = json_decode($resolve->input, true, flags: JSON_THROW_ON_ERROR);
        expect($configuration['.node-version'])->toBe($nodeFile);
        expect($configuration['.nvmrc'])->toBe($nvmrc);
        if ($engine === null) {
            expect($configuration['package'])->not->toHaveKey('engines');
        } else {
            expect(NpmVersionRange::parse($configuration['package']['engines']['node'])->satisfies($selected))->toBeTrue();
        }
        expect($instance->fresh()->getAttribute('vp_environment'))->toMatchArray(['status' => 'active', 'node_version' => $selected]);
    })->with([
        'only nvmrc' => [null, "22.18.0\n", null, '22.18.0'],
        'only node-version' => ["22.18.0\n", null, null, '22.18.0'],
        'node-version before nvmrc' => ['22.18.0', '24.22.0', null, '22.18.0'],
        'engines before nvmrc' => [null, '20.18.0', '^24.11.0', '24.22.0'],
        'node-version before engines and nvmrc' => ['22.18.0', '20.18.0', '>=22', '22.18.0'],
    ]);

    it('materializes the nvmrc-only configuration without a shadowing engine in the isolated resolver', function (): void {
        [$prepare, $ssh, $instance] = vp_store_fixture();
        unset($ssh->configuration['package']['engines']['node']);
        $ssh->configuration['.node-version'] = null;
        $ssh->configuration['.nvmrc'] = "22.18.0\n";
        $ssh->nodeVersion = '22.18.0';
        $prepare->command($instance, $instance->checkout_path, 'npm-build', 'npm run build');
        $resolve = array_values(array_filter($ssh->commands, fn ($command): bool => str_contains(implode(' ', $command->arguments), 'TemporaryDirectory')))[0];
        $files = new Filesystem;
        $directory = sys_get_temp_dir().'/orbit-vp-resolution-'.Str::uuid();
        $files->makeDirectory($directory);

        try {
            // Exercise the real resolver program. Only the external vp executable is faked.
            $files->put($directory.'/vp', <<<'PY'
                #!/usr/bin/env python3
                import json, os, pathlib, sys
                root = pathlib.Path.cwd()
                package = json.loads((root / 'package.json').read_text())
                assert sys.argv[1:] == ['env', 'current', '--json']
                assert 'node' not in package.get('engines', {})
                assert 'scripts' not in package
                assert not (root / '.node-version').exists()
                assert pathlib.Path(os.environ['VP_HOME']) == root / '.vp'
                assert 'VP_NODE_VERSION' not in os.environ
                pin = (root / '.nvmrc').read_text().strip()
                print(json.dumps({'node': {'version': pin, 'source': '.nvmrc'}, 'package_manager': {'name': 'npm', 'version': '11.12.0'}}))
                PY);
            chmod($directory.'/vp', 0755);
            $process = new Process(['python3', '-c', $resolve->arguments[2], $directory.'/vp'], env: ['VP_NODE_VERSION' => '99.0.0']);
            $process->setInput($resolve->input)->mustRun();

            expect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['node'])->toBe(['version' => '22.18.0', 'source' => '.nvmrc']);
        } finally {
            $files->deleteDirectory($directory);
        }
    });

    it('uses the vite-plus engine only as a default when the Project has no Node setting', function (): void {
        [$prepare, $ssh, $instance] = vp_store_fixture();
        unset($ssh->configuration['package']['engines']['node']);
        $ssh->configuration['.node-version'] = null;
        $ssh->configuration['.nvmrc'] = null;

        $prepare->command($instance, $instance->checkout_path, 'npm-build', 'npm run build');

        $resolve = array_values(array_filter($ssh->commands, fn ($command): bool => str_contains(implode(' ', $command->arguments), 'TemporaryDirectory')))[0];
        $configuration = json_decode($resolve->input, true, flags: JSON_THROW_ON_ERROR);
        expect($configuration['package']['engines']['node'])->toBe('^20.19.0 || ^22.18.0 || >=24.11.0');
    });

    it('rejects a selected nvmrc pin incompatible with vite-plus rather than replacing it', function (): void {
        [$prepare, $ssh, $instance] = vp_store_fixture();
        unset($ssh->configuration['package']['engines']['node']);
        $ssh->configuration['.node-version'] = null;
        $ssh->configuration['.nvmrc'] = '20.18.0';
        $ssh->nodeVersion = '20.18.0';

        try {
            $prepare->command($instance, $instance->checkout_path, 'npm-build', 'npm run build');
            test()->fail('The incompatible Project pin must fail.');
        } catch (ResourceOperationException $exception) {
            expect($exception->errorCode)->toBe('deployment.vp_requirements_invalid');
        }
        expect($ssh->operations)->not->toContain('install', 'build');
        expect($instance->fresh()->getAttribute('vp_environment'))->toMatchArray(['status' => 'failed']);
    });

    it('uses pnpm by default or the Project lockfile signal when no packageManager is declared', function (?string $hint, string $manager, string $version): void {
        [$prepare, $ssh, $instance] = vp_store_fixture();
        unset($ssh->configuration['package']['packageManager']);
        $ssh->configuration['package_manager_hint'] = $hint;
        $ssh->packageManager = $manager;
        $ssh->packageManagerVersion = $version;

        $command = $prepare->command($instance, $instance->checkout_path, 'npm-build', 'npm run build');

        expect($instance->fresh()->getAttribute('vp_environment'))->toMatchArray(['package_manager' => $manager, 'package_manager_version' => $version]);
        expect($command)->toContain($manager.' run build');
    })->with([[null, 'pnpm', '10.17.0'], ['npm', 'npm', '11.12.0']]);

    it('rejects unsafe Project selectors before installing or building', function (string $field, mixed $value): void {
        [, $ssh, $instance, $deployment] = vp_store_fixture();
        if ($field === 'vite-plus') {
            $ssh->configuration['package']['devDependencies']['vite-plus'] = $value;
        } elseif ($field === '.node-version') {
            $ssh->configuration[$field] = $value;
        } else {
            $ssh->configuration['package'][$field] = $value;
        }
        try {
            vp_store_build($deployment, $instance);
            test()->fail('Unsafe Project data must fail.');
        } catch (ResourceOperationException $exception) {
            expect($exception->errorCode)->toBe('deployment.vp_requirements_invalid');
        }
        expect($ssh->operations)->not->toContain('install', 'build', 'resolve');
    })->with([
        ['vite-plus', 'file:../../foreign'],
        ['packageManager', 'npm@11.12.0; touch /tmp/foreign'],
        ['packageManager', 'https://example.test/manager.tgz'],
        ['.node-version', "24.22.0\nmalicious-command"],
        ['engines', ['node' => ['unexpected' => 'object']]],
    ]);

    it('stops before the build when registry requirements cannot be read', function (): void {
        [, $ssh, $instance, $deployment] = vp_store_fixture(registryUnavailable: true);
        try {
            vp_store_build($deployment, $instance);
            test()->fail('Unavailable metadata must fail.');
        } catch (ResourceOperationException $exception) {
            expect($exception->errorCode)->toBe('deployment.vp_store_failed');
        }
        expect($ssh->operations)->toBe(['project']);
        expect($instance->fresh()->getAttribute('vp_environment'))->toMatchArray(['status' => 'failed', 'error_code' => 'deployment.vp_store_failed']);
    });

    it('preserves package-manager integrity pins for owner installation and application execution', function (): void {
        [$prepare, $ssh, $instance] = vp_store_fixture();
        $ssh->configuration['package']['packageManager'] = 'npm@11.12.0+sha512.abc123';
        $ssh->installed = true;

        $command = $prepare->command($instance, $instance->checkout_path, 'npm-build', 'npm run build');

        $install = array_values(array_filter($ssh->commands, fn ($command): bool => in_array('install', $command->arguments, true)))[0];
        expect($install->arguments)->toContain('npm@11.12.0+sha512.abc123');
        expect($instance->fresh()->getAttribute('vp_environment'))->toMatchArray(['package_manager_hash' => 'sha512.abc123']);
        expect($command)->toContain('npm@11.12.0+sha512.abc123');
    });

    it('rejects incompatible runtimes before installing or building', function (string $version): void {
        [, $ssh, $instance, $deployment] = vp_store_fixture();
        $ssh->nodeVersion = $version;
        try {
            vp_store_build($deployment, $instance);
            test()->fail('An incompatible runtime must fail.');
        } catch (ResourceOperationException $exception) {
            expect($exception->errorCode)->toBe('deployment.vp_requirements_invalid');
        }
        expect($ssh->operations)->not->toContain('install', 'build');
    })->with(['18.20.0', '26.0.0']);

    it('prepares other JavaScript entry points without replacing their commands', function (string $command): void {
        [$prepare, $ssh, $instance] = vp_store_fixture();
        $ssh->installed = true;

        $prepared = $prepare->command($instance, $instance->checkout_path, 'frontend', $command);

        expect($prepared)->toContain("'env' 'exec'", escapeshellarg($command));
        expect($instance->fresh()->getAttribute('vp_environment'))->toMatchArray(['status' => 'active']);
    })->with(['node build.js', 'npx vite build', './node_modules/.bin/vite build', 'corepack pnpm run build', 'bun run build']);

    it('leaves non-vite Projects and non-JavaScript steps unchanged', function (): void {
        [$prepare, $ssh, $instance] = vp_store_fixture();
        expect($prepare->command($instance, $instance->checkout_path, 'migrate', 'php artisan migrate'))->toBe('php artisan migrate');
        expect($ssh->commands)->toBeEmpty();
        $ssh->configuration = ['package' => ['scripts' => ['build' => 'existing-build']]];
        expect($prepare->command($instance, $instance->checkout_path, 'npm-build', 'npm run build'))->toBe('npm run build');
        expect($ssh->operations)->toBe(['project']);
    });

    it('checks actual store executables without invoking a download-capable command', function (string $state, bool $expected): void {
        [$prepare, $ssh, $instance] = vp_store_fixture();
        $prepare->command($instance, $instance->checkout_path, 'npm-build', 'npm run build');
        $inspection = array_values(array_filter($ssh->commands, fn ($command): bool => str_contains(implode(' ', $command->arguments), 'inventory = json.loads')))[0];
        $directory = sys_get_temp_dir().'/orbit-vp-store-'.Str::uuid();
        $files = new Filesystem;
        try {
            $files->ensureDirectoryExists($directory.'/bin');
            $node = $directory.'/js_runtime/node/24.22.0/bin/node';
            $npm = $directory.'/package_manager/npm/11.12.0/npm/bin/npm';
            $files->ensureDirectoryExists(dirname($node));
            $files->ensureDirectoryExists(dirname($npm));
            $inventory = json_encode(['node' => [['version' => '24.22.0']], 'package_managers' => ['npm' => [['version' => '11.12.0']]]], JSON_THROW_ON_ERROR);
            $files->put($directory.'/bin/vp', "#!/bin/sh\n[ \"\$*\" = 'env list --json' ] || exit 91\nprintf '%s' ".escapeshellarg($inventory)."\n");
            $files->put($node, "#!/bin/sh\nprintf '%s' '".($state === 'wrong_node' ? 'v22.0.0' : 'v24.22.0')."'\n");
            $files->put($npm, "#!/bin/sh\nprintf '%s' '".($state === 'wrong_pm' ? '10.0.0' : '11.12.0')."'\n");
            chmod($directory.'/bin/vp', 0755);
            chmod($node, 0755);
            chmod($npm, 0755);
            if ($state === 'missing_node') {
                unlink($node);
            }
            if ($state === 'missing_pm') {
                unlink($npm);
            }
            $process = new Process(['python3', '-c', $inspection->arguments[2], $directory.'/bin/vp', $directory, '24.22.0', 'npm', '11.12.0']);
            $process->mustRun();
            expect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe($expected);
        } finally {
            $files->deleteDirectory($directory);
        }
    })->with([
        'matching install' => ['present', true],
        'missing node binary' => ['missing_node', false],
        'wrong node executable' => ['wrong_node', false],
        'missing package manager binary' => ['missing_pm', false],
        'wrong package manager executable' => ['wrong_pm', false],
    ]);

    it('reports missing or unreadable recorded content with Doctor without installing it', function (string $state, ?string $expected): void {
        [$prepare, $ssh, $instance, , $vp] = vp_store_fixture();
        $prepare->command($instance, $instance->checkout_path, 'npm-build', 'npm run build');
        $ssh->operations = [];
        $ssh->installed = $state !== 'missing';
        $ssh->inspectionFails = $state === 'unreadable';
        $inspector = Mockery::mock(ToolInspector::class);
        $probe = new ToolDoctorProbe($inspector, new VersionConstraint, new UnsupportedPackageInventory, $vp);

        $report = $probe->inspect(new DoctorNodeContext($instance->node, new NodeInspectionData(true, 'linux', 'amd64', true)));

        expect($report->checked)->toBe(1);
        if ($expected === null) {
            expect($report->issues)->toBeEmpty();
        } else {
            expect($report->issues[0]->code)->toBe($expected);
            expect($report->issues[0]->resourceId)->toBeNull();
            expect($report->issues[0]->resourceName)->toBe('Instance '.$instance->id.' vp environment');
        }
        expect($ssh->operations)->not->toContain('install', 'vp-upgrade', 'resolve');
    })->with([
        'adopted content is healthy' => ['present', null],
        'missing content is drift' => ['missing', 'tool.vp_store_missing'],
        'failed reads are unverifiable' => ['unreadable', 'tool.vp_store_inspection_failed'],
    ]);
});
