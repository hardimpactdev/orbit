<?php

declare(strict_types=1);

use App\E2E\IncusHost;
use App\E2E\TopologySwamp;
use Tests\Support\FakeIncus;
use Tests\TestCase;

uses(TestCase::class);

it('refuses a missing sandbox credential before any Incus request', function (): void {
    $incus = new FakeIncus()->install();
    expect(fn () => (new TopologySwamp(new IncusHost))->assertCredential())
        ->toThrow(RuntimeException::class, 'e2e.swamp_club_credential_missing');
    expect($incus->calls)->toBe([]);
});

it('rejects unsafe sandbox credential custody', function (string $change): void {
    $path = temporaryPath('swamp-club-', 5);
    file_put_contents($path, 'fixture-private-credential');
    chmod($path, 0600);
    if ($change === 'mode') {
        chmod($path, 0644);
    } elseif ($change === 'symlink') {
        symlink($path, $path.'.link');
        $path .= '.link';
    } elseif ($change === 'empty') {
        file_put_contents($path, '');
    } else {
        file_put_contents($path, "invalid token\n");
    }
    $incus = new FakeIncus()->install();
    expect(fn () => (new TopologySwamp(new IncusHost, $path))->assertCredential())
        ->toThrow(RuntimeException::class, 'e2e.swamp_club_credential_invalid');
    expect($incus->calls)->toBe([]);
})->with(['mode', 'symlink', 'empty', 'whitespace']);

it('delivers the sandbox credential only on owned Gateway exec stdin and withholds native failures', function (): void {
    $path = temporaryPath('swamp-club-', 5);
    $credential = 'fixture-private-credential';
    file_put_contents($path, $credential);
    chmod($path, 0600);
    $target = featureTarget('TST-11');
    $incus = new FakeIncus()->install();
    $incus->instances[$target->instance('gateway')] = [
        ...$incus->instances[IncusHost::TEMPLATE],
        'status' => 'Running', 'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
    ];
    $incus->respond(static fn (): array => ['', 'native secret '.$credential, 1]);
    expect(fn () => (new TopologySwamp(new IncusHost, $path))->prepare($target))
        ->toThrow(RuntimeException::class, 'e2e.swamp_prepare_failed');
    expect($incus->execs)->toHaveCount(1)
        ->and($incus->execs[0]['argv'])->toBe(['/usr/local/bin/converge-swamp.py', 'prepare'])
        ->and($incus->execs[0]['stdin'])->toBe($credential);
});

it('starts the prepared sandbox without another credential delivery', function (): void {
    $target = featureTarget('TST-11');
    $incus = new FakeIncus()->install();
    $incus->instances[$target->instance('gateway')] = [
        ...$incus->instances[IncusHost::TEMPLATE],
        'status' => 'Running', 'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
    ];
    $incus->respond(static fn (): array => ['', '', 0]);
    (new TopologySwamp(new IncusHost))->start($target);
    expect($incus->execs)->toHaveCount(1)
        ->and($incus->execs[0]['argv'])->toBe(['/usr/local/bin/converge-swamp.py', 'start'])
        ->and($incus->execs[0]['stdin'])->toBeNull();
});
