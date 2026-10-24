<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Tasks\TaskUnitProgram;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Real repository adapter and DocsImpact, with only disposable Git and program-host state. */
final class TaskAnalysisAdapterFixture
{
    public readonly string $checkout;

    private readonly ForkedPythonPrograms $programs;

    /** @var array<string, string> */
    private array $environment = [];

    public function __construct(string $mode)
    {
        $this->checkout = sys_get_temp_dir().'/orbit-analysis-adapter-'.bin2hex(random_bytes(6));
        $this->programs = new ForkedPythonPrograms;
        $root = dirname(base_path(), 2);
        foreach (['bin/task-analyze', 'apps/gateway/app/Support/StrictJson.php', 'apps/gateway/app/Domain/Tasks/TaskAnalysisPlan.php', 'apps/gateway/app/Domain/Tasks/TaskAnalysisReport.php'] as $path) {
            File::ensureDirectoryExists(dirname($this->checkout.'/'.$path));
            File::copy($root.'/'.$path, $this->checkout.'/'.$path);
        }
        chmod($this->checkout.'/bin/task-analyze', 0755);
        File::ensureDirectoryExists($this->checkout.'/apps/docs/vendor');
        $autoload = '<?php';
        foreach (['apps/gateway/vendor/autoload.php', 'apps/docs/app/Documentation/DocsImpact.php', 'apps/docs/app/Documentation/DocsImpactReport.php'] as $path) {
            $autoload .= ' require '.var_export($root.'/'.$path, true).';';
        }
        file_put_contents($this->checkout.'/apps/docs/vendor/autoload.php', $autoload);
        File::ensureDirectoryExists($this->checkout.'/docs/reference');
        file_put_contents($this->checkout.'/docs/reference/test.md', "---\ntitle: Test\n---\nTest\n");
        foreach ([['init', '--quiet'], ['add', '.'], ['-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '--quiet', '-m', 'real adapter']] as $arguments) {
            new Process(['git', ...$arguments], $this->checkout)->mustRun();
        }
        if ($mode === 'overflow') {
            for ($index = 0; $index < 700; $index++) {
                file_put_contents($this->checkout.'/'.sprintf('%04d', $index).str_repeat('x', 96).'.txt', 'x');
            }
        }
        if ($mode === 'missing-tooling') {
            unlink($this->checkout.'/apps/docs/vendor/autoload.php');
        }
        if ($mode === 'missing-scanner') {
            file_put_contents($this->checkout.'/apps/docs/vendor/autoload.php', '<?php');
        }
        // CI installs only Gateway dependencies. Deny Docs vendor access even in a fully bootstrapped checkout.
        // Only the adapter's PHP loses tooling; the existing Python supervisor keeps its real Git.
        $tools = $this->checkout.'/.git/adapter-tools';
        File::ensureDirectoryExists($tools);
        $path = $mode === 'missing-git' ? 'export PATH='.escapeshellarg($tools)."\n" : '';
        $allowed = $this->checkout.PATH_SEPARATOR.$root.'/apps/gateway'.PATH_SEPARATOR.$root.'/apps/docs/app'.PATH_SEPARATOR.'/dev/null';
        $flags = '-d '.escapeshellarg('open_basedir='.$allowed).' ';
        if ($mode === 'missing-proc-open') {
            $flags .= '-d disable_functions=proc_open ';
        }
        file_put_contents($tools.'/php', "#!/bin/sh\n".$path.'exec '.escapeshellarg(PHP_BINARY).' '.$flags.'"$@"'."\n");
        chmod($tools.'/php', 0755);
        $this->environment = ['PATH' => $tools.':'.getenv('PATH')];
    }

    /** @param array<string, mixed>|null $input
     * @return array<string, mixed>
     */
    public function capture(?array $input = null): array
    {
        $bootstrap = <<<'PYTHON'
            import io, json, os, sys
            source, options = sys.stdin.buffer.read().split(b'\0', 1)
            options = json.loads(options)
            scope = {'__name__': 'check', '__file__': options['checkout'] + '/.git/orbit/check'}
            exec(compile(source, sys.argv[1], 'exec'), scope)
            os.chdir(options['checkout'])
            request = options['input']
            if request is None:
                print(json.dumps({'head': scope['git'](options['checkout'], 'rev-parse', 'HEAD'),
                                  'tree': scope['working_tree'](options['checkout'])}))
            else:
                lock, path, _ = scope['start_record'](request['execution_id'])
                scope['write_start_record'](path, {'run_id': request['execution_id'], 'state': 'starting', 'starter_pid': os.getpid(),
                    'starter_started': scope['process_started'](os.getpid()), 'boot': open('/proc/sys/kernel/random/boot_id').read().strip()})
                lock.close()
                sys.stdin = io.TextIOWrapper(io.BytesIO(json.dumps(request).encode()))
                scope['main'](['run-owned', request['execution_id'], options['checkout'], request['head'], request['tree'], '-', '-', '', 'analysis'])
                with open(scope['RESULT']) as result:
                    print(result.read())
            PYTHON;
        $capture = $this->programs->run($bootstrap, 'real-analysis-adapter', TaskUnitProgram::read('tasks/check')."\0".json_encode(['checkout' => $this->checkout, 'input' => $input], JSON_THROW_ON_ERROR), $this->environment);
        if ($capture->exitCode !== 0) {
            throw new RuntimeException($capture->stderr);
        }

        return json_decode($capture->stdout, true, flags: JSON_THROW_ON_ERROR);
    }

    public function close(): void
    {
        $this->programs->close();
        File::deleteDirectory($this->checkout);
    }
}
