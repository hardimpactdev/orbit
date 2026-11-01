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

it('resolves Metrics write contracts without the retired mutation DTO', function (): void {
    $program = <<<'PY'
        import ast, pathlib, sys
        path = pathlib.Path(sys.argv[1])
        parsed = ast.parse(path.read_text())
        definitions = ast.Module(body=[node for node in parsed.body if isinstance(node, (ast.Import, ast.ImportFrom, ast.FunctionDef)) or isinstance(node, ast.Assign) and any(isinstance(target, ast.Name) and target.id == 'CTRL_DATA_OVERRIDES' for target in node.targets)], type_ignores=[])
        namespace = {'ROOT': path.parent.parent, 'components': {'Operation': {}}, 'gdata': {}, 'RESPONSE_OVERRIDES': {}, 'SDK_RESPONSE_FALLBACK': {}}
        exec(compile(definitions, str(path), 'exec'), namespace)
        for method in ('store', 'destroy', 'enableExporter', 'disableExporter', 'reset'):
            schema, is_list = namespace['data_schema_for']({'action': 'MetricsController@' + method})
            assert schema == {'$ref': '#/components/schemas/Operation'} and not is_list
        PY;
    $process = new Process([StandardLibraryPython::executable(), '-c', $program, dirname(__DIR__, 4).'/bin/docs-openapi']);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
});
