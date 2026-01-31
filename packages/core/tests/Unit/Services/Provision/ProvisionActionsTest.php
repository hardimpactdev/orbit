<?php

declare(strict_types=1);

use HardImpact\Orbit\Core\Data\ProvisionContext;
use HardImpact\Orbit\Core\Services\Provision\Actions\SetPhpVersion;
use HardImpact\Orbit\Core\Services\Provision\ProvisionLogger;

describe('SetPhpVersion', function () {
    it('writes .php-version file with specified version', function () {
        $projectDir = sys_get_temp_dir().'/php-version-test-'.uniqid();
        mkdir($projectDir, 0755, true);

        $context = new ProvisionContext(
            slug: 'php-test',
            projectPath: $projectDir,
            phpVersion: '8.4',
        );

        $logger = new ProvisionLogger('php-test');
        $action = new SetPhpVersion;
        $result = $action->handle($context, $logger);

        expect($result->isSuccess())->toBeTrue();
        expect(file_exists("{$projectDir}/.php-version"))->toBeTrue();
        expect(trim(file_get_contents("{$projectDir}/.php-version")))->toBe('8.4');

        // Cleanup
        @unlink("{$projectDir}/.php-version");
        @rmdir($projectDir);
    });

    it('uses default version when not specified', function () {
        $projectDir = sys_get_temp_dir().'/php-default-test-'.uniqid();
        mkdir($projectDir, 0755, true);

        $context = new ProvisionContext(
            slug: 'php-default-test',
            projectPath: $projectDir,
        );

        $logger = new ProvisionLogger('php-default-test');
        $action = new SetPhpVersion;
        $result = $action->handle($context, $logger);

        expect($result->isSuccess())->toBeTrue();
        expect(file_exists("{$projectDir}/.php-version"))->toBeTrue();

        // Cleanup
        @unlink("{$projectDir}/.php-version");
        @rmdir($projectDir);
    });

    it('overwrites existing .php-version file', function () {
        $projectDir = sys_get_temp_dir().'/php-overwrite-test-'.uniqid();
        mkdir($projectDir, 0755, true);
        file_put_contents("{$projectDir}/.php-version", '7.4');

        $context = new ProvisionContext(
            slug: 'php-overwrite-test',
            projectPath: $projectDir,
            phpVersion: '8.4',
        );

        $logger = new ProvisionLogger('php-overwrite-test');
        $action = new SetPhpVersion;
        $result = $action->handle($context, $logger);

        expect($result->isSuccess())->toBeTrue();
        expect(trim(file_get_contents("{$projectDir}/.php-version")))->toBe('8.4');

        // Cleanup
        @unlink("{$projectDir}/.php-version");
        @rmdir($projectDir);
    });
});
