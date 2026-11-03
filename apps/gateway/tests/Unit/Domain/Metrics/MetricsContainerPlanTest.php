<?php

declare(strict_types=1);

use App\Domain\Metrics\MetricsContainerPlan;

describe(MetricsContainerPlan::class, function (): void {
    // The fixture is the plan the earlier Swamp-side renderer produced for the same input. Containers carry its spec
    // hash, so a different rendering would recreate every Node's Metrics containers on the next enable.
    it('renders the same plan and spec hashes as the earlier renderer', function (): void {
        $fixture = json_decode((string) file_get_contents(__DIR__.'/../../../Fixtures/Metrics/container-plan.json'), true);
        $input = $fixture['input'];
        $configuration = array_column($input['files'], 'content', 'path');

        expect((new MetricsContainerPlan)->for($input['assignment_id'], $input['address'], $configuration))->toBe($fixture['plan']);
    });

    it('refuses the private admin password in a public plan', function (): void {
        (new MetricsContainerPlan)->for(1, '10.44.0.12', ['/etc/orbit/metrics/grafana/admin-password' => 'secret']);
    })->throws(InvalidArgumentException::class, 'metrics.public_plan_private_field');
});
