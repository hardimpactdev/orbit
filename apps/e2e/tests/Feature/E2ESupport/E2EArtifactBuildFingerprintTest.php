<?php

declare(strict_types=1);

it('includes the SDK package in CLI and gateway artifact build fingerprints', function (): void {
    $source = file_get_contents(app_path('E2E/Support/E2EArtifactBuildFingerprint.php'));

    expect($source)
        ->toContain("'packages/sdk'")
        ->toContain("'packages/sdk/composer.json'")
        ->toContain("'packages/sdk/composer.lock'")
        ->toContain("'packages/sdk/src'")
        ->toContain("'packages/sdk/vendor'");
});

it('does not fingerprint the removed gateway node-scripts packaging path', function (): void {
    $artifact = (string) file_get_contents(app_path('E2E/Support/E2EArtifactBuildFingerprint.php'));
    $provision = (string) file_get_contents(app_path('E2E/Support/E2EProvisionFingerprint.php'));

    expect($artifact)
        ->not->toContain('apps/gateway/resources/node-scripts')->and($provision)
        ->not->toContain('apps/gateway/resources/node-scripts');
});
