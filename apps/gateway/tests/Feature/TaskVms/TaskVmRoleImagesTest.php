<?php

declare(strict_types=1);

use App\Domain\TaskVms\TaskVmException;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\TaskVms\TaskVmRoleImages;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use App\Models\TaskVmImageBuild;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\AppDevFakeSshExecutor;
use Tests\Support\TaskVmRecipeImages;
use Tests\Support\TaskVmSettingsBuilder;

/**
 * A fake `incus` for the host: the VM's filesystem is `$WORK/guest`. Like the real one, `incus exec` without `-n`
 * forwards the caller's stdin into the guest, so it takes whatever is left of a script that arrives on stdin.
 * `DROP_WRITES=1` makes `file push` and `mv` report success without writing, as a run cut short would.
 */
const RoleImagesFakeIncus = <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "${*//$'\n'/ }" >> "$WORK/calls"
[[ $1 == --project ]] && shift 2
guest() { printf '%s' "$WORK/guest$1"; }
case $1 in
    exec)
        shift
        if [[ $1 == -n ]]; then shift; else cat >/dev/null; fi
        shift 2 # the VM and `--`
        case $1 in
            install) mkdir -p -- "$(guest "${!#}")" ;;
            mv) [[ ${DROP_WRITES-} == 1 ]] || mv -f -- "$(guest "$3")" "$(guest "$4")" ;;
            python3) python3 "$2" "$3" "$(guest "$4")" "${@:5}" ;;
            *) exit 99 ;;
        esac
        ;;
    image) printf 'image bytes' >"$4.tar" ;;
    file)
        [[ ${DROP_WRITES-} == 1 ]] && exit 0
        target=$(guest "/${4#tvm-*/}")
        mkdir -p -- "$(dirname -- "$target")"
        cp -- "$3" "$target"
        ;;
    *) exit 98 ;;
esac
SH;

/** Runs each host command locally, as `sudo -n` would on the host, with the fake `incus` first on the PATH. */
final class RoleImagesLocalHost implements SshExecutor
{
    /** @var list<RemoteCommand> */
    public array $commands = [];

    /** @param array<string, string> $environment */
    public function __construct(private readonly string $work, public array $environment = []) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->commands[] = $command;
        expect(array_slice($command->arguments, 0, 2))->toBe(['sudo', '-n']);
        $process = new Process(array_slice($command->arguments, 2), null, ['WORK' => $this->work, 'PATH' => $this->work.'/bin:'.getenv('PATH'), ...$this->environment], $command->input);
        $process->run();

        return new CommandResult((int) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput(), 1, false);
    }

    /** @return list<string> */
    public function calls(): array
    {
        return is_file($this->work.'/calls') ? file($this->work.'/calls', FILE_IGNORE_NEW_LINES) ?: [] : [];
    }
}

beforeEach(function (): void {
    $host = Node::query()->create(['name' => 'beast', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'wireguard_ip' => '10.44.0.7', 'public_ssh_host' => '192.0.2.7', 'user' => 'orbit']);
    TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host($host->id)]]);
    $this->build = TaskVmRecipeImages::ready($host->id);
    $project = Project::query()->create(['name' => 'Recipe', 'slug' => 'recipe', 'repository_url' => 'https://github.com/acme/recipe.git', 'default_branch' => 'main']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Nodes', 'brief' => 'Work', 'status' => 'reserved']);
    Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'Prod', 'brief' => 'Prod', 'status' => 'todo', 'topology' => ['app-prod']]);
    $this->vm = TaskVm::query()->create(['group_id' => $group->id, 'host_node_id' => $host->id, 'provider' => 'incus', 'size' => 'medium', 'name' => 'tvm-7', 'state' => 'provisioning', 'wireguard_ip' => '10.44.0.129', 'recipe_identity' => $this->build->recipe_identity, 'image_fingerprint' => $this->build->image_fingerprint]);
    $this->work = sys_get_temp_dir().'/orbit-role-transfer-'.bin2hex(random_bytes(6));
    mkdir($this->work.'/bin', 0700, true);
    file_put_contents($this->work.'/bin/incus', RoleImagesFakeIncus);
    chmod($this->work.'/bin/incus', 0755);
    $this->host = new RoleImagesLocalHost($this->work);
    app()->instance(SshExecutor::class, $this->host);
});

afterEach(function (): void {
    new Process(['rm', '-rf', '--', $this->work])->run();
});

it('transfers the claimed recipe from its own host even after a newer recipe becomes desired', function (): void {
    DB::table('task_vm_image_recipe')->where('id', 1)->update(['identity' => 'sha256:'.str_repeat('b', 64)]);
    $ssh = new AppDevFakeSshExecutor;
    app()->instance(SshExecutor::class, $ssh);

    app(TaskVmRoleImages::class)->prepare($this->vm);

    expect($ssh->commands)->toHaveCount(1);
    $command = $ssh->commands[0];
    expect($ssh->connections[0]->host)->toBe('10.44.0.7')
        ->and(array_slice($command->arguments, 0, 4))->toBe(['sudo', '-n', 'bash', '-ceu'])
        ->and(array_slice($command->arguments, 5, 4))->toBe(['--', 'transfer', 'orbit-tasks', 'tvm-7'])
        ->and($command->input)->toBeNull()
        ->and($command->protectedInput)->toBeNull();
    $manifest = json_decode($command->arguments[9], true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['recipe_identity'])->toBe($this->build->recipe_identity)
        ->and(array_keys($manifest['images']))->toBe(['base'])
        ->and($manifest['images']['base'])->toBe($this->build->images['base']);
});

it('runs the whole transfer through an incus exec that reads stdin, and publishes the manifest last', function (): void {
    $images = app(TaskVmRoleImages::class);

    $images->prepare($this->vm);

    $calls = $this->host->calls();
    $fingerprint = $this->build->image_fingerprint;
    $guest = '/var/lib/orbit/task-vm-role-images';
    expect(implode("\n", $calls))->toContain("image export {$fingerprint} ", 'file push ', "exec -n tvm-7 -- mv -f {$guest}/manifest.json.new {$guest}/manifest.json");
    expect($calls)->toHaveCount(7)
        ->and($calls[0])->toStartWith('--project orbit-tasks exec -n tvm-7 -- python3 -c ')
        ->and($calls[1])->toBe("--project orbit-tasks exec -n tvm-7 -- install -d -o 1000 -g 1000 -m 0700 {$guest}")
        ->and($calls[2])->toStartWith("--project orbit-tasks image export {$fingerprint} ")
        ->and($calls[3])->toStartWith('--project orbit-tasks file push ')->toContain("tvm-7{$guest}/base.tar")
        ->and($calls[4])->toStartWith('--project orbit-tasks file push ')->toContain("tvm-7{$guest}/manifest.json.new")
        ->and($calls[5])->toBe("--project orbit-tasks exec -n tvm-7 -- mv -f {$guest}/manifest.json.new {$guest}/manifest.json")
        ->and($calls[6])->toStartWith('--project orbit-tasks exec -n tvm-7 -- python3 -c ');
    expect(file_get_contents($this->work.'/guest'.$guest.'/base.tar'))->toBe('image bytes');
    $manifest = json_decode((string) file_get_contents($this->work.'/guest'.$guest.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['recipe_identity'])->toBe($this->build->recipe_identity)
        ->and($manifest['images']['base']['fingerprint'])->toBe($fingerprint)
        ->and($images->present($this->vm))->toBeTrue();
});

it('skips the copy when the VM already holds the claimed manifest', function (): void {
    $images = app(TaskVmRoleImages::class);
    $images->prepare($this->vm);
    $before = count($this->host->calls());

    $images->prepare($this->vm);

    $again = array_slice($this->host->calls(), $before);
    expect($again)->toHaveCount(1)
        ->and($again[0])->toStartWith('--project orbit-tasks exec -n tvm-7 -- python3 -c ');
});

it('transfers again when the manifest in the VM names another recipe image', function (): void {
    $images = app(TaskVmRoleImages::class);
    $images->prepare($this->vm);
    $fingerprint = str_repeat('c', 64);
    TaskVmImageBuild::query()->whereKey($this->build->id)->update(['images' => ['base' => [...$this->build->images['base'], 'fingerprint' => $fingerprint]], 'image_fingerprint' => $fingerprint]);
    $this->vm->update(['image_fingerprint' => $fingerprint]);

    expect($images->present($this->vm))->toBeFalse();
    $images->prepare($this->vm);

    expect(implode("\n", $this->host->calls()))->toContain("image export {$fingerprint} ")
        ->and($images->present($this->vm))->toBeTrue();
});

it('fails a transfer whose copy was cut short instead of reporting success', function (): void {
    $this->host->environment = ['DROP_WRITES' => '1'];

    expect(fn () => app(TaskVmRoleImages::class)->prepare($this->vm))
        ->toThrow(fn (TaskVmException $exception) => expect($exception->errorCode)->toBe('task_vm.recipe_images_transfer_failed')
            ->and($exception->getMessage())->toContain('Exit code [67]'));
});

it('fails a check that cannot read the VM instead of answering absent', function (): void {
    app()->instance(SshExecutor::class, new AppDevFakeSshExecutor([new CommandResult(1, '', 'Error: Instance is not running', 1, false)]));

    expect(fn () => app(TaskVmRoleImages::class)->present($this->vm))
        ->toThrow(fn (TaskVmException $exception) => expect($exception->errorCode)->toBe('task_vm.recipe_images_unreadable')
            ->and($exception->getMessage())->toContain('Instance is not running'));
});

it('needs no image for a VM that runs no topology', function (): void {
    $ssh = new AppDevFakeSshExecutor;
    app()->instance(SshExecutor::class, $ssh);
    $this->vm->update(['size' => 'normal']);

    $images = app(TaskVmRoleImages::class);
    $images->prepare($this->vm);

    expect($images->present($this->vm))->toBeTrue()->and($ssh->commands)->toBe([]);
});
