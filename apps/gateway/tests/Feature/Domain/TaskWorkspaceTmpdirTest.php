<?php

declare(strict_types=1);

use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\Projects\GatewaySetupSteps;
use App\Domain\Tasks\TaskCheckProcess;
use App\Domain\Tasks\TaskCheckReading;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\RemoteTaskCheckRunner;
use App\Infrastructure\Tasks\TaskUnitProgram;
use App\Infrastructure\Tasks\TaskWorkspaceExecutor;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\Support\ForkedPythonPrograms;
use Tests\Support\LinuxHost;
use Tests\Support\LocalShellSshExecutor;
use Tests\Support\ResolvedVp;
use Tests\Support\StandardLibraryPython;

require_once __DIR__.'/../../Support/TaskCheckRunnerFixtures.php';

beforeAll(function (): void {
    tmpdir_programs();
});

afterAll(function (): void {
    tmpdir_programs()->close();
});

function tmpdir_programs(): ForkedPythonPrograms
{
    static $programs = null;

    return $programs ??= new ForkedPythonPrograms;
}

beforeEach(function (): void {
    $this->directory = sys_get_temp_dir().'/orbit-workspace-tmpdir-'.bin2hex(random_bytes(8));
    $this->allocated = [];
    File::ensureDirectoryExists($this->directory);
    File::ensureDirectoryExists($this->directory.'/home', 0700);
});

afterEach(function (): void {
    File::deleteDirectory($this->directory);
    foreach ($this->allocated as $path) {
        if (! is_string($path) || $path === '' || ! is_dir($path)) {
            continue;
        }
        $real = realpath($path);
        if (is_string($real) && $real !== '/tmp' && str_starts_with($real, '/tmp/')) {
            File::deleteDirectory($real);
        }
    }
});

function tmpdir_shell(): LocalShellSshExecutor
{
    $transport = new LocalShellSshExecutor;
    $transport->environment['HOME'] = test()->directory.'/home';
    $transport->environment['PATH'] = StandardLibraryPython::path((string) getenv('PATH'));

    return $transport;
}

function tmpdir_runner(?SshExecutor $transport = null): RemoteTaskCheckRunner
{
    return new RemoteTaskCheckRunner(new TaskWorkspaceExecutor(new DevelopmentSshExecutor(
        $transport ?? tmpdir_shell(),
        new class implements SshKeyProvider
        {
            public function privateKeyPath(): string
            {
                return '/unused';
            }

            public function publicKey(): string
            {
                return 'unused';
            }
        },
        new class implements KnownHostsStore
        {
            public function path(): string
            {
                return '/unused';
            }

            public function put(string $host, int $port, HostKey $key): void {}
        },
    )), ResolvedVp::manager(), app(GatewaySetupSteps::class), app(RepositoryPullRequestAccess::class));
}

function tmpdir_records_scripts(array &$scripts): SshExecutor
{
    $inner = tmpdir_shell();

    return new class($inner, $scripts) implements SshExecutor
    {
        public function __construct(private SshExecutor $inner, private array &$scripts) {}

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $this->scripts[] = (string) $command->input;

            return $this->inner->execute($connection, $command);
        }
    };
}

function tmpdir_script_allocates(string $script): bool
{
    return str_contains($script, "workspace_metadata 'tmpdir'");
}

function tmpdir_check_directory(string $checkout): string
{
    (new Process(['git', 'init', '-q', $checkout]))->mustRun();
    $metadata = new Process(['python3', resource_path('tasks/metadata'), $checkout, 'tmpdir']);
    $metadata->setInput('{}');
    $path = rtrim($metadata->mustRun()->getOutput(), "\n");
    $allocated = test()->allocated;
    test()->allocated = [...(is_array($allocated) ? $allocated : []), $path];

    return $path;
}

function tmpdir_instance(string $checkout, string $name): Instance
{
    $suffix = bin2hex(random_bytes(4));
    $slug = $name.'-'.$suffix;
    $host = '10.44.'.random_int(1, 254).'.'.random_int(1, 254);
    $project = Project::query()->create(['name' => $slug, 'slug' => $slug, 'repository_url' => 'git@github.com:acme/'.$slug.'.git', 'default_branch' => 'main']);
    $node = Node::query()->create(['name' => $slug, 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => $host, 'wireguard_ip' => $host, 'user' => 'orbit']);

    return test_ready_task_workspace($project, $node, [
        'name' => 'task-'.$slug,
        'checkout_path' => $checkout,
        'branch' => 'task-'.$slug,
    ]);
}

function tmpdir_wait(RemoteTaskCheckRunner $runner, Instance $instance, TaskCheckProcess $process): TaskCheckReading
{
    return check_runner_wait($runner, $instance, $process);
}

function tmpdir_remove(string $path): bool
{
    $script = test()->directory.'/check.py';
    file_put_contents($script, TaskUnitProgram::read('tasks/check'));
    $probe = tmpdir_programs()->runCommand(<<<'PYTHON'
import importlib.machinery, importlib.util, sys
loader = importlib.machinery.SourceFileLoader('check', sys.argv[1])
check = importlib.util.module_from_spec(importlib.util.spec_from_loader('check', loader))
loader.exec_module(check)
sys.exit(0 if check.remove_allocated_check_tmpdir(sys.argv[2]) else 1)
PYTHON, [$script, $path]);

    return $probe->exitCode === 0;
}

function tmpdir_checkout(string $directory, string $name): string
{
    $checkout = $directory.'/'.$name;
    (new Process(['git', 'init', '-q', $checkout]))->mustRun();
    (new Process(['git', '-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '-q', '--allow-empty', '-m', 'start'], $checkout))->mustRun();

    return $checkout;
}

describe('workspace TMPDIR', function (): void {
    it('runs login commands in its private fixture home without changing the caller', function (): void {
        $home = $this->directory.'/home';
        File::ensureDirectoryExists($home, 0700);
        file_put_contents($home.'/.profile', "export ORBIT_TMPDIR_LOGIN_PROOF=private-home\n");
        $priorHome = getenv('HOME');
        $priorProof = getenv('ORBIT_TMPDIR_LOGIN_PROOF');
        $transport = tmpdir_shell();
        $result = $transport->execute(
            new SshConnection('127.0.0.1', 'fixture', 22, '/unused', '/unused'),
            new RemoteCommand(['bash', '-lc', 'printf "%s\\n%s\\n" "$HOME" "$ORBIT_TMPDIR_LOGIN_PROOF"']),
        );

        expect($result->exitCode)->toBe(0)
            ->and($result->stdout)->toBe($home."\nprivate-home\n")
            ->and($result->stderr)->toBe('')
            ->and($transport->environment['HOME'] ?? null)->toBe($home)
            ->and(getenv('HOME'))->toBe($priorHome)
            ->and(getenv('ORBIT_TMPDIR_LOGIN_PROOF'))->toBe($priorProof);
    });

    it('gives test processes inheriting private TMPDIR a separate traversable fixture root', function (): void {
        $temporary = tmpdir_check_directory($this->directory);
        $probe = new Process([PHP_BINARY, '-r', <<<'PHP'
            require 'vendor/autoload.php';
            Tests\Support\TestTemporaryDirectory::bootstrap();
            echo json_encode(['path' => sys_get_temp_dir(), 'mode' => fileperms(sys_get_temp_dir()) & 0777]);
            PHP,
        ], base_path(), ['TMPDIR' => $temporary]);
        $fixture = json_decode($probe->mustRun()->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        expect($fixture['path'])->toStartWith(realpath('/tmp').'/orbit-gateway-tests-')->not->toBe($temporary);
        expect($fixture['mode'])->toBe(0755);
        expect($temporary)->toStartWith(realpath('/tmp').'/orbit-check-'.posix_geteuid().'-');
        expect(fileowner($temporary))->toBe(posix_geteuid());
        expect(fileperms($temporary) & 0777)->toBe(0711);
    });

    it('keeps restrictive prior shared caches out of setup and analyse in per-workspace tmp', function (): void {
        $shared = $this->directory.'/prior-shared';
        File::ensureDirectoryExists($shared.'/phpstan');
        chmod($shared.'/phpstan', 0500);
        $analyse = 'mkdir -p "$TMPDIR/phpstan" && printf analysed > "$TMPDIR/phpstan/result" && cp "$TMPDIR/phpstan/result" phpstan-result && getfacl -cp "$TMPDIR" > tmp-acl && stat -c %a:%u "$TMPDIR" > tmp-stat && printf %s "$TMPDIR"';
        // The old layout really fails on permissions, not on an assertion about the new path.
        expect((new Process(['bash', '-c', $analyse], null, ['TMPDIR' => $shared]))->run())->not->toBe(0);
        $prior = getenv('TMPDIR');
        $priorServer = $_SERVER['TMPDIR'] ?? null;
        $priorEnvironment = $_ENV['TMPDIR'] ?? null;
        putenv('TMPDIR='.$shared);
        $_SERVER['TMPDIR'] = $_ENV['TMPDIR'] = $shared;

        try {
            $runner = tmpdir_runner();
            $checks = [];
            foreach (['first', 'second'] as $name) {
                $checkout = tmpdir_checkout($this->directory, $name);
                File::ensureDirectoryExists($checkout.'/.git/orbit/tmp');
                (new Process(['setfacl', '-m', 'd:u:nobody:rwX', $checkout.'/.git/orbit/tmp']))->mustRun();
                $instance = tmpdir_instance($checkout, $name);
                $process = $runner->start($instance, $analyse.' > check-tmp', [
                    ['name' => 'setup', 'command' => $analyse.' > setup-tmp', 'timeout_seconds' => 10],
                ], ['start' => null, 'commands' => [
                    ['id' => 'tmp', 'command' => $analyse.' > deliverable-tmp', 'directory' => '.'],
                ]]);
                $checks[] = [$checkout, $process];
            }
            $directories = [];
            foreach ($checks as [$checkout, $process]) {
                // Observe the local result directly; remote status decoding has its own proofs.
                check_runner_wait_for_exit($process);
                $result = json_decode(file_get_contents($checkout.'/.git/orbit/check.json'), true, flags: JSON_THROW_ON_ERROR);
                expect($result['exit_code'])->toBe(0);
                $expected = file_get_contents($checkout.'/setup-tmp');
                expect($expected)->toStartWith(realpath('/tmp').'/orbit-check-'.posix_geteuid().'-')
                    ->not->toContain($checkout)
                    ->not->toContain('.git');
                expect(file_get_contents($checkout.'/tmp-stat'))->toBe('711:'.posix_geteuid()."\n");
                foreach (['setup-tmp', 'check-tmp', 'deliverable-tmp'] as $file) {
                    expect(file_get_contents($checkout.'/'.$file))->toBe($expected);
                }
                expect(file_get_contents($checkout.'/phpstan-result'))->toBe('analysed');
                expect(file_get_contents($checkout.'/tmp-acl'))->not->toContain('default:')->not->toContain('user:nobody:');
                expect(is_dir($expected))->toBeFalse();
                $directories[] = $expected;
            }
            expect($directories[0])->not->toBe($directories[1]);
        } finally {
            chmod($shared.'/phpstan', 0700);
            putenv($prior === false ? 'TMPDIR' : 'TMPDIR='.$prior);
            if ($priorServer === null) {
                unset($_SERVER['TMPDIR']);
            } else {
                $_SERVER['TMPDIR'] = $priorServer;
            }
            if ($priorEnvironment === null) {
                unset($_ENV['TMPDIR']);
            } else {
                $_ENV['TMPDIR'] = $priorEnvironment;
            }
        }
    });

    it('lets another Unix user traverse the check TMPDIR to a granted child', function (): void {
        $parent = $this->directory.'/acl-parent';
        File::ensureDirectoryExists($parent);
        (new Process(['setfacl', '-m', 'd:u:nobody:rwx,d:u:'.posix_geteuid().':rwx', $parent]))->mustRun();

        $legacy = $parent.'/check-'.posix_geteuid();
        mkdir($legacy, 0700);
        (new Process(['setfacl', '-b', '-k', $legacy]))->mustRun();
        chmod($legacy, 0700);
        $legacyChild = $legacy.'/child';
        mkdir($legacyChild, 0755);
        file_put_contents($legacyChild.'/file', 'secret');
        (new Process(['setfacl', '-m', 'u:nobody:rwx', $legacyChild]))->mustRun();
        (new Process(['setfacl', '-m', 'u:nobody:r', $legacyChild.'/file']))->mustRun();

        expect((new Process(['sudo', '-n', '-u', 'nobody', 'test', '-r', $legacyChild.'/file']))->run())->not->toBe(0);

        $allocated = tmpdir_check_directory($this->directory);
        expect($allocated)->toStartWith(realpath('/tmp').'/orbit-check-'.posix_geteuid().'-')
            ->not->toContain($parent)
            ->toMatch('#\A[A-Za-z0-9/_-]+\z#');
        expect(LinuxHost::stopScript($allocated))->toBeString();
        expect(fileowner($allocated))->toBe(posix_geteuid());
        expect(fileperms($allocated) & 0777)->toBe(0711);
        $permissions = (new Process(['getfacl', '-cp', $allocated]))->mustRun()->getOutput();
        expect($permissions)->not->toContain('default:');

        $child = $allocated.'/child';
        mkdir($child, 0755);
        file_put_contents($child.'/file', 'shared');
        (new Process(['setfacl', '-m', 'u:nobody:rwx', $child]))->mustRun();
        (new Process(['setfacl', '-m', 'u:nobody:r', $child.'/file']))->mustRun();

        expect((new Process(['sudo', '-n', '-u', 'nobody', 'test', '-r', $child.'/file']))->run())->toBe(0);
    })->group('privileged');

    it('removes the allocated TMPDIR after a passing check', function (): void {
        $checkout = tmpdir_checkout($this->directory, 'pass');
        $instance = tmpdir_instance($checkout, 'pass');
        $runner = tmpdir_runner();

        $process = $runner->start($instance, 'printf %s "$TMPDIR" > tmpdir-path.pending && mv -- tmpdir-path.pending tmpdir-path && while [ ! -f release-check ]; do sleep 0.05; done && test -d "$TMPDIR"');
        try {
            for ($attempt = 0; $attempt < 100 && ! is_file($checkout.'/tmpdir-path'); $attempt++) {
                usleep(50_000);
            }
            $path = (string) file_get_contents($checkout.'/tmpdir-path');
            $first = $runner->read($instance, $process);

            expect($first->state)->toBe('running')
                ->and($path)->toStartWith(realpath('/tmp').'/orbit-check-'.posix_geteuid().'-')
                ->and(is_dir($path))->toBeTrue();
        } finally {
            file_put_contents($checkout.'/release-check', 'ready');
            $reading = tmpdir_wait($runner, $instance, $process);
        }

        expect($reading->exitCode)->toBe(0)
            ->and(is_dir($path))->toBeFalse();
    });

    it('removes the allocated TMPDIR after a failing check', function (): void {
        $checkout = tmpdir_checkout($this->directory, 'fail');
        $instance = tmpdir_instance($checkout, 'fail');
        $runner = tmpdir_runner();

        $reading = tmpdir_wait($runner, $instance, $runner->start($instance, 'printf %s "$TMPDIR" > tmpdir-path.pending && mv -- tmpdir-path.pending tmpdir-path && exit 2'));
        $path = (string) file_get_contents($checkout.'/tmpdir-path');

        expect($reading->exitCode)->toBe(2)
            ->and($path)->toStartWith(realpath('/tmp').'/orbit-check-'.posix_geteuid().'-')
            ->and(is_dir($path))->toBeFalse();
    });

    it('removes the allocated TMPDIR after a cancelled check', function (): void {
        $checkout = tmpdir_checkout($this->directory, 'cancel');
        $instance = tmpdir_instance($checkout, 'cancel');
        $runner = tmpdir_runner();
        $process = $runner->start($instance, 'printf %s "$TMPDIR" > tmpdir-path.pending && mv -- tmpdir-path.pending tmpdir-path && sleep 30');
        for ($attempt = 0; $attempt < 100 && ! is_file($checkout.'/tmpdir-path'); $attempt++) {
            usleep(50_000);
        }
        $path = (string) file_get_contents($checkout.'/tmpdir-path');
        expect($path)->toStartWith(realpath('/tmp').'/orbit-check-'.posix_geteuid().'-')
            ->and(is_dir($path))->toBeTrue();

        $runner->cancel($instance, $process);
        $reading = tmpdir_wait($runner, $instance, $process);

        expect($reading->state)->toBe('lost')
            ->and(is_dir($path))->toBeFalse();
    });

    it('removes the allocated TMPDIR after a killed check', function (): void {
        $checkout = tmpdir_checkout($this->directory, 'killed');
        $instance = tmpdir_instance($checkout, 'killed');
        $runner = tmpdir_runner();
        $process = $runner->start($instance, 'printf %s "$TMPDIR" > tmpdir-path.pending && mv -- tmpdir-path.pending tmpdir-path && sleep 30');
        for ($attempt = 0; $attempt < 100 && ! is_file($checkout.'/tmpdir-path'); $attempt++) {
            usleep(50_000);
        }
        $path = (string) file_get_contents($checkout.'/tmpdir-path');
        expect($path)->toStartWith(realpath('/tmp').'/orbit-check-'.posix_geteuid().'-')
            ->and(is_dir($path))->toBeTrue();

        posix_kill($process->pid, SIGKILL);
        // Cancellation cleans a leftover only after the killed process is gone, not while SIGKILL is pending.
        $reading = tmpdir_wait($runner, $instance, $process);
        clearstatcache(true, $path);
        expect($reading->state)->toBe('lost')
            ->and(is_dir($path))->toBeTrue();

        $runner->cancel($instance, $process);

        clearstatcache(true, $path);
        expect(is_dir($path))->toBeFalse();
    });

    it('refuses to remove an unexpected TMPDIR path', function (): void {
        $uid = posix_geteuid();
        $root = realpath('/tmp');
        $allocated = tmpdir_check_directory($this->directory);
        $nested = $allocated.'/child';
        mkdir($nested, 0700);
        $sibling = $this->directory.'/orbit-check-'.$uid.'-nested';
        mkdir($sibling, 0700);
        $foreign = $root.'/orbit-gateway-tests-'.bin2hex(random_bytes(4));
        mkdir($foreign, 0700);
        $this->allocated[] = $foreign;
        $escapeName = 'orbit-sibling-'.bin2hex(random_bytes(4));
        $escape = $allocated.'/../'.$escapeName;
        mkdir($escape, 0700);
        // The test removes $allocated, so clean up through a path that does not pass through it.
        $this->allocated[] = dirname($allocated).'/'.$escapeName;

        expect(tmpdir_remove(''))->toBeFalse()
            ->and(tmpdir_remove('/tmp'))->toBeFalse()
            ->and(tmpdir_remove($root))->toBeFalse()
            ->and(tmpdir_remove($root.'/orbit-check-'.$uid))->toBeFalse()
            ->and(tmpdir_remove($root.'/orbit-check-'.$uid.'-'))->toBeFalse()
            ->and(tmpdir_remove($sibling))->toBeFalse()
            ->and(tmpdir_remove($foreign))->toBeFalse()
            ->and(tmpdir_remove($nested))->toBeFalse()
            ->and(tmpdir_remove($escape))->toBeFalse()
            ->and(tmpdir_remove($allocated.'/..'))->toBeFalse()
            ->and(is_dir($allocated))->toBeTrue()
            ->and(is_dir($nested))->toBeTrue()
            ->and(is_dir($sibling))->toBeTrue()
            ->and(is_dir($foreign))->toBeTrue()
            ->and(is_dir($escape))->toBeTrue()
            ->and(is_dir($root))->toBeTrue();

        expect(tmpdir_remove($allocated))->toBeTrue()
            ->and(is_dir($allocated))->toBeFalse()
            ->and(is_dir($nested))->toBeFalse();
    });

    it('allocates a check TMPDIR only when start runs, never on status, cancel, or snapshot', function (): void {
        $scripts = [];
        $checkout = tmpdir_checkout($this->directory, 'polls');
        $instance = tmpdir_instance($checkout, 'polls');
        $runner = tmpdir_runner(tmpdir_records_scripts($scripts));

        $process = $runner->start($instance, 'printf %s "$TMPDIR" > tmpdir-path.pending && mv -- tmpdir-path.pending tmpdir-path && sleep 30');
        for ($attempt = 0; $attempt < 100 && ! is_file($checkout.'/tmpdir-path'); $attempt++) {
            usleep(50_000);
        }
        $path = (string) file_get_contents($checkout.'/tmpdir-path');
        expect($path)->toStartWith(realpath('/tmp').'/orbit-check-'.posix_geteuid().'-')
            ->and(is_dir($path))->toBeTrue()
            ->and(tmpdir_script_allocates($scripts[0] ?? ''))->toBeTrue();
        $this->allocated[] = $path;
        $child = $path.'/granted';
        mkdir($child, 0755);
        file_put_contents($child.'/file', 'keep');
        (new Process(['setfacl', '-m', 'u:nobody:r', $child.'/file']))->mustRun();
        $mode = fileperms($path) & 0777;
        $inode = fileinode($path);
        $afterStart = count($scripts);

        $reading = $runner->read($instance, $process);
        $snapshot = $runner->snapshot($instance);

        expect($reading->state)->toBe('running')
            ->and($snapshot->head)->toBe($process->head)
            ->and(is_dir($path))->toBeTrue()
            ->and(fileperms($path) & 0777)->toBe($mode)
            ->and(fileinode($path))->toBe($inode)
            ->and((new Process(['getfacl', '-cp', $child.'/file']))->mustRun()->getOutput())->toContain('user:nobody:r');
        expect(array_map(tmpdir_script_allocates(...), array_slice($scripts, $afterStart)))->each->toBeFalse();

        $beforeCancel = count($scripts);
        $runner->cancel($instance, $process);
        $finished = tmpdir_wait($runner, $instance, $process);

        expect($finished->state)->toBe('lost')
            ->and(is_dir($path))->toBeFalse()
            ->and(tmpdir_script_allocates($scripts[$beforeCancel] ?? ''))->toBeFalse();
    });
});
