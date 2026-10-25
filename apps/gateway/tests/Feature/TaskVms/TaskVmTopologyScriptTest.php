<?php

declare(strict_types=1);

use App\Infrastructure\Tasks\TaskUnitProgram;
use Symfony\Component\Process\Process;

it('lints the exact assembled program that the Gateway sends', function (string $resource): void {
    $probe = new Process(['python3', '-c', <<<'PYTHON'
import importlib.machinery, importlib.util, pathlib, sys
loader = importlib.machinery.SourceFileLoader('check', sys.argv[1])
check = importlib.util.module_from_spec(importlib.util.spec_from_loader('check', loader))
loader.exec_module(check)
sys.stdout.write(check.assemble(check.parts(pathlib.Path(sys.argv[2])))[0])
PYTHON, base_path('../../bin/remote-programs-check'), resource_path($resource)]);

    expect($probe->mustRun()->getOutput())->toBe(TaskUnitProgram::read($resource));
})->with(['tasks/check', 'tasks/workspace-operation.py']);
