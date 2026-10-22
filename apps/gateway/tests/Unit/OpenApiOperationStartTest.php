<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Support\StandardLibraryPython;

it('refuses to generate an unmarked operation start', function (bool $precedingMarker): void {
    $preceding = $precedingMarker ? <<<'PHP'
        #[StartsOperation(OperationKinds::InstanceDeploy)]
        public function previous(): JsonResponse
        {
            return response()->json(['data' => OperationData::fromModel($operation)->toArray()], 202);
        }
        PHP : '';
    $controller = $preceding.<<<'PHP'
        public function store(): JsonResponse
        {
            return response()->json(['data' => OperationData::fromModel($operation)->toArray()], 202);
        }
        PHP;
    $program = <<<'PY'
        import ast, pathlib, sys
        path = pathlib.Path(sys.argv[1])
        source = sys.argv[2]
        # Load the generator's real functions without running its whole catalogue build.
        parsed = ast.parse(path.read_text())
        definitions = ast.Module(body=[node for node in parsed.body if isinstance(node, (ast.Import, ast.ImportFrom, ast.FunctionDef))], type_ignores=[])
        namespace = {'ROOT': path.parent.parent}
        exec(compile(definitions, str(path), 'exec'), namespace)

        class Controller:
            stem = 'FixtureController'
            def read_text(self):
                return source
            def relative_to(self, root):
                return pathlib.Path('fixture/FixtureController.php')

        namespace['gateway_files'] = lambda *args, **kwargs: [Controller()]
        namespace['load_controllers']()
        PY;

    $process = new Process([StandardLibraryPython::executable(), '-c', $program, dirname(__DIR__, 4).'/bin/docs-openapi', $controller]);
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('FixtureController.php::store answers 202 with OperationData but has no #[StartsOperation] marker');
})->with(['no marker' => false, 'only the preceding method is marked' => true]);
