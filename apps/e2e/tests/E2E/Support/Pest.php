<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Shared E2E test helpers
|--------------------------------------------------------------------------
|
| Home for the global helper functions shared across the migrated E2E test
| suites. The topology/provider tests are the first batch to move here; the
| app/node/gateway, resource, and infra/tool/websocket batches will add their
| helpers alongside these as they migrate out of apps/gateway/tests/E2E.
|
*/

if (! function_exists('e2eProvisionKeepsFailures')) {
    function e2eProvisionKeepsFailures(): bool
    {
        $value = getenv('ORBIT_E2E_KEEP_ON_FAILURE');

        return ! is_string($value) || ! in_array(strtolower($value), ['0', 'false', 'no'], true);
    }
}

if (! function_exists('e2eProvisionReportDangling')) {
    /**
     * @param  list<string>  $instanceNames
     */
    function e2eProvisionReportDangling(array $instanceNames): void
    {
        $instanceNames = array_values(array_unique(array_filter($instanceNames)));

        if ($instanceNames === []) {
            fwrite(STDERR, "E2E provision test failed; no tracked instances were available to report.\n");

            return;
        }

        fwrite(STDERR, "E2E provision test failed; keeping instances for inspection:\n");

        foreach ($instanceNames as $instanceName) {
            fwrite(STDERR, "  - {$instanceName}\n");
        }

        fwrite(STDERR, "Reap later with: composer e2e:reap-incus -- --force --older-than=0m\n");
        fwrite(STDERR, "Set ORBIT_E2E_KEEP_ON_FAILURE=0 to restore cleanup-on-failure behavior.\n");
    }
}
