<?php

declare(strict_types=1);

use App\Domain\RemotePrograms\RemoteProgramLanguage;
use App\Infrastructure\RemotePrograms\RemoteProgramCatalog;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

function remote_program_fixture(string $directory, string $path, string $contents): void
{
    File::ensureDirectoryExists(dirname("{$directory}/{$path}"));
    file_put_contents("{$directory}/{$path}", $contents);
}

describe('RemoteProgramCatalog', function (): void {
    beforeEach(function (): void {
        $this->directory = sys_get_temp_dir().'/orbit-remote-programs-'.bin2hex(random_bytes(6));
        remote_program_fixture($this->directory, 'lib/first.py', "FIRST = 1\n");
        remote_program_fixture($this->directory, 'lib/second.py', 'SECOND = 2');
        remote_program_fixture($this->directory, 'demo/run.py', "# Program: demo.run\n# Uses: lib/first.py lib/second.py\n# Outcomes: done refused\n# Timeout: 30\nrun_program(main)\n");
        remote_program_fixture($this->directory, 'demo/check.sh', "# Program: demo.check\n# Timeout: 5\ntest -d \"\$1\"\n");
        $this->catalog = new RemoteProgramCatalog($this->directory);
    });

    afterEach(function (): void {
        File::deleteDirectory($this->directory);
    });

    it('assembles a program after the libraries its header names, in order', function (): void {
        $program = $this->catalog->program('demo.run');

        expect($program->language)->toBe(RemoteProgramLanguage::Python)
            ->and($program->source)->toBe("FIRST = 1\nSECOND = 2\n# Program: demo.run\n# Uses: lib/first.py lib/second.py\n# Outcomes: done refused\n# Timeout: 30\nrun_program(main)\n")
            ->and($program->outcomes)->toBe(['done', 'refused'])
            ->and($program->timeoutSeconds)->toBe(30)
            ->and($program->digest())->toBe(hash('sha256', $program->source));
    });

    it('gives a Bash program the outcomes succeeded and failed', function (): void {
        $program = $this->catalog->program('demo.check');

        expect($program->language)->toBe(RemoteProgramLanguage::Bash)
            ->and($program->outcomes)->toBe(['succeeded', 'failed']);
    });

    it('embeds the shared unit proof inside a named library', function (): void {
        remote_program_fixture($this->directory, 'lib/first.py', "# ORBIT_TASK_UNIT_STATE\n");
        remote_program_fixture($this->directory, 'demo/run.py', "# Program: demo.run\n# Uses: lib/first.py\n# Outcomes: done\n# Timeout: 30\nprint(vanished({'LoadState': 'not-found', 'ActiveState': 'inactive', 'ControlGroup': '', 'Job': ''}))\n");
        $process = new Process(['python3', '-']);
        $process->setInput($this->catalog->program('demo.run')->source);

        $process->mustRun();

        expect($process->getOutput())->toBe("True\n");
    });

    it('refuses a program whose file does not match its contract', function (string $path, string $contents, string $name): void {
        remote_program_fixture($this->directory, $path, $contents);

        expect(fn () => $this->catalog->program($name))->toThrow(LogicException::class);
    })->with([
        'another name' => ['demo/named.py', "# Program: demo.other\n# Outcomes: done\n# Timeout: 30\n", 'demo.named'],
        'no timeout' => ['demo/untimed.py', "# Program: demo.untimed\n# Outcomes: done\n", 'demo.untimed'],
        'no outcomes' => ['demo/silent.py', "# Program: demo.silent\n# Timeout: 30\n", 'demo.silent'],
        'repeated outcomes' => ['demo/twice.py', "# Program: demo.twice\n# Outcomes: done done\n# Timeout: 30\n", 'demo.twice'],
        'a library outside lib' => ['demo/escape.py', "# Program: demo.escape\n# Uses: ../demo/run.py\n# Outcomes: done\n# Timeout: 30\n", 'demo.escape'],
        'a missing library' => ['demo/missing.py', "# Program: demo.missing\n# Uses: lib/absent.py\n# Outcomes: done\n# Timeout: 30\n", 'demo.missing'],
        'a Bash library for Python' => ['demo/mixed.py', "# Program: demo.mixed\n# Uses: lib/first.sh\n# Outcomes: done\n# Timeout: 30\n", 'demo.mixed'],
        'Bash outcomes' => ['demo/bash.sh', "# Program: demo.bash\n# Outcomes: done\n# Timeout: 30\n", 'demo.bash'],
        'a library as a program' => ['lib/main.py', "# Program: lib.main\n# Outcomes: done\n# Timeout: 30\n", 'lib.main'],
        'an unknown name' => ['demo/run.py', "# Program: demo.run\n# Outcomes: done\n# Timeout: 30\n", 'demo.Run'],
        'a name with a path' => ['demo/run.py', "# Program: demo.run\n# Outcomes: done\n# Timeout: 30\n", 'demo.run/../x'],
    ]);

    it('loads every Gateway program with a valid header', function (): void {
        $catalog = new RemoteProgramCatalog;
        $names = [];

        foreach (glob(resource_path('remote').'/*/*.{py,sh}', GLOB_BRACE) ?: [] as $path) {
            if (basename(dirname($path)) !== 'lib') {
                $names[] = basename(dirname($path)).'.'.pathinfo($path, PATHINFO_FILENAME);
            }
        }

        expect($names)->toBe(['gateway.task-workspace-owner']);
        foreach ($names as $name) {
            expect($catalog->program($name)->name)->toBe($name);
        }
        expect($catalog->bootstrap())->toContain('sys.argv[1]');
    });
});
