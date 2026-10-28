<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('publishes private DNS atomically and restores data and service state on refusal', function (): void {
    $process = new Process(['python3', '-I', base_path('tests/Fixtures/Routes/private_dns_publication.py'), resource_path('remote/route/publish-dns.py')]);
    $process->setTimeout(2);
    $process->run();

    expect($process->getErrorOutput())->toContain('Ran 8 tests', 'OK');
    expect($process->getExitCode())->toBe(0);
});
