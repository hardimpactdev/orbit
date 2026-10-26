<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Tasks\TaskUnitProgram;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class TaskUnitStateFixture
{
    public readonly string $home;

    public readonly string $receipt;

    public readonly string $boot;

    public readonly string $operation;

    public readonly string $binding;

    /** @var array<string, string> */
    public array $state;

    public function __construct()
    {
        $this->home = sys_get_temp_dir().'/orbit-unit-state-'.bin2hex(random_bytes(10));
        $this->operation = str_repeat('a', 32);
        $this->binding = str_repeat('b', 64);
        $this->boot = trim(file_get_contents('/proc/sys/kernel/random/boot_id'));
        $this->receipt = $this->home.'/.local/state/orbit/task-workspace-operations/'.$this->operation;
        new Filesystem()->ensureDirectoryExists(dirname($this->receipt), 0700);
        mkdir($this->home.'/bin', 0700);
        foreach (['sudo', 'systemctl'] as $tool) {
            copy(__DIR__.'/../Fixtures/task-unit-tools.php', $this->home.'/bin/'.$tool);
            chmod($this->home.'/bin/'.$tool, 0700);
        }
        $this->state = ['LoadState' => 'loaded', 'ActiveState' => 'failed', 'SubState' => 'failed', 'Job' => '',
            'ControlGroup' => '', 'Description' => 'Orbit workspace operation '.$this->binding,
            'ExecMainCode' => '0', 'ExecMainStatus' => '0', 'Result' => 'resources'];
        $this->write($this->receipt, ['operation' => $this->operation, 'binding' => $this->binding, 'boot' => $this->boot, 'state' => 'running']);
    }

    /** @param array<string, mixed> $value */
    public function write(string $path, array $value): void
    {
        file_put_contents($path, json_encode($value, JSON_THROW_ON_ERROR));
    }

    /** @param list<string> $arguments */
    public function process(array $arguments): Process
    {
        $this->write($this->home.'/unit.json', $this->state);

        return new Process($arguments, $this->home, ['HOME' => $this->home, 'PATH' => $this->home.'/bin:'.getenv('PATH')], timeout: 10);
    }

    public function operation(string $mode): Process
    {
        $arguments = ['python3', '-I', '-c', TaskUnitProgram::read('tasks/workspace-operation.py'), $mode, $this->operation, $this->binding, '0'];
        if ($mode === 'seal') {
            array_push($arguments, json_encode([$this->operation], JSON_THROW_ON_ERROR), '[]');
        } elseif ($mode === 'run') {
            $arguments[] = 'true';
        }

        return $this->process($arguments);
    }

    /** @return array<string, mixed> */
    public function invoke(string $mode): array
    {
        return json_decode($this->operation($mode)->mustRun()->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function __destruct()
    {
        new Filesystem()->deleteDirectory($this->home);
    }
}
