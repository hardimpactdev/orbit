<?php

declare(strict_types=1);

/**
 * ProjectInstancePermissionMigrator exists only for the immutable
 * 2026-07-20 node_access migration. Runtime grant paths must not call it.
 */
it('limits ProjectInstancePermissionMigrator to the historical 2026-07-20 migration import', function (): void {
    $gatewayRoot = base_path();
    $runtimeHits = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($gatewayRoot.'/app', FilesystemIterator::SKIP_DOTS),
    );

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $path = $file->getPathname();
        $relative = str_replace($gatewayRoot.'/', '', $path);

        if ($relative === 'app/Services/Nodes/Access/ProjectInstancePermissionMigrator.php') {
            continue;
        }

        $contents = (string) file_get_contents($path);

        if (str_contains($contents, 'ProjectInstancePermissionMigrator')) {
            $runtimeHits[] = $relative;
        }
    }

    expect($runtimeHits)->toBeEmpty();

    $historicalMigration = (string) file_get_contents(
        database_path('migrations/2026_07_20_080355_add_project_instance_permissions_to_node_access_grants.php'),
    );

    expect($historicalMigration)
        ->toContain('use App\\Services\\Nodes\\Access\\ProjectInstancePermissionMigrator;')
        ->toContain('new ProjectInstancePermissionMigrator');

    expect(class_exists(\App\Services\Activity\ActivityPayloadCompatibility::class, false))
        ->toBeFalse()
        ->and(file_exists(app_path('Services/Activity/ActivityPayloadCompatibility.php')))
        ->toBeFalse()
        ->and(file_exists(app_path('Services/Activity/ActivityPayloadFormatter.php')))
        ->toBeTrue();
});
