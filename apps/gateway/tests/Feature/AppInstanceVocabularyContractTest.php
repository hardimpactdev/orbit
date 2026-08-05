<?php

declare(strict_types=1);

use App\Services\Nodes\Access\NodePermissionNormalizer;
use App\Services\Nodes\Access\NodePermissionRegistry;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;

it('registers apps and instances as the canonical workload resources', function (): void {
    $routeUris = collect(Route::getRoutes())
        ->map(fn (LaravelRoute $route): string => $route->uri())
        ->filter(fn (string $uri): bool => str_starts_with($uri, 'api/'))
        ->values();

    expect($routeUris)
        ->toContain('api/apps', 'api/instances')
        ->not->toContain('api/projects');

    $projectWorkloadRoutes = $routeUris
        ->filter(fn (string $uri): bool => (bool) preg_match('#^api/projects(/|$)#', $uri))
        ->values();

    expect($projectWorkloadRoutes)->toBeEmpty();
});

it('registers app and instance permissions without project workload tokens', function (): void {
    $permissions = app(NodePermissionRegistry::class)->all();

    expect($permissions)
        ->toContain(
            'app:*',
            'app:read',
            'app:write',
            'app:list',
            'app:show',
            'app:new',
            'app:remove',
            'instance:*',
            'instance:read',
            'instance:write',
            'instance:register',
            'instance:credentials',
            'instance:mount',
            'solo:project:list',
            'codex:app',
        )
        ->not->toContain(
            'project:*',
            'project:read',
            'project:write',
            'project:list',
            'project:show',
            'project:new',
            'project:remove',
            'project:register',
            'project:credentials',
            'project:mount',
        );

    expect(fn () => app(NodePermissionNormalizer::class)->normalize(['project:read']))
        ->toThrow(InvalidArgumentException::class, 'Unknown permission [project:read].');
});

it('keeps agent-facing Orbit skill references on app and instance vocabulary', function (): void {
    $paths = [
        '.agents/skills/orbit/SKILL.md',
        '.agents/skills/orbit/references/app.md',
        '.agents/skills/orbit/references/concepts.md',
        'apps/docs/content/domains/authorization-matrix.md',
    ];

    $content = collect($paths)
        ->map(fn (string $path): string => (string) file_get_contents(repo_path($path)))
        ->implode("\n");

    expect($content)
        ->toContain('app:new', 'app:list', 'instance:list', 'instance:setup')
        ->toContain('--app=')
        ->toContain('domains/5_app')
        ->and(
            str_contains($content, 'App → Instance → Workspace')
            || str_contains($content, 'App -> Instance -> Workspace'),
        )
        ->toBeTrue()
        ->and($content)
        ->not->toMatch('/\bproject:(?:new|list|show|remove|read|write)\b/')
        ->not->toContain('/api/projects')
        ->not->toContain('domains/5_project')
        ->not->toMatch('/Project\s*->\s*Instance\s*->\s*Workspace/')
        ->not->toContain('[--project=');
});

/**
 * Active CLI human headers and value-taking filters must use App vocabulary.
 * Solo/Codex external project contracts are guarded separately.
 */
it('keeps active CLI app and instance command surfaces on App vocabulary', function (): void {
    $cliRoot = dirname(base_path()).'/cli';
    if (! is_dir($cliRoot)) {
        $cliRoot = base_path().'/../cli';
    }
    $cliRoot = realpath($cliRoot) ?: $cliRoot;

    $instanceList = (string) file_get_contents($cliRoot.'/app/Commands/App/InstanceListCommand.php');
    $appShow = (string) file_get_contents($cliRoot.'/app/Commands/App/AppShowCommand.php');
    $prompts = (string) file_get_contents($cliRoot.'/app/Commands/Concerns/PromptsForGatewayRegistryEntities.php');

    expect($instanceList)
        ->toContain('{--app= : Limit results to one app}')
        ->toContain("'APP'")
        ->not->toContain('{--app : Limit results to one app}')
        ->not->toContain("'PROJECT'")
        ->not->toContain('{--project');

    expect($appShow)
        ->toContain("'APP DEPS'")
        ->toContain('promptForVisibleApp')
        ->not->toContain("'PROJECT DEPS'")
        ->not->toContain('promptForVisibleProject');

    expect($prompts)
        ->toContain('function promptForVisibleApp')
        ->toContain("headers: ['App', 'Host', 'Node', 'Repository']")
        ->not->toContain('function promptForVisibleProject')
        ->not->toContain("headers: ['Project', 'Host', 'Node', 'Repository']");
});

/**
 * macOS node-detail workload tables must use App headers, not Project.
 */
it('keeps macOS node detail workload table headers on App vocabulary', function (): void {
    $macosMain = repo_path('apps/macos/frontend/src/main.ts');
    $source = (string) file_get_contents($macosMain);

    expect($source)
        ->toContain("['Process', 'App', 'Runtime', 'Status']")
        ->toContain("['App', 'Instance', 'Environment', 'Status']")
        ->not->toContain("['Process', 'Project', 'Runtime', 'Status']")
        ->not->toContain("['Project', 'Instance', 'Environment', 'Status']");
});

it('rejects active AppInstance model class and app_instances schema names in runtime models', function (): void {
    expect(class_exists(App\Models\AppInstance::class, false))
        ->toBeFalse()
        ->and(class_exists(App\Models\Project::class, false))
        ->toBeFalse()
        ->and(class_exists(App\Models\App::class))
        ->toBeTrue()
        ->and(class_exists(App\Models\Instance::class))
        ->toBeTrue()
        ->and(new App\Models\App()->getTable())
        ->toBe('apps')
        ->and(new App\Models\Instance()->getTable())
        ->toBe('instances');
});

it('does not load a runtime activity project-key translator', function (): void {
    expect(file_exists(app_path('Services/Activity/ActivityPayloadCompatibility.php')))
        ->toBeFalse()
        ->and(file_exists(app_path('Services/Activity/ActivityPayloadFormatter.php')))
        ->toBeTrue();
});

/**
 * Active code must not retain AppInstance / app_instances / app_instance_id.
 * Historical pre-2026-08-05 migrations and their dedicated schema/migration tests
 * intentionally keep those tokens to prove the immutable historical step.
 */
it('rejects residual AppInstance schema tokens outside historical exclusions', function (): void {
    $gatewayRoot = base_path();
    $repoRoot = dirname($gatewayRoot);
    $hits = [];

    $excludedExact = array_fill_keys(
        [
            // Historical pre-cutover migration tests (prove app_instances schema steps).
            'tests/Feature/Migrations/AddDockerFirstRuntimeFieldsBackfillTest.php',
            'tests/Feature/Migrations/AddProcessLabelBackfillTest.php',
            'tests/Feature/Migrations/CanonicalizeAppRuntimeInstanceOwnershipTest.php',
            'tests/Feature/Migrations/CanonicalizeProcessAppInstanceOwnershipTest.php',
            'tests/Feature/Migrations/CanonicalizeScheduleAppInstanceOwnershipTest.php',
            'tests/Feature/Migrations/MoveAdoptedStateToAppInstancesTest.php',
            'tests/Feature/Migrations/RestrictWorkspacesToAppDevelopmentNodesTest.php',
            'tests/Feature/Database/AppWebSocketBindingSchemaTest.php',
            'tests/Feature/Database/CanonicalAppInstanceOwnershipSchemaTest.php',
            'tests/Feature/Database/CanonicalDeploymentOwnershipSchemaTest.php',
            'tests/Feature/Database/DatabaseConnectionSchemaTest.php',
            'tests/Feature/Database/ProcessRuntimeScopeSchemaTest.php',
            'tests/Feature/Database/WorkspaceEnvOwnershipMigrationTest.php',
            // New cutover migration tests intentionally seed pre-state then assert final schema.
            'tests/Feature/Migrations/RenameAppInstancesToInstancesSchemaTest.php',
            'tests/Feature/Migrations/RewriteActivityLogWorkloadPropertiesTest.php',
            'tests/Feature/MigrateAppInstanceSchemaAndProjectPermissionsTest.php',
            // This guard file documents residual tokens in exclusion prose and expectations.
            'tests/Feature/AppInstanceVocabularyContractTest.php',
            // Negative schema assertion mentions app_instances by design.
            'tests/Feature/AppLogicalStorageSchemaTest.php',
            // Historical ADE re-run presents app_instances table name to immutable migration.
            'tests/Feature/Removal/AdeOpenCodePolyscopeRemovalTest.php',
            // Cutover migration rewrites app_instances → instances; tokens appear by design.
            'database/migrations/2026_08_05_120000_rename_app_instances_to_instances_and_project_permissions_to_app.php',
            // Migration-only helper retained for immutable pre-cutover migrations (not a runtime alias).
            'app/Data/Apps/AppInstanceRuntimeRequirementsData.php',
        ],
        true,
    );

    $scanRoots = [
        $gatewayRoot.'/app',
        $gatewayRoot.'/database',
        $gatewayRoot.'/routes',
        $gatewayRoot.'/tests',
    ];

    foreach ($scanRoots as $root) {
        if (! is_dir($root)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $absolute = $file->getPathname();
            $relative = str_replace($gatewayRoot.'/', '', $absolute);

            if (isset($excludedExact[$relative])) {
                continue;
            }

            // Historical migrations keep app_instances / app_instance_id by design.
            if (str_starts_with($relative, 'database/migrations/')) {
                $basename = basename($relative);
                if (
                    $basename
                        !== '2026_08_05_120000_rename_app_instances_to_instances_and_project_permissions_to_app.php'
                    && preg_match('/^2026_\d{2}_\d{2}_/', $basename) === 1
                    && $basename < '2026_08_05_120000_'
                ) {
                    continue;
                }
            }

            $contents = (string) file_get_contents($absolute);

            if (
                str_contains($contents, 'AppInstance')
                || str_contains($contents, 'app_instances')
                || str_contains($contents, 'app_instance_id')
            ) {
                $hits[] = $relative;
            }
        }
    }

    expect($hits)->toBeEmpty();
});

/**
 * AppInstanceRuntimeRequirementsData is migration-only. Runtime must use
 * InstanceRuntimeRequirementsData; historical migrations keep the legacy import.
 */
it('limits AppInstanceRuntimeRequirementsData to historical migration imports', function (): void {
    $gatewayRoot = base_path();
    $allowed = [
        'app/Data/Apps/AppInstanceRuntimeRequirementsData.php',
        'database/migrations/2026_06_17_201539_create_app_instances_table.php',
        'database/migrations/2026_07_12_084244_canonicalize_app_instance_ownership.php',
        'tests/Feature/AppInstanceVocabularyContractTest.php',
    ];
    $hits = [];

    $scanRoots = [
        $gatewayRoot.'/app',
        $gatewayRoot.'/database',
        $gatewayRoot.'/routes',
        $gatewayRoot.'/tests',
    ];

    foreach ($scanRoots as $root) {
        if (! is_dir($root)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace($gatewayRoot.'/', '', $file->getPathname());
            if (in_array($relative, $allowed, true)) {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());
            if (str_contains($contents, 'AppInstanceRuntimeRequirementsData')) {
                $hits[] = $relative;
            }
        }
    }

    expect($hits)
        ->toBeEmpty()
        ->and(class_exists(\App\Data\Apps\AppInstanceRuntimeRequirementsData::class))
        ->toBeTrue()
        ->and(class_exists(\App\Data\Apps\InstanceRuntimeRequirementsData::class))
        ->toBeTrue()
        ->and(\App\Data\Apps\AppInstanceRuntimeRequirementsData::class)
        ->not->toBe(\App\Data\Apps\InstanceRuntimeRequirementsData::class);

    $createMigration = (string) file_get_contents(
        database_path('migrations/2026_06_17_201539_create_app_instances_table.php'),
    );
    $canonicalMigration = (string) file_get_contents(
        database_path('migrations/2026_07_12_084244_canonicalize_app_instance_ownership.php'),
    );

    expect($createMigration)
        ->toContain('use App\\Data\\Apps\\AppInstanceRuntimeRequirementsData;')
        ->and($canonicalMigration)
        ->toContain('use App\\Data\\Apps\\AppInstanceRuntimeRequirementsData;')
        ->and(file_get_contents(app_path('Models/Instance.php')))
        ->toContain('InstanceRuntimeRequirementsData')
        ->not->toContain('AppInstanceRuntimeRequirementsData');
});
