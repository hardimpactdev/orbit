<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Tasks;

use App\Infrastructure\Tasks\TaskUnitProgram;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Large;
use Symfony\Component\Process\Process;
use Tests\Feature\GitHub\GitHubTestSupport;
use Tests\FeatureTestCase;
use Tests\Support\LocalShellSshExecutor;
use Tests\Support\StandardLibraryPython;

require_once __DIR__.'/../../../Support/TaskCheckRunnerFixtures.php';
require_once __DIR__.'/../../../Support/TaskCheckAuthenticationFixture.php';

#[Large] // Real Tasks deadlines, the five-second TERM grace, and bounded pipe draining are the proof.
final class TaskCheckDeadlineTest extends FeatureTestCase
{
    public string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = check_runner_directory();
    }

    protected function tearDown(): void
    {
        try {
            File::deleteDirectory($this->directory);
        } finally {
            parent::tearDown();
        }
    }

    public function test_redacts_split_credentials_on_timeout(): void
    {
        check_runner_authentication('sleep 20', 124, $this->directory);
    }

    #[DataProvider('setup_script_cases')]
    public function test_removes_setup_scripts(string $command, int $exit): void
    {
        $checkout = check_runner_checkout('touch check-ran', directory: $this->directory);
        $instance = check_runner_instance($checkout);
        $runner = check_runner(new LocalShellSshExecutor, directory: $this->directory);

        $reading = check_runner_wait($runner, $instance, $runner->start($instance, 'composer check', [
            ['name' => 'Stop', 'command' => 'mkdir -p ignored; printf "%s" "$0" > ignored/setup-script; '.$command, 'timeout_seconds' => 1],
            ['name' => 'Never', 'command' => 'touch never-ran', 'timeout_seconds' => 10],
        ]));

        expect($reading->exitCode)->toBe($exit)
            ->and($reading->failedStep)->toBe('Stop')
            ->and(file_exists(file_get_contents($checkout.'/ignored/setup-script')))->toBeFalse()
            ->and(file_exists($checkout.'/never-ran'))->toBeFalse()
            ->and(file_exists($checkout.'/check-ran'))->toBeFalse();
    }

    public static function setup_script_cases(): array
    {
        return [
            ['sleep 10', 124],
            ['exec >/dev/null 2>&1; sleep 10', 124],
        ];
    }

    public function test_kills_a_timed_out_base_process_group(): void
    {
        $harness = $this->directory.'/base-timeout.py';
        file_put_contents($harness, <<<'PYTHON'
    import importlib.machinery
    import importlib.util
    import json
    import os
    import sys
    import time

    loader = importlib.machinery.SourceFileLoader('task_check', sys.argv[1])
    module = importlib.util.module_from_spec(importlib.util.spec_from_loader('task_check', loader))
    loader.exec_module(module)
    workdir = sys.argv[2]
    group_file = os.path.join(workdir, 'process-group')
    log_path = os.path.join(workdir, 'check.log')
    command = f'echo $$ > {group_file}; trap "exit 0" TERM; (trap "" TERM; echo child-ready; exec sleep 30) & wait'
    with open(log_path, 'wb') as log:
        code, output, timed_out = module.run_base(command, workdir, log, 0.5)
    process_group = int(open(group_file).read())
    deadline = time.monotonic() + 2
    while module.process_group_exists(process_group) and time.monotonic() < deadline:
        time.sleep(0.05)
    print(json.dumps({
    'code': code,
    'timed_out': timed_out,
    'output': output,
    'log': open(log_path, 'rb').read().decode(),
    'group_exists': module.process_group_exists(process_group),
    }))
    PYTHON);
        $script = $this->directory.'/check.py';
        file_put_contents($script, TaskUnitProgram::read('tasks/check'));
        $probe = new Process(['python3', $harness, $script, $this->directory], base_path());
        $result = json_decode($probe->mustRun()->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        expect($result['code'])->toBeNull()
            ->and($result['timed_out'])->toBeTrue()
            ->and($result['output'])->toContain('child-ready')
            ->and($result['log'])->toContain('child-ready')
            ->and($result['log'])->toContain('Base command timed out after 0.5 seconds.')
            ->and($result['group_exists'])->toBeFalse();
    }

    #[DataProvider('descendant_cases')]
    public function test_streams_setup_and_stops_descendants(bool $authenticated, bool $cancel): void
    {
        Http::preventStrayRequests();
        if ($authenticated) {
            GitHubTestSupport::storeApp();
            Http::fake([
                'https://api.github.com/repos/acme/shop/installation' => Http::response(['id' => 9]),
                'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_setup_sentinel'], 201),
            ]);
        }
        $checkout = check_runner_checkout('true', directory: $this->directory);
        $instance = check_runner_instance($checkout);
        $runner = check_runner(new LocalShellSshExecutor, directory: $this->directory);
        $process = $runner->start($instance, 'true', [[
            'name' => 'waiting',
            'command' => 'mkdir -p ignored; sleep 30 & echo $! > ignored/setup-child; printf "streamed-output:%08000d" 0; wait',
            'timeout_seconds' => $cancel ? 20 : 2,
        ]]);
        try {
            $log = $checkout.'/.git/orbit/check.log';
            $deadline = microtime(true) + 10;
            do {
                usleep(50000);
                $output = is_file($log) ? file_get_contents($log) : '';
            } while (! str_contains($output, 'streamed-output:000') && microtime(true) < $deadline);

            expect($output)->toContain('streamed-output:000');
            expect($runner->read($instance, $process)->state)->toBe('running');
            $child = (int) file_get_contents($checkout.'/ignored/setup-child');
            if ($cancel) {
                $runner->cancel($instance, $process);
                expect(check_runner_wait($runner, $instance, $process)->state)->toBe('lost');
            } else {
                expect(check_runner_wait($runner, $instance, $process)->exitCode)->toBe(124);
            }
            $probe = new Process([StandardLibraryPython::executable(), '-c', <<<'PYTHON'
                import pathlib, sys
                stat = pathlib.Path('/proc/' + sys.argv[1] + '/stat')
                assert not stat.exists() or stat.read_text().rsplit(')', 1)[1].split()[0] == 'Z'
                PYTHON, (string) $child]);
            expect($probe->run())->toBe(0, $probe->getErrorOutput());
        } finally {
            $runner->cancel($instance, $process);
        }
    }

    public static function descendant_cases(): array
    {
        return [
            [false, false],
            [false, true],
            [true, false],
            [true, true],
        ];
    }

    #[DataProvider('drain_cases')]
    public function test_bounds_output_draining(?int $timeout): void
    {
        $checkout = check_runner_checkout('touch check-ran', directory: $this->directory);
        $instance = check_runner_instance($checkout);
        $runner = check_runner(new LocalShellSshExecutor, directory: $this->directory);
        $command = <<<'BASH'
            mkdir -p ignored
            python3 - <<'PY'
            import pathlib, subprocess, sys
            child = subprocess.Popen([sys.executable, '-S', '-c', 'import time; time.sleep(30)'], start_new_session=True)
            pathlib.Path('ignored/escaped-child').write_text(str(child.pid))
            PY
            BASH;
        $started = microtime(true);
        $process = $runner->start($instance, 'touch check-ran', [
            ['name' => 'escaped', 'command' => $command, 'timeout_seconds' => $timeout],
            ['name' => 'never', 'command' => 'touch never-ran', 'timeout_seconds' => 10],
        ]);
        try {
            $reading = check_runner_wait($runner, $instance, $process);

            expect($reading->exitCode)->toBe(124)
                ->and($reading->failedStep)->toBe('escaped')
                ->and($reading->output)->toContain('output did not close')
                ->and(microtime(true) - $started)->toBeLessThan(10)
                ->and(file_exists($checkout.'/never-ran'))->toBeFalse()
                ->and(file_exists($checkout.'/check-ran'))->toBeFalse();
        } finally {
            $runner->cancel($instance, $process);
            if (is_file($checkout.'/ignored/escaped-child')) {
                new Process(['kill', '-TERM', trim(file_get_contents($checkout.'/ignored/escaped-child'))])->run();
            }
        }
    }

    public static function drain_cases(): array
    {
        return [
            [30],
            [null],
        ];
    }
}
