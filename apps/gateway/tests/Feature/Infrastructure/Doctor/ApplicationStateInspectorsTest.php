<?php

declare(strict_types=1);

use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\InstanceInspectionData;
use App\Domain\Doctor\ProjectInspectionData;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\CheckoutRemovalBoundary;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Nodes\Storage\ProtectedPathCatalog;
use App\Domain\Nodes\Storage\StorageRootResolver;
use App\Domain\Projects\ProjectType;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Doctor\NativeInstanceStateInspector;
use App\Infrastructure\Doctor\NativeProjectStateInspector;
use App\Infrastructure\Doctor\ProductionInstanceInspectionExpectationFactory;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AppDevFakeSshExecutor;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\FixtureProcessRunner;
use Tests\Support\UnixSocketDirectory;

it('batches only settled selected-node Project checkouts through the agent', function (): void {
    $project = application_inspector_app();
    $node = application_inspector_node();
    $node->update(['settings' => ['apps' => ['path' => '/fast/apps']]]);
    $instance = application_app_instance($project, $node);
    $instance->update(['checkout_path' => "/fast/apps/{$project->slug}/development"]);
    application_app_instance($project, application_inspector_node(), 'other-node');
    $removing = application_app_instance($project, $node, 'removing');
    application_mark_removing($removing);
    application_app_instance($project, $node, 'provisioning')->update([
        'status' => InstanceState::CheckoutPrepared, 'provisioning_step' => 'checkout',
    ]);
    $agents = new FakeAgentCommandRunner()->answer('doctor.inspect-project', [
        'checkouts' => [['instance_id' => (int) $instance->id, 'matches' => true]],
    ]);

    expect(application_app_inspector($agents)->inspect($project, $node))->toEqual(new ProjectInspectionData(1, true));
    expect($agents->calls)->toBe([[
        'node' => $node->name, 'command' => 'doctor.inspect-project', 'input' => [
            'repository' => $project->repository_url,
            'checkouts' => [[
                'instance_id' => (int) $instance->id, 'path' => $instance->checkout_path,
                'root' => '/fast/apps', 'user' => 'nckrtl', 'production' => false,
            ]],
        ],
    ]]);
});

it('supplies recorded production home and owner without a shell', function (): void {
    $project = application_inspector_app();
    $node = application_inspector_node();
    $instance = application_production_app_instance($project, $node, 'base64:'.str_repeat('A', 44));
    $agents = new FakeAgentCommandRunner()->answer('doctor.inspect-project', [
        'checkouts' => [['instance_id' => (int) $instance->id, 'matches' => true]],
    ]);

    expect(application_app_inspector($agents)->inspect($project, $node))->toEqual(new ProjectInspectionData(1, true));
    expect($agents->calls[0]['input']['checkouts'])->toBe([[
        'instance_id' => (int) $instance->id, 'path' => $instance->checkout_path,
        'root' => $instance->production_home, 'user' => $instance->production_user, 'production' => true,
    ]]);
});

it('preserves mismatching and unavailable checkout identities independently', function (): void {
    $project = application_inspector_app();
    $node = application_inspector_node();
    $failed = application_app_instance($project, $node, 'failed');
    $mismatch = application_app_instance($project, $node, 'mismatch');
    $healthy = application_app_instance($project, $node, 'healthy');
    $agents = new FakeAgentCommandRunner()->answer('doctor.inspect-project', ['checkouts' => [
        ['instance_id' => (int) $failed->id, 'matches' => null],
        ['instance_id' => (int) $mismatch->id, 'matches' => false],
        ['instance_id' => (int) $healthy->id, 'matches' => true],
    ]]);

    expect(application_app_inspector($agents)->inspect($project, $node))
        ->toEqual(new ProjectInspectionData(3, false, [(int) $mismatch->id], [(int) $failed->id]));
    expect($agents->calls)->toHaveCount(1);
});

it('returns a healthy empty Project selection without a command', function (): void {
    $agents = new FakeAgentCommandRunner;
    expect(application_app_inspector($agents)->inspect(application_inspector_app(), application_inspector_node()))
        ->toEqual(new ProjectInspectionData(0, true));
    expect($agents->calls)->toBe([]);
});

it('keeps a failed or malformed Project observation unavailable', function (string $failure): void {
    $project = application_inspector_app();
    $node = application_inspector_node();
    $instance = application_app_instance($project, $node);
    $agents = new FakeAgentCommandRunner;
    match ($failure) {
        'refusal' => $agents->refuse('doctor.inspect-project', 'doctor.project_input_invalid'),
        'transport' => $agents->answer('doctor.inspect-project', static fn (): array => throw new RuntimeException('lost transport')),
        'missing' => $agents->answer('doctor.inspect-project', []),
        'wrong identity' => $agents->answer('doctor.inspect-project', ['checkouts' => [['instance_id' => 0, 'matches' => true]]]),
        'wrong value' => $agents->answer('doctor.inspect-project', ['checkouts' => [['instance_id' => (int) $instance->id, 'matches' => 'true']]]),
    };

    expect(application_app_inspector($agents)->inspect($project, $node))
        ->toEqual(new ProjectInspectionData(1, true, [], [(int) $instance->id]));
})->with(['refusal', 'transport', 'missing', 'wrong identity', 'wrong value']);

it('refuses invalid Project intent before an agent call', function (): void {
    $project = application_inspector_app();
    $project->repository_url = 'not a repository';
    $agents = new FakeAgentCommandRunner;
    expect(fn () => application_app_inspector($agents)->inspect($project, application_inspector_node()))
        ->toThrow(DoctorInspectionException::class);
    expect($agents->calls)->toBe([]);
});

it('sends recorded Instance source intent through the agent after a Project rename', function (): void {
    $node = application_inspector_node();
    $project = application_inspector_app();
    $instance = application_app_instance($project, $node);
    $checkout = $instance->checkout_path;
    $project->update(['slug' => 'renamed-project']);
    $agents = new FakeAgentCommandRunner()->answer('doctor.inspect-development-source', [
        'checkout' => true, 'layout' => true, 'origin' => true, 'history' => true,
    ]);

    expect(application_instance_inspector($agents)->inspect($instance->refresh()))
        ->toEqual(new InstanceInspectionData(true, true, true, true));
    expect($agents->calls)->toBe([[
        'node' => $node->name, 'command' => 'doctor.inspect-development-source', 'input' => [
            'repository' => $project->repository_url, 'checkout' => $checkout,
            'root' => '/srv/users/nckrtl/apps', 'user' => 'nckrtl', 'group' => 'nckrtl',
            'layout' => $instance->source_layout, 'starting_commit' => $instance->starting_commit,
        ],
    ]]);
});

it('fails Instance inspection closed for a checkout path that does not end in the Instance name', function (string $path): void {
    $instance = application_app_instance(application_inspector_app(), application_inspector_node());
    $instance->update(['checkout_path' => $path]);
    $agents = new FakeAgentCommandRunner;

    expect(fn () => application_instance_inspector($agents)->inspect($instance->refresh()))
        ->toThrow(DoctorInspectionException::class);
    expect($agents->calls)->toBe([]);
})->with([
    'another name' => '/srv/users/nckrtl/apps/project/main',
    'too shallow' => '/development',
    'protected root' => '/srv/users/nckrtl/project/development',
]);

it('maps each development source mismatch independently', function (string $field, string $property): void {
    $instance = application_app_instance(application_inspector_app(), application_inspector_node());
    $values = ['checkout' => true, 'layout' => true, 'origin' => true, 'history' => true];
    $values[$field] = false;
    $agents = new FakeAgentCommandRunner()->answer('doctor.inspect-development-source', $values);

    expect(application_instance_inspector($agents)->inspect($instance)->{$property})->toBeFalse();
})->with([
    ['checkout', 'checkoutExists'], ['layout', 'repositoryLayoutMatches'],
    ['origin', 'originMatches'], ['history', 'sourceIdentityMatches'],
]);

it('sanitizes failed and malformed development source observations', function (string $failure): void {
    $instance = application_app_instance(application_inspector_app(), application_inspector_node());
    $agents = new FakeAgentCommandRunner;
    match ($failure) {
        'refusal' => $agents->refuse('doctor.inspect-development-source', 'doctor.source_inspection_failed'),
        'transport' => $agents->answer('doctor.inspect-development-source', static fn (): array => throw new RuntimeException('remote-secret-token')),
        'missing' => $agents->answer('doctor.inspect-development-source', []),
        'unavailable' => $agents->answer('doctor.inspect-development-source', ['checkout' => null, 'layout' => true, 'origin' => true, 'history' => true]),
        'malformed' => $agents->answer('doctor.inspect-development-source', ['checkout' => 'private-output', 'layout' => true, 'origin' => true, 'history' => true]),
    };

    $exception = application_capture_exception(fn () => application_instance_inspector($agents)->inspect($instance));
    application_assert_sanitized($exception, 'remote-secret-token');
})->with(['refusal', 'transport', 'missing', 'unavailable', 'malformed']);

it('keeps migrated source inspectors behind the agent boundary', function (): void {
    expect(file_get_contents(app_path('Infrastructure/Doctor/NativeProjectStateInspector.php')))
        ->not->toContain('SshExecutor', 'RemoteCommand', 'ProcessRunner', '<<<');
    $instance = file_get_contents(app_path('Infrastructure/Doctor/NativeInstanceStateInspector.php'));
    $development = substr($instance, 0, strpos($instance, 'private function inspectProduction'));
    expect($development)->toContain('doctor.inspect-development-source')->not->toContain('new RemoteCommand', '$this->ssh->execute');
});

it('checks the selected release environment in its application directory', function (string $webRoot, string $suffix, string $target): void {
    $home = sys_get_temp_dir().'/orbit-doctor-release-env-'.bin2hex(random_bytes(8));
    $release = $home.'/releases/initial';
    mkdir($release.$suffix, 0700, true);
    file_put_contents($home.'/.env', "APP_KEY=secret\n");
    chmod($home.'/.env', 0600);
    symlink('releases/initial', $home.'/current');
    symlink($target, $release.$suffix.'/.env');
    $setup = 'home='.escapeshellarg($home)."\n"
        .'user='.escapeshellarg(posix_getpwuid(posix_geteuid())['name'])."\n"
        .'environment='.escapeshellarg(base64_encode("APP_KEY=secret\n"));

    try {
        $program = application_production_observation_program('environment_matches', $setup, $webRoot);
        expect(application_run(['bash', '-s'], $program)->stdout)->toBe("1\n");
        unlink($release.$suffix.'/.env');
        file_put_contents($release.$suffix.'/.env', "APP_KEY=secret\n");
        expect(application_run(['bash', '-s'], $program)->stdout)->toBe("0\n");
        if ($suffix !== '') {
            symlink('../../.env', $release.'/.env');
            expect(application_run(['bash', '-s'], $program)->stdout)->toBe("0\n");
        }
    } finally {
        new Filesystem()->deleteDirectory($home);
    }
})->with([
    'root public' => ['public', '', '../../.env'],
    'nested Laravel' => ['server/web/public', '/server/web', '../../../../.env'],
]);

it('accepts the initial pool only before selection and rejects the old production-home working directory', function (string $root, string $suffix): void {
    $home = sys_get_temp_dir().'/orbit-doctor-fpm-app-'.bin2hex(random_bytes(8));
    $files = new Filesystem;
    $files->ensureDirectoryExists($home.'/runtime/generated');
    $files->ensureDirectoryExists($home.'/releases/initial'.$suffix);
    $files->ensureDirectoryExists($home.'/releases/fresh'.$suffix);
    $setup = 'runtime_directory='.escapeshellarg($home.'/runtime')."\n"
        .'generated_directory='.escapeshellarg($home.'/runtime/generated')."\n"
        .'application='.escapeshellarg($home.'/current'.$suffix)."\n"
        .'initial_application='.escapeshellarg($home.'/releases/initial'.$suffix)."\n"
        .'observed_pool="$initial_pool_configuration"'."\n"
        .'home='.escapeshellarg($home)."\n".<<<'BASH'
            exact_file() {
                case "$1" in
                    */pool.conf)
                        expected_chdir=$(printf '%s' "$2" | base64 --decode | grep '^chdir = ')
                        observed_chdir=$(printf '%s' "$observed_pool" | base64 --decode | grep '^chdir = ')
                        test "$expected_chdir" = "$observed_chdir"
                        ;;
                    *) return 0 ;;
                esac
            }
            stat() { printf 'root:root:755\n'; }
            local_tuning_matches() { return 0; }
            loaded_service_matches() { return 0; }
            process_runtime_matches() { return 0; }
            worker_identity_matches() { return 0; }
            socket_service_matches() { return 0; }
            runtime_generation_applied() { return 0; }
            systemctl() {
                case "$2" in
                    --property=ActiveState) printf 'active\n' ;;
                    --property=User) printf '\n' ;;
                    --property=MainPID) printf '620\n' ;;
                    *) return 1 ;;
                esac
            }
            BASH;

    try {
        $initial = application_production_observation_program('php_fpm_matches', $setup, $root);
        expect(application_run(['bash'], $initial)->stdout)->toBe("1\n");
        symlink('releases/fresh', $home.'/current');
        expect(application_run(['bash'], $initial)->stdout)->toBe("0\n");
        $selected = application_production_observation_program('php_fpm_matches', $setup."\n".'observed_pool="$pool_configuration"', $root);
        expect(application_run(['bash'], $selected)->stdout)->toBe("1\n");
        unlink($home.'/current');
        symlink('releases/initial', $home.'/current');
        expect(application_run(['bash'], $selected)->stdout)->toBe("1\n");
        $oldHome = application_production_observation_program('php_fpm_matches', $setup."\n".<<<'BASH'
            observed_pool=$(printf '%s' "$pool_configuration" | base64 --decode | sed 's,^chdir = .*current.*,chdir = /home/obsolete,' | base64 --wrap=0)
            BASH, $root);
        expect(application_run(['bash'], $oldHome)->stdout)->toBe("0\n");
    } finally {
        $files->deleteDirectory($home);
    }
})->with(['root public' => ['public', ''], 'nested Laravel' => ['server/web/public', '/server/web']]);

it('rejects pending or unacknowledged FPM generations but accepts a master restarted after confirmation', function (string $root, string $suffix, string $state, string $expected): void {
    $directory = sys_get_temp_dir().'/orbit-doctor-generation-'.bin2hex(random_bytes(8));
    $files = new Filesystem;
    $files->ensureDirectoryExists($directory.'/runtime/generated');
    $files->ensureDirectoryExists($directory.'/home/releases/fresh'.$suffix);
    $files->ensureDirectoryExists($directory.'/proc/sys/kernel/random');
    $files->ensureDirectoryExists($directory.'/proc/620');
    file_put_contents($directory.'/boot', file_get_contents('/proc/sys/kernel/random/boot_id'));
    file_put_contents($directory.'/proc/620/stat', file_get_contents('/proc/self/stat'));
    symlink('releases/fresh', $directory.'/home/current');
    $setup = 'fixture='.escapeshellarg($directory)."\n"
        .'application='.escapeshellarg($directory.'/home/current'.$suffix)."\n"
        .'initial_application='.escapeshellarg($directory.'/home/releases/initial'.$suffix)."\n".<<<'BASH'
            runtime_directory="$fixture/runtime"
            generated_directory="$runtime_directory/generated"
            proc_root="$fixture/proc"
            home="$fixture/home"
            cp "$fixture/boot" "$proc_root/sys/kernel/random/boot_id"
            chown() { :; }
            stat() { printf 'root:root:%s\n' "$(command stat -c %a -- "${!#}")"; }
            exact_file() { return 0; }
            local_tuning_matches() { return 0; }
            loaded_service_matches() { return 0; }
            process_runtime_matches() { return 0; }
            worker_identity_matches() { return 0; }
            socket_service_matches() { return 0; }
            systemctl() {
                case "$2" in
                    --property=ActiveState) printf 'active\n' ;;
                    --property=User) printf '\n' ;;
                    --property=MainPID|--property) printf '620\n' ;;
                    *) return 1 ;;
                esac
            }
            original_pool="$pool_configuration"
            select_application_pool
            confirm_runtime_generation
            case "${1:-}" in
                pending) begin_runtime_generation ;;
                missing) rm "$runtime_directory/.runtime-generation.applied" ;;
                rebooted) printf '00000000-0000-0000-0000-000000000000\n' > "$proc_root/sys/kernel/random/boot_id" ;;
                restarted) sed -i '4s/.*/0/' "$runtime_directory/.runtime-generation.applied" ;;
                older-master) sed -i '4s/.*/999999999999/' "$runtime_directory/.runtime-generation.applied" ;;
                malformed) sed -i '4s/.*/soon/' "$runtime_directory/.runtime-generation.applied" ;;
                other-generation) sed -i '1s/.*/0000/' "$runtime_directory/.runtime-generation.applied" ;;
                restarted-other-generation) sed -i -e '1s/.*/0000/' -e '4s/.*/0/' "$runtime_directory/.runtime-generation.applied" ;;
                malformed-boot) sed -i -e '2s/.*/not-a-boot-id/' -e '4s/.*/0/' "$runtime_directory/.runtime-generation.applied" ;;
                recovered) begin_runtime_generation; confirm_runtime_generation ;;
            esac
            pool_configuration="$original_pool"
            BASH;

    try {
        $program = application_production_observation_program('php_fpm_matches', $setup, $root);
        expect(application_run(['bash', '-s', '--', $state], $program)->stdout)->toBe($expected."\n", $state);
    } finally {
        $files->deleteDirectory($directory);
    }
})->with(['root public' => ['public', ''], 'nested Laravel' => ['server/web/public', '/server/web']])->with([
    'applied' => ['applied', '1'],
    'pending' => ['pending', '0'],
    'missing' => ['missing', '0'],
    'rebooted' => ['rebooted', '1'],
    'restarted' => ['restarted', '1'],
    'older-master' => ['older-master', '0'],
    'malformed' => ['malformed', '0'],
    'other-generation' => ['other-generation', '0'],
    'restarted-other-generation' => ['restarted-other-generation', '0'],
    'malformed-boot' => ['malformed-boot', '0'],
    'recovered' => ['recovered', '1'],
]);

it('expects the actual production renderer application directory for selected and initial releases', function (string $root, string $suffix): void {
    $instance = application_production_app_instance(application_inspector_app(), application_inspector_node(), 'private-production-value');
    $instance->update(['root' => $root]);

    $expectation = app(ProductionInstanceInspectionExpectationFactory::class)->make($instance);

    expect($expectation->runtimeConfiguration?->pool)
        ->toContain('chdir = '.$instance->production_home.'/current'.$suffix."\n")
        ->and($expectation->initialRuntimeConfiguration?->pool)
        ->toContain('chdir = '.$instance->production_home.'/releases/initial'.$suffix."\n");
})->with(['root public' => ['public', ''], 'nested Laravel' => ['server/web/public', '/server/web']]);

it('observes production projections through fixed arguments and protected input', function (): void {
    $secret = 'doctor-production-secret';
    $instance = application_production_app_instance(
        application_inspector_app(),
        application_inspector_node(),
        $secret,
    );
    $ssh = new AppDevFakeSshExecutor([app_inspector_result("1\n1\n1\n1\n1\n1\n")]);

    $inspection = application_instance_inspector($ssh)->inspect($instance);
    $command = $ssh->commands[0];
    $protected = $command->protectedInput;
    if (! $protected instanceof ProtectedInput) {
        throw new RuntimeException('Expected protected production inspection input.');
    }
    $program = stream_get_contents($protected->stream());
    if (! is_string($program)) {
        throw new RuntimeException('Expected readable production inspection input.');
    }
    application_run(['bash', '-n'], $program);
    $expectation = app(ProductionInstanceInspectionExpectationFactory::class)->make($instance);

    expect($inspection)
        ->toEqual(new InstanceInspectionData(
            true,
            true,
            true,
            true,
            productionHomeMatches: true,
            releaseSelectionMatches: true,
            selectedReleaseRootMatches: true,
            environmentProjectionMatches: true,
            phpFpmProjectionMatches: true,
            caddyProjectionMatches: true,
        ))
        ->and($command->arguments)
        ->toBe(['sudo', 'bash', '-s', '--'])
        ->and($command->input)
        ->toBeNull()
        ->and($command->protectedInput)
        ->toBeInstanceOf(ProtectedInput::class)
        ->and($command->maxOutputBytes)
        ->toBe(128)
        ->and(json_encode($command, JSON_THROW_ON_ERROR))
        ->not->toContain($secret, $instance->production_home)
        ->and($program)
        ->toContain(
            base64_encode("APP_KEY=\"{$secret}\"\n"),
            'emit release_selection_matches',
            'emit php_fpm_matches',
            'loaded_service_matches',
            'systemctl show --property=ExecStart --value',
            'systemctl show --property=Environment --value',
            'process_runtime_matches',
            'proc_root=/proc',
            '$proc_root/net/unix',
            '$proc_root/$main_pid/fd/',
            'worker_identity_matches',
            '/^Gid:/',
            '$proc_root/$worker_pid/root',
            'local_tuning_matches || return 1',
            'chdir|chroot|env\[home\]',
        )
        ->not->toContain(
            $secret,
            ' -t ',
            'cgi-fcgi',
            'curl ',
            'kill ',
            '/proc/$main_pid/cmdline',
            '/proc/$main_pid/environ',
            '/var/log/php-fpm.log',
            'systemctl restart',
            'systemctl reload',
            'rm -',
            'install ',
            'mv ',
        )
        ->and(json_encode($expectation, JSON_THROW_ON_ERROR))
        ->not->toContain($secret)
        ->and($expectation->__debugInfo())
        ->toBe(['environment' => '[PROTECTED]'])
        ->and($ssh->connections[0]->commandTimeout)
        ->toBe(30.0);
});

it('inspects non-PHP production Instances without requiring or probing a PHP-FPM service', function (): void {
    $instance = application_production_app_instance(
        application_inspector_app(),
        application_inspector_node(),
        'doctor-static-secret',
    );
    $instance->update([
        'selected_php_version' => null,
        'production_php_service' => null,
        'production_php_pool' => null,
        'production_php_socket' => null,
    ]);
    $ssh = new AppDevFakeSshExecutor([app_inspector_result(implode(PHP_EOL, ['1', '1', '1', '1', '1', '1', '']))]);

    $inspection = application_instance_inspector($ssh)->inspect($instance->refresh());
    $expectation = app(ProductionInstanceInspectionExpectationFactory::class)->make($instance->refresh());
    $input = $ssh->commands[0]->protectedInput;
    if (! $input instanceof ProtectedInput) {
        throw new RuntimeException('Expected protected production inspection input.');
    }
    $program = stream_get_contents($input->stream());
    if (! is_string($program)) {
        throw new RuntimeException('Expected readable production inspection input.');
    }

    expect($inspection->phpFpmProjectionMatches)->toBeTrue()
        ->and($inspection->caddyProjectionMatches)->toBeTrue()
        ->and($expectation->associationMatches)->toBeTrue()
        ->and($expectation->runtime)->toBeNull()
        ->and($expectation->runtimeConfiguration)->toBeNull()
        ->and($program)->toContain("runtime_expected='0'");
});

it('inspects a Laravel package with selected PHP but no PHP-FPM identity as having no runtime', function (): void {
    $project = application_inspector_app();
    $project->update(['type' => ProjectType::LaravelPackage]);
    $instance = application_production_app_instance(
        $project,
        application_inspector_node(),
        'doctor-package-secret',
    );
    $instance->update([
        'production_php_service' => null,
        'production_php_pool' => null,
        'production_php_socket' => null,
    ]);
    $ssh = new AppDevFakeSshExecutor([app_inspector_result(implode(PHP_EOL, ['1', '1', '1', '1', '1', '1', '']))]);

    $inspection = application_instance_inspector($ssh)->inspect($instance->refresh());
    $expectation = app(ProductionInstanceInspectionExpectationFactory::class)->make($instance->refresh());
    $input = $ssh->commands[0]->protectedInput;
    if (! $input instanceof ProtectedInput) {
        throw new RuntimeException('Expected readable production inspection input.');
    }
    $program = stream_get_contents($input->stream());
    if (! is_string($program)) {
        throw new RuntimeException('Expected readable production inspection input.');
    }

    expect($inspection->phpFpmProjectionMatches)->toBeTrue()
        ->and($expectation->associationMatches)->toBeTrue()
        ->and($expectation->runtime)->toBeNull()
        ->and($expectation->runtimeConfiguration)->toBeNull()
        ->and($program)->toContain("runtime_expected='0'");
});

it('refuses PHP production inspection when its dedicated service is missing', function (): void {
    $instance = application_production_app_instance(
        application_inspector_app(),
        application_inspector_node(),
        'doctor-php-secret',
    );
    $instance->update([
        'production_php_service' => null,
        'production_php_pool' => null,
        'production_php_socket' => null,
    ]);

    expect(fn () => app(ProductionInstanceInspectionExpectationFactory::class)->make($instance->refresh()))
        ->toThrow(InvalidArgumentException::class, 'The production PHP Instance requires a dedicated PHP-FPM service and PHP version.');
});

it('executes loaded service association outcomes from the production program', function (
    string $systemctl,
    string $expected,
): void {
    $program = application_production_observation_program('loaded_service_matches', $systemctl);
    $result = application_run(['bash'], $program);

    expect($result->stdout)->toBe("{$expected}\n");
})->with([
    'loaded command and environment match' => [
        <<<'BASH'
            systemctl() {
                case "$2" in
                    --property=ExecStart)
                        printf '{ path=%s ; argv[]=%s --nodaemonize --fpm-config %s/php-fpm.conf ; }\n' "$executable" "$executable" "$generated_directory"
                        ;;
                    --property=Environment) printf '%s\n' "$expected_environment" ;;
                    *) return 1 ;;
                esac
            }
            BASH,
        '1',
    ],
    'recognizable loaded command mismatches' => [
        <<<'BASH'
            systemctl() {
                case "$2" in
                    --property=ExecStart) printf '{ path=/usr/sbin/php-fpm8.4 ; argv[]=/usr/sbin/php-fpm8.4 ; }\n' ;;
                    --property=Environment) printf '%s\n' "$expected_environment" ;;
                    *) return 1 ;;
                esac
            }
            BASH,
        '0',
    ],
    'unreadable loaded command is unavailable' => [
        <<<'BASH'
            systemctl() { return 1; }
            BASH,
        '2',
    ],
]);

it('executes active service and main PID outcomes from the production program', function (
    string $condition,
    string $expected,
): void {
    $sandbox = sys_get_temp_dir().'/orbit-doctor-service-'.Str::uuid();
    $runtime = "{$sandbox}/runtime";
    $generated = "{$runtime}/generated";
    $counter = "{$sandbox}/main-pid-count";
    $files = new Filesystem;
    $files->makeDirectory($generated, 0o755, true);
    file_put_contents($counter, '0');
    $activeState = $condition === 'inactive' ? 'inactive' : 'active';
    $mainPid = $condition === 'malformed PID' ? 'private-invalid' : '620';
    $activeResult = $condition === 'unreadable state' ? 'return 1' : "printf '%s\\n' '{$activeState}'";
    $pidResult = $condition === 'changed PID'
        ? <<<'BASH'
            count=$(cat "$counter")
            count=$((count + 1))
            printf '%s' "$count" > "$counter"
            if test "$count" -eq 1; then printf '620\n'; else printf '621\n'; fi
            BASH
        : "printf '%s\\n' '{$mainPid}'";
    $setup = <<<BASH
        runtime_directory={$runtime}
        generated_directory={$generated}
        counter={$counter}
        exact_file() { return 0; }
        select_application_pool() { return 0; }
        local_tuning_matches() { return 0; }
        loaded_service_matches() { return 0; }
        process_runtime_matches() { return 0; }
        worker_identity_matches() { return 0; }
        socket_service_matches() { return 0; }
        runtime_generation_applied() { return 0; }
        stat() { printf 'root:root:755\n'; }
        systemctl() {
            case "\$2" in
                --property=ActiveState) {$activeResult} ;;
                --property=User) printf '\n' ;;
                --property=MainPID) {$pidResult} ;;
                *) return 1 ;;
            esac
        }
        BASH;

    try {
        $program = application_production_observation_program('php_fpm_matches', $setup);
        $result = application_run(['bash'], $program);

        expect($result->stdout)->toBe("{$expected}\n");
    } finally {
        $files->deleteDirectory($sandbox);
    }
})->with([
    'active service with stable main PID' => ['matches', '1'],
    'inactive service is drift' => ['inactive', '0'],
    'unreadable active state is unavailable' => ['unreadable state', '2'],
    'malformed main PID is unavailable' => ['malformed PID', '2'],
    'changed main PID is unavailable' => ['changed PID', '2'],
]);

it('executes stable master process outcomes from the production program', function (
    string $condition,
    string $expected,
): void {
    $sandbox = sys_get_temp_dir().'/orbit-doctor-master-'.Str::uuid();
    $procRoot = "{$sandbox}/proc";
    $mainPid = 610;
    $counter = "{$sandbox}/start-count";
    $files = new Filesystem;
    $files->makeDirectory("{$procRoot}/{$mainPid}", 0o755, true);
    file_put_contents("{$procRoot}/{$mainPid}/stat", application_process_stat($mainPid, 1, 8001));
    file_put_contents("{$procRoot}/{$mainPid}/status", "Uid:\t0\t0\t0\t0\n");
    symlink($condition === 'executable mismatch' ? '/usr/bin/bash' : '/usr/sbin/php-fpm8.5', "{$procRoot}/{$mainPid}/exe");
    if ($condition === 'missing status') {
        unlink("{$procRoot}/{$mainPid}/status");
    }
    $stability = '';
    if ($condition === 'changed start time') {
        file_put_contents($counter, '0');
        $stability = <<<BASH
            counter={$counter}
            process_start_time() {
                count=\$(cat "\$counter")
                count=\$((count + 1))
                printf '%s' "\$count" > "\$counter"
                if test "\$count" -eq 1; then printf '8001\n'; else printf '8002\n'; fi
            }
            BASH;
    }

    try {
        $setup = "proc_root={$procRoot}\nmain_pid={$mainPid}\n{$stability}";
        $program = application_production_observation_program('process_runtime_matches', $setup);
        $result = application_run(['bash'], $program);

        expect($result->stdout)->toBe("{$expected}\n");
    } finally {
        $files->deleteDirectory($sandbox);
    }
})->with([
    'expected executable root identity and stable start time' => ['matches', '1'],
    'executable mismatch' => ['executable mismatch', '0'],
    'missing status is unavailable' => ['missing status', '2'],
    'changed start time is unavailable' => ['changed start time', '2'],
]);

it('executes current worker identity outcomes from the production program', function (
    string $condition,
    string $expected,
): void {
    $sandbox = sys_get_temp_dir().'/orbit-doctor-workers-'.Str::uuid();
    $procRoot = "{$sandbox}/proc";
    $mainPid = 410;
    $workerPid = 411;
    $identity = posix_getpwuid(posix_geteuid());
    $groupIdentity = posix_getgrgid(posix_getegid());
    $user = is_array($identity) && is_string($identity['name'] ?? null) ? $identity['name'] : 'orbit';
    $group = is_array($groupIdentity) && is_string($groupIdentity['name'] ?? null)
        ? $groupIdentity['name']
        : $user;
    $files = new Filesystem;
    $files->makeDirectory("{$procRoot}/{$mainPid}/task/{$mainPid}", 0o755, true);
    $children = match ($condition) {
        'idle' => '',
        'exited before a UID mismatch' => "{$workerPid} ".($workerPid + 1)."\n",
        default => "{$workerPid}\n",
    };
    file_put_contents("{$procRoot}/{$mainPid}/task/{$mainPid}/children", $children);
    $writeWorker = static function (int $pid, int $uid, string $state = 'S', int $flags = 0) use ($files, $procRoot, $mainPid, $sandbox, $condition): void {
        $gid = $condition === 'gid mismatch' ? posix_getegid() + 1 : posix_getegid();
        $parent = $condition === 'reparented' ? $mainPid + 1 : $mainPid;
        $files->makeDirectory("{$procRoot}/{$pid}", 0o755, true);
        $status = "State:\t{$state}\nPPid:\t{$parent}\nUid:\t{$uid}\t{$uid}\t{$uid}\t{$uid}\nGid:\t{$gid}\t{$gid}\t{$gid}\t{$gid}\n";
        file_put_contents("{$procRoot}/{$pid}/status", $status);
        file_put_contents("{$procRoot}/{$pid}/stat", application_process_stat($pid, $parent, 9001, $flags));
        if ($state !== 'Z' && $flags === 0) {
            symlink($condition === 'root mismatch' ? $sandbox : '/', "{$procRoot}/{$pid}/root");
        }
    };

    match ($condition) {
        'idle', 'exited' => null,
        'exited before a UID mismatch' => $writeWorker($workerPid + 1, posix_geteuid() + 1),
        'zombie' => $writeWorker($workerPid, posix_geteuid(), 'Z'),
        'exiting' => $writeWorker($workerPid, posix_geteuid(), 'S', 0x4),
        default => $writeWorker($workerPid, $condition === 'uid mismatch' ? posix_geteuid() + 1 : posix_geteuid()),
    };

    if ($condition === 'missing status') {
        unlink("{$procRoot}/{$workerPid}/status");
    }

    try {
        $setup = sprintf(
            "proc_root=%s\nmain_pid=%d\nuser=%s",
            escapeshellarg($procRoot),
            $mainPid,
            escapeshellarg($user),
        );
        $program = application_production_observation_program('worker_identity_matches', $setup);
        $result = application_run(['bash'], $program);

        expect($result->stdout)->toBe("{$expected}\n")
            ->and($group)->not->toBeEmpty();
    } finally {
        $files->deleteDirectory($sandbox);
    }
})->with([
    'idle ondemand pool' => ['idle', '1'],
    'matching UID GID and root' => ['matches', '1'],
    'UID mismatch' => ['uid mismatch', '0'],
    'GID mismatch' => ['gid mismatch', '0'],
    'process root mismatch' => ['root mismatch', '0'],
    'unreadable status of a running worker' => ['missing status', '2'],
    'reparented worker' => ['reparented', '2'],
    'worker that exited after the children list' => ['exited', '1'],
    'worker that is exiting as a zombie' => ['zombie', '1'],
    'worker the kernel marks exiting' => ['exiting', '1'],
    'exited worker before a live UID mismatch' => ['exited before a UID mismatch', '0'],
]);

it('executes socket and service association outcomes from the production program', function (
    string $condition,
    string $expected,
): void {
    $sandbox = UnixSocketDirectory::create();
    $procRoot = "{$sandbox}/proc";
    $mainPid = 510;
    $socket = "{$sandbox}/php.sock";
    $files = new Filesystem;
    $files->makeDirectory("{$procRoot}/net", 0o755, true);
    $files->makeDirectory("{$procRoot}/{$mainPid}/fd", 0o755, true);
    $listener = stream_socket_server("unix://{$socket}", $errorCode, $errorMessage);
    if ($listener === false) {
        throw new RuntimeException("Could not create Unix socket: {$errorCode} {$errorMessage}");
    }
    chmod($socket, 0o660);
    $metadata = stat($socket);
    if (! is_array($metadata)) {
        throw new RuntimeException('Could not inspect Unix socket fixture.');
    }
    $inode = (string) $metadata['ino'];
    $table = "Num       RefCount Protocol Flags    Type St Inode Path\n"
        ."0000000000000000: 00000002 00000000 00010000 0001 01 {$inode} {$socket}\n";
    if ($condition === 'connections in flight') {
        // Each accepted connection repeats the path with its own inode and the connected state.
        $table .= '0000000000000001: 00000003 00000000 00000000 0001 03 '.($inode + 1)." {$socket}\n"
            .'0000000000000002: 00000003 00000000 00000000 0001 03 '.($inode + 2)." {$socket}\n";
    }
    if ($condition === 'two listeners') {
        $table .= '0000000000000003: 00000002 00000000 00010000 0001 01 '.($inode + 3)." {$socket}\n";
    }
    file_put_contents("{$procRoot}/net/unix", $table);
    if ($condition === 'closed descriptor') {
        // readlink fails on an entry that is no longer a descriptor link, as when the master closes it mid-scan.
        file_put_contents("{$procRoot}/{$mainPid}/fd/7", '');
    }
    symlink(
        $condition === 'mismatch' ? 'socket:[999999]' : "socket:[{$inode}]",
        "{$procRoot}/{$mainPid}/fd/8",
    );
    if ($condition === 'unavailable') {
        unlink("{$procRoot}/net/unix");
    }
    if ($condition === 'master exited') {
        // The master exits after systemd reported its PID, so its /proc entry is gone before the descriptor scan.
        $files->deleteDirectory("{$procRoot}/{$mainPid}");
    }
    $identity = posix_getpwuid(posix_geteuid());
    $groupIdentity = posix_getgrgid(posix_getegid());
    $user = is_array($identity) && is_string($identity['name'] ?? null) ? $identity['name'] : 'orbit';
    $group = is_array($groupIdentity) && is_string($groupIdentity['name'] ?? null)
        ? $groupIdentity['name']
        : $user;

    try {
        $setup = sprintf(
            "proc_root=%s\nmain_pid=%d\nsocket=%s\nuser=%s",
            escapeshellarg($procRoot),
            $mainPid,
            escapeshellarg($socket),
            escapeshellarg($user),
        );
        $program = application_production_observation_program('socket_service_matches', $setup);
        $program = str_replace(
            'test "$socket_metadata" = "$user:caddy:660"',
            'test "$socket_metadata" = "$user:'.addslashes($group).':660"',
            $program,
        );
        $result = application_run(['bash'], $program);

        expect($result->stdout)->toBe("{$expected}\n");
    } finally {
        fclose($listener);
        $files->deleteDirectory($sandbox);
    }
})->with([
    'master owns expected socket inode' => ['matches', '1'],
    'master owns another socket' => ['mismatch', '0'],
    'socket table disappears' => ['unavailable', '2'],
    'accepted connections in flight' => ['connections in flight', '1'],
    'two listening entries' => ['two listeners', '2'],
    'descriptor closed during the scan' => ['closed descriptor', '1'],
    'master that exited during the scan' => ['master exited', '2'],
]);

it('maps each production projection without retaining protected diagnostics', function (
    string $remote,
    string $field,
): void {
    $instance = application_production_app_instance(
        application_inspector_app(),
        application_inspector_node(),
        'private-production-value',
    );
    $ssh = new AppDevFakeSshExecutor([app_inspector_result($remote)]);

    $inspection = application_instance_inspector($ssh)->inspect($instance);

    expect($inspection->{$field})
        ->toBeFalse()
        ->and(json_encode($inspection, JSON_THROW_ON_ERROR))
        ->not->toContain('private-production-value');
})->with([
    'production home' => ["0\n1\n1\n1\n1\n1\n", 'productionHomeMatches'],
    'release selection' => ["1\n0\n1\n1\n1\n1\n", 'releaseSelectionMatches'],
    'selected release root' => ["1\n1\n0\n1\n1\n1\n", 'selectedReleaseRootMatches'],
    'environment' => ["1\n1\n1\n0\n1\n1\n", 'environmentProjectionMatches'],
    'PHP-FPM' => ["1\n1\n1\n1\n0\n1\n", 'phpFpmProjectionMatches'],
    'Caddy' => ["1\n1\n1\n1\n1\n0\n", 'caddyProjectionMatches'],
]);

it('finds each of the Instance site blocks unchanged in the one live Caddyfile', function (string $layout, string $expected): void {
    $sandbox = sys_get_temp_dir().'/orbit-doctor-caddy-'.bin2hex(random_bytes(6));
    $live = $layout === 'fragment' ? "{$sandbox}/v1/Caddyfile" : "{$sandbox}/v2/Caddyfile";
    $program = application_production_observation_program('caddy_matches', "readlink() { printf '%s\\n' '{$live}'; }");
    $instance = Instance::query()->latest('id')->firstOrFail();
    $sites = app(ProductionInstanceInspectionExpectationFactory::class)->make($instance)->caddySites;
    $render = app(NodeCaddyfileRenderer::class)->render($instance->node)->content;
    $files = new Filesystem;
    $files->ensureDirectoryExists("{$sandbox}/v1/fragments");
    $files->ensureDirectoryExists("{$sandbox}/v2");

    try {
        expect($sites)->toHaveCount(1)
            ->and($sites[0])->toStartWith("# orbit: app-prod app-instance-{$instance->id}\n")
            ->and($render)->toContain($sites[0]);

        file_put_contents($live, match ($layout) {
            'build' => $render,
            'edit inside' => str_replace($sites[0], str_replace("\n}\n", "\n    respond hand-edit\n}\n", $sites[0]), $render),
            'edit elsewhere' => $render."\nhand.example.test {\n    respond hi\n}\n",
            'fragment' => "import {$sandbox}/v1/fragments/*.caddy\n",
        });
        file_put_contents("{$sandbox}/v1/fragments/app-dev.caddy", $sites[0]);

        expect(application_run(['bash'], $program)->stdout)->toBe("{$expected}\n");
    } finally {
        $files->deleteDirectory($sandbox);
    }
})->with([
    'a Node Caddy build that matches a fresh render' => ['build', '1'],
    'a hand edit inside the Instance site' => ['edit inside', '0'],
    'a hand edit elsewhere in the file, which role.caddy_build_drift reports' => ['edit elsewhere', '1'],
    'the fragment layout of an earlier release, which Doctor no longer reads' => ['fragment', '0'],
]);

it('keeps an unavailable production runtime observation distinct from drift', function (): void {
    $instance = application_production_app_instance(
        application_inspector_app(),
        application_inspector_node(),
        'private-production-value',
    );
    $ssh = new AppDevFakeSshExecutor([app_inspector_result("0\n1\n1\n1\n2\n1\n")]);

    $inspection = application_instance_inspector($ssh)->inspect($instance);

    expect($inspection->productionHomeMatches)
        ->toBeFalse()
        ->and($inspection->phpFpmProjectionMatches)
        ->toBeNull()
        ->and(json_encode($inspection, JSON_THROW_ON_ERROR))
        ->not->toContain('private-production-value');
});

it('fails production inspection closed for malformed, failed, truncated, and diagnostic output', function (
    CommandResult $result,
): void {
    $instance = application_production_app_instance(
        application_inspector_app(),
        application_inspector_node(),
        'private-production-value',
    );

    $exception = application_capture_exception(
        fn (): InstanceInspectionData => application_instance_inspector(
            new AppDevFakeSshExecutor([$result]),
        )->inspect($instance),
    );

    application_assert_sanitized($exception, 'private-production-value');
})->with([
    'failure' => [app_inspector_result('', exitCode: 1, stderr: 'private-production-value')],
    'malformed' => [app_inspector_result('private-production-value')],
    'truncated' => [app_inspector_result("1\n1\n1\n1\n1\n1\n", truncated: true)],
    'stderr' => [app_inspector_result("1\n1\n1\n1\n1\n1\n", stderr: 'private-production-value')],
]);

/** @param non-empty-list<string> $arguments */
function application_run(array $arguments, ?string $input = null): CommandResult
{
    $result = new FixtureProcessRunner()->run(new ProcessInvocation($arguments, input: $input));
    expect($result->succeeded())->toBeTrue($result->stderr);

    return $result;
}

function application_production_observation_program(string $observation, string $setup, string $webRoot = 'public'): string
{
    $instance = application_production_app_instance(
        application_inspector_app(),
        application_inspector_node(),
        'private-production-value',
    );
    $instance->update(['root' => $webRoot]);
    $ssh = new AppDevFakeSshExecutor([app_inspector_result("1\n1\n1\n1\n1\n1\n")]);
    application_instance_inspector($ssh)->inspect($instance);
    $protected = $ssh->commands[0]->protectedInput;
    if (! $protected instanceof ProtectedInput) {
        throw new RuntimeException('Expected protected production inspection input.');
    }
    $program = stream_get_contents($protected->stream());
    if (! is_string($program)) {
        throw new RuntimeException('Expected readable production inspection input.');
    }
    $emissions = <<<'BASH'
        emit home_matches
        emit release_selection_matches
        emit selected_root_matches
        emit environment_matches
        emit php_fpm_matches
        emit caddy_matches
        BASH;
    $replacement = trim($setup)."\nemit {$observation}";
    $program = str_replace($emissions, $replacement, $program, $count);
    if ($count !== 1) {
        throw new RuntimeException('Could not isolate the production observation.');
    }

    return $program;
}

function application_process_stat(int $pid, int $parentPid, int $startTime, int $flags = 0): string
{
    return implode(' ', [
        (string) $pid,
        '(php-fpm worker)',
        'S',
        (string) $parentPid,
        ...array_fill(0, 4, '0'),
        (string) $flags,
        ...array_fill(0, 12, '0'),
        (string) $startTime,
    ])."\n";
}

function application_capture_exception(Closure $operation): DoctorInspectionException
{
    try {
        $operation();
    } catch (DoctorInspectionException $exception) {
        return $exception;
    }

    throw new RuntimeException('Expected inspection failure.');
}

function application_assert_sanitized(DoctorInspectionException $exception, string $sentinel): void
{
    expect($exception->getMessage())
        ->toBeEmpty()
        ->and((string) $exception)
        ->not->toContain($sentinel)->and(json_encode($exception))
        ->not->toContain($sentinel);
}

function application_inspector_node(): Node
{
    static $number = 20;
    $number++;

    return Node::query()->create([
        'name' => "doctor-node-{$number}",
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'amd64',
        'public_ssh_host' => "192.0.2.{$number}",
        'public_ssh_port' => 22,
        'user' => 'nckrtl',
        'wireguard_ip' => "10.44.0.{$number}",
        'tld' => "node-{$number}.test",
    ]);
}

function application_inspector_app(): Project
{
    static $number = 0;
    $number++;

    return Project::query()->create([
        'name' => "Project {$number}",
        'slug' => "project-{$number}",
        'repository_url' => "https://git.example.test/acme/project-{$number}.git",
    ]);
}

function application_app_instance(Project $project, Node $node, string $name = 'development'): Instance
{
    $project->update(['default_branch' => 'main', 'root' => 'public']);
    if (! $node->roles()->whereIn('role', [RoleName::AppDev->value, RoleName::AppProd->value])->exists()) {
        $node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    }

    return Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => $name,
        'checkout_path' => "/srv/users/nckrtl/apps/{$project->slug}/{$name}",
        'branch' => 'development',
        'starting_commit' => str_repeat('a', 40),
        'status' => InstanceState::Active,
    ]);
}

function application_mark_removing(Instance $instance): void
{
    $trigger = DB::table('sqlite_master')
        ->where('type', 'trigger')
        ->where('name', 'instances_removal_status_update')
        ->value('sql');
    expect($trigger)->toBeString();
    DB::statement('DROP TRIGGER instances_removal_status_update');

    try {
        $instance->update(['status' => InstanceState::Removing]);
    } finally {
        DB::statement($trigger);
    }
}

function application_app_inspector(FakeAgentCommandRunner $agents): NativeProjectStateInspector
{
    return new NativeProjectStateInspector(
        $agents,
        new CommandDeadline,
        application_inspector_accounts(),
        app(StorageRootResolver::class),
        new NodeSettingsNormalizer,
    );
}

function application_instance_inspector(
    SshExecutor|FakeAgentCommandRunner $runner,
): NativeInstanceStateInspector {
    $ssh = $runner instanceof SshExecutor ? $runner : new AppDevFakeSshExecutor;
    $agents = $runner instanceof FakeAgentCommandRunner ? $runner : new FakeAgentCommandRunner;

    return new NativeInstanceStateInspector(
        new DevelopmentSshExecutor($ssh, application_inspector_keys(), application_inspector_hosts()),
        new CommandDeadline,
        application_inspector_accounts(),
        new CheckoutRemovalBoundary(new ProtectedPathCatalog),
        app(ProductionInstanceInspectionExpectationFactory::class),
        $agents,
    );
}

function application_production_app_instance(Project $project, Node $node, string $secret): Instance
{
    NodeRole::query()->firstOrCreate(
        ['node_id' => $node->id, 'role' => RoleName::AppProd],
        ['status' => LifecycleStatus::Active],
    );
    $project->update(['default_branch' => 'main', 'root' => 'public']);
    $user = "orbit-app-{$project->id}";
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'production',
        'environment' => 'production',
        'checkout_path' => "/home/{$user}/releases/initial",
        'production_user' => $user,
        'production_home' => "/home/{$user}",
        'production_php_service' => "orbit-{$user}-php8.5-fpm.service",
        'production_php_pool' => "orbit-{$user}",
        'production_php_socket' => "/run/php/{$user}.sock",
        'root' => 'public',
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'selected_php_version' => '8.5',
        'source_is_laravel' => true,
        'provisioning_step' => 'active',
        'status' => InstanceState::Active,
    ]);
    $route = Route::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'domain' => "{$project->slug}.example.test",
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Public,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);
    $instance->environmentValues()->create(['env_key' => 'APP_KEY', 'env_value' => $secret]);

    return $instance;
}

function application_inspector_accounts(): ManagedUserAccountResolver
{
    return new class implements ManagedUserAccountResolver
    {
        public function resolve(Node $node): ManagedUserAccount
        {
            return new ManagedUserAccount('nckrtl', 'nckrtl', '/srv/users/nckrtl');
        }
    };
}

function application_inspector_keys(): SshKeyProvider
{
    return new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/tmp/doctor-key';
        }

        public function publicKey(): string
        {
            return 'ssh-ed25519 AAAA';
        }
    };
}

function application_inspector_hosts(): KnownHostsStore
{
    return new class implements KnownHostsStore
    {
        public function path(): string
        {
            return '/tmp/doctor-known-hosts';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    };
}

function app_inspector_result(
    string $stdout,
    int $exitCode = 0,
    string $stderr = '',
    bool $truncated = false,
): CommandResult {
    return new CommandResult($exitCode, $stdout, $stderr, 1, $truncated);
}
