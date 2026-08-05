<?php

declare(strict_types=1);

/**
 * Guards Codex App and Solo external project contracts against bulk Orbit
 * vocabulary renames. Only the internal Orbit model type may be App; public
 * Codex/Solo project routes, payloads, error meta, and fixtures must remain project.
 */

it('keeps Codex App controller Codex-facing project vocabulary byte-stable', function (): void {
    $path = app_path('Http/Controllers/Api/CodexAppController.php');
    $source = (string) file_get_contents($path);

    expect($source)
        ->toContain('use App\\Models\\App;')
        ->not->toContain('use App\\Models\\Project;')->toContain(
            "return \$this->error('project.not_found', \"Project '{\$project}' not found.\", ['project' => \$project], 404);",
        )->toContain('private function projectPayload(App $app): array')->toContain(
            'private function projectPayloadFromConfig(array $connection, array $project): array',
        )->toContain("'project' => \$app->name,")->toContain("'project' => \$label,")->toContain(
            "'codex_project' => [",
        )->toContain("'codex_projects' => \$projects,")
        ->not->toContain("['app' => \$project], 404)")
        ->not->toMatch("/'app'\\s*=>\\s*\\\$app->name/")
        ->not->toMatch("/@return array\\{app: string/");
});

it('keeps Solo proxy controller Solo project list route and method names', function (): void {
    $path = app_path('Http/Controllers/Api/SoloProxyController.php');
    $source = (string) file_get_contents($path);

    expect($source)
        ->toContain('public function projects(')
        ->toContain("\$this->operation = 'projects';")
        ->toContain("requiredDataKey: 'projects'")
        ->toContain("'projects' => 'solo.projects.listed'")
        ->not->toContain('public function apps(')
        ->not->toContain("\$this->operation = 'apps';")
        ->not->toContain("requiredDataKey: 'apps'");
});

it('keeps Solo proxy routes on /api/solo/projects not /api/solo/apps', function (): void {
    $path = base_path('routes/api.php');
    $source = (string) file_get_contents($path);

    expect($source)
        ->toContain("Route::get('/solo/projects', [SoloProxyController::class, 'projects']);")
        ->not->toContain("Route::get('/solo/apps', [SoloProxyController::class, 'apps']);")->toContain(
            "Route::post('/codex/apps/{project}', [CodexAppController::class, 'add']);",
        )->toContain("Route::delete('/codex/apps/{project}', [CodexAppController::class, 'remove']);")
        ->not->toContain("Route::post('/codex/apps/{app}', [CodexAppController::class, 'add']);");
});

it('keeps Solo and Codex feature fixtures on project payload keys', function (): void {
    $solo = (string) file_get_contents(base_path('tests/Feature/Http/Api/SoloProxyControllerTest.php'));
    $codex = (string) file_get_contents(base_path('tests/Feature/Http/Api/CodexAppControllerTest.php'));

    expect($solo)
        ->toContain("'project' => 'orbit'")
        ->toContain("'project' => '4'")
        ->toContain("'/projects/orbit/status'")
        ->not->toContain("'app' => 'orbit'")
        ->not->toContain("'app' => '4',");

    expect($codex)
        ->toContain("assertJsonPath('success.data.codex_project.project', 'docs')")
        ->toContain('App::factory()')
        ->not->toContain('Project::factory()')
        ->not->toContain("assertJsonPath('success.data.codex_project.app'");
});

it('keeps CLI Solo and Codex command catalogs on external project vocabulary', function (): void {
    $cliRoot = dirname(base_path()).'/cli';
    if (! is_dir($cliRoot)) {
        $cliRoot = base_path().'/../cli';
    }
    $cliRoot = realpath($cliRoot) ?: $cliRoot;

    $soloRead = (string) file_get_contents($cliRoot.'/app/Commands/Solo/SoloReadOperationCatalog.php');
    $codexCmd = (string) file_get_contents($cliRoot.'/app/Commands/Codex/CodexAppCommand.php');

    expect($soloRead)
        ->toContain("command: 'solo:project:list'")
        ->toContain("gatewayPath: '/api/solo/projects'")
        ->toContain('{--project= : Project id or name}')
        ->not->toContain("gatewayPath: '/api/solo/apps'");

    expect($codexCmd)
        ->toContain('{project? : Project name or hostname}')
        ->toContain("\$this->gatewayGet('/api/codex/projects'")
        ->not->toContain('{app? : App name or hostname}');
});
