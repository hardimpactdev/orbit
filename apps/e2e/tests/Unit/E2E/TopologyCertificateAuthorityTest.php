<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/** @return array{root:string,script:string,ca:string} */
function topologyCaFixture(): array
{
    $root = temporaryPath('orbit-topology-ca-');
    mkdir($root, 0700, true);
    $script = $root.'/topology-ca.php';
    file_put_contents($script, str_replace(
        ['/home/orbit/orbit/apps/gateway/vendor/autoload.php', '/home/orbit/.orbit/ca'],
        [dirname(__DIR__, 4).'/gateway/vendor/autoload.php', $root.'/ca'],
        guestScriptSource('topology-ca.php'),
    ));

    return ['root' => $root, 'script' => $script, 'ca' => $root.'/ca'];
}

it('creates a private disposable root with the real Gateway CA rule and retains it on repeated setup', function (): void {
    $fixture = topologyCaFixture();
    $process = new Process([PHP_BINARY, $fixture['script']]);

    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(json_decode($process->getOutput(), true))->toBe(['topology_ca' => 'fixture-set'])
        ->and($process->getErrorOutput())->toBe('');
    $private = file_get_contents($fixture['ca'].'/root.key');
    $certificate = file_get_contents($fixture['ca'].'/root.pem');
    expect(fileperms($fixture['ca']) & 0777)->toBe(0700)
        ->and(fileperms($fixture['ca'].'/root.key') & 0777)->toBe(0600)
        ->and(fileperms($fixture['ca'].'/root.pem') & 0777)->toBe(0644);
    $verification = new Process(['openssl', 'verify', '-CAfile', $fixture['ca'].'/root.pem', $fixture['ca'].'/root.pem']);
    expect($verification->run())->toBe(0, $verification->getErrorOutput());

    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(file_get_contents($fixture['ca'].'/root.key'))->toBe($private)
        ->and(file_get_contents($fixture['ca'].'/root.pem'))->toBe($certificate)
        ->and($process->getOutput().$process->getErrorOutput())->not->toContain($private, $certificate);
});

it('refuses partial invalid or linked CA state without replacing or exporting it', function (string $state): void {
    $fixture = topologyCaFixture();
    mkdir($fixture['ca'], 0700);
    $private = 'private-fixture-value';
    file_put_contents($fixture['ca'].'/root.key', $private);
    if ($state === 'invalid') {
        file_put_contents($fixture['ca'].'/root.pem', 'invalid-certificate');
    } elseif ($state === 'linked') {
        file_put_contents($fixture['root'].'/foreign.pem', 'foreign-certificate');
        symlink($fixture['root'].'/foreign.pem', $fixture['ca'].'/root.pem');
    }
    $process = new Process([PHP_BINARY, $fixture['script']]);

    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())->toBe("e2e.topology_ca_invalid\n")
        ->and(file_get_contents($fixture['ca'].'/root.key'))->toBe($private)
        ->and(file_exists($fixture['ca'].'/root.pem'))->toBe($state !== 'partial');
})->with(['partial', 'invalid', 'linked']);

it('signs the repository leaf with the disposable root under the canonical Gateway leaf policy', function (): void {
    $fixture = topologyCaFixture();
    (new Process([PHP_BINARY, $fixture['script']]))->mustRun();
    $directory = $fixture['root'].'/repositories';
    $script = $fixture['root'].'/leaf.php';
    file_put_contents($script, str_replace(
        ['/home/orbit/orbit/apps/gateway/vendor/autoload.php', '/home/orbit/.orbit/ca', '/etc/swamp-proof'],
        [dirname(__DIR__, 4).'/gateway/vendor/autoload.php', $fixture['ca'], $directory],
        guestScriptSource('topology-repository-certificate.php'),
    ));
    $process = new Process([PHP_BINARY, $script, '10.232.14.10']);

    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toBe("{\"certificate\":true}\n")
        ->and($process->getErrorOutput())->toBe('');
    $private = file_get_contents($directory.'/repositories.key');
    $certificate = file_get_contents($directory.'/repositories.pem');
    $parsed = openssl_x509_parse($certificate);
    expect(openssl_pkey_get_details(openssl_pkey_get_private($private))['bits'])->toBe(2048)
        ->and($parsed['extensions']['subjectAltName'])->toBe('DNS:repositories.orbit, IP Address:10.232.14.10')
        ->and($parsed['validTo_time_t'] - $parsed['validFrom_time_t'])->toBe(397 * 86400);

    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(file_get_contents($directory.'/repositories.key'))->toBe($private)
        ->and(file_get_contents($directory.'/repositories.pem'))->toBe($certificate);
});
