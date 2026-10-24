<?php

declare(strict_types=1);

use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Infrastructure\GatewayReleases\GatewayReleaseCommands;
use App\Infrastructure\GatewayReleases\GatewayReleaseRecovery;
use App\Infrastructure\GatewayReleases\GatewayReleaseRestore;
use App\Models\GatewayRelease;
use App\Models\Node;
use Tests\Support\FakeAgentCommandRunner;

beforeEach(function (): void {
    $node = Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.2', 'status' => 'active']);
    $node->roles()->create(['role' => 'gateway', 'status' => 'active']);
    $this->home = '/home/orbit/.orbit';
    config(['orbit.home' => $this->home]);
    $this->agents = new FakeAgentCommandRunner;
    $this->commands = new GatewayReleaseCommands($this->agents);
    $this->recovery = new GatewayReleaseRecovery($this->commands);
    $this->restore = new GatewayReleaseRestore($this->recovery, $this->commands);
    $this->sha = str_repeat('a', 40);
    $this->record = new GatewayRelease()->forceFill(['id' => 7, 'sha' => $this->sha, 'snapshot_path' => $this->home.'/backups/original.sqlite', 'previous_release_id' => 'bbbbbbbbbbbb', 'phases' => []]);
    $this->path = $this->home.'/recovery/stage-7-'.$this->sha.'.sqlite';
    $this->hash = str_repeat('c', 64);
    $this->identity = ['orbit_home' => $this->home, 'record' => 7, 'sha' => $this->sha];
    $this->agents->answer('gateway.protect-release-recovery', ['path' => $this->path, 'sha256' => $this->hash])
        ->answer('gateway.verify-release-recovery', ['verified' => true])
        ->answer('gateway.write-release-receipt', ['changed' => true]);
    $this->saved = ['outcome' => 'verified', 'original' => $this->record->snapshot_path, 'path' => $this->path, 'sha256' => $this->hash];
});

it('protects the original snapshot with exact staged intent without writing the record', function (): void {
    $before = $this->record->getAttributes();

    expect($this->recovery->protect($this->record))->toBe($this->saved);
    expect($this->agents->calls)->toBe([['node' => 'gateway', 'command' => 'gateway.protect-release-recovery', 'input' => ['identity' => $this->identity, 'source' => $this->record->snapshot_path]]]);
    expect($this->record->getAttributes())->toBe($before);
});

it('verifies the recorded copy on repeats even after the original snapshot was retired', function (): void {
    $this->record->phases = ['recovery' => $this->saved];
    $this->record->snapshot_path = null;

    expect($this->recovery->protect($this->record))->toBe($this->saved);
    $this->recovery->verify($this->record);

    expect($this->agents->calls)->toBe(array_fill(0, 2, ['node' => 'gateway', 'command' => 'gateway.verify-release-recovery', 'input' => ['identity' => $this->identity, 'sha256' => $this->hash]]));
});

it('rejects recovery phases that do not bind this record before sending a command', function (string $field, mixed $value): void {
    $this->saved[$field] = $value;
    $this->record->phases = ['recovery' => $this->saved];

    expect(fn () => $this->recovery->verify($this->record))->toThrow(GatewayReleaseException::class);
    expect($this->agents->calls)->toBe([]);
})->with([['outcome', 'pending'], ['path', '/other/snapshot.sqlite'], ['sha256', null]]);

it('rejects incomplete recovery evidence returned by the agent', function (array $result): void {
    $this->agents->answer('gateway.protect-release-recovery', $result);

    expect(fn () => $this->recovery->protect($this->record))->toThrow(GatewayReleaseException::class, 'invalid release command result');
})->with([
    [[]], [['path' => '/other/snapshot.sqlite', 'sha256' => str_repeat('a', 64)]],
    [['path' => '/home/orbit/.orbit/recovery/stage-7-'.str_repeat('a', 40).'.sqlite', 'sha256' => 'not-a-hash']],
]);

it('does not accept an incomplete verification result', function (): void {
    $this->record->phases = ['recovery' => $this->saved];
    $this->agents->answer('gateway.verify-release-recovery', []);

    expect(fn () => $this->recovery->verify($this->record))->toThrow(GatewayReleaseException::class, 'invalid release command result');
});

it('maps native foreign-key evidence into the recovery phase', function (): void {
    $violations = [['table' => 'notes', 'rowid' => 7, 'parent' => 'owners', 'fkid' => 0]];
    $this->agents->refuse('gateway.protect-release-recovery', 'gateway.release_recovery_foreign_keys', ['violations' => $violations, 'limit' => 20]);

    try {
        $this->recovery->protect($this->record);
        $this->fail('Expected a recovery refusal.');
    } catch (GatewayReleaseException $error) {
        expect($error->step)->toBe('recovery')->and($error->errorCode)->toBe('gateway.release_recovery_foreign_keys');
        expect($error->phase)->toBe(['violations' => $violations, 'limit' => 20]);
    }
});

it('persists exact receipt attributes and verifies their staged identity when reading', function (): void {
    $this->record->phases = ['recovery' => $this->saved, 'hold' => ['record' => 7, 'source' => 'bbbbbbbbbbbb', 'outcome' => 'held']];
    $attributes = $this->record->getAttributes();
    $this->agents->answer('gateway.read-release-receipt', ['attributes' => $attributes]);

    $this->restore->remember($this->record);
    expect($this->restore->read($this->sha, 7)->getAttributes())->toBe($attributes);
    expect($this->agents->calls)->toBe([
        ['node' => 'gateway', 'command' => 'gateway.write-release-receipt', 'input' => ['identity' => $this->identity, 'attributes' => $attributes]],
        ['node' => 'gateway', 'command' => 'gateway.read-release-receipt', 'input' => $this->identity],
        ['node' => 'gateway', 'command' => 'gateway.verify-release-recovery', 'input' => ['identity' => $this->identity, 'sha256' => $this->hash]],
    ]);
});

it('refuses mismatched receipt identity before recovery verification', function (string $field): void {
    $hold = ['record' => 7, 'source' => 'bbbbbbbbbbbb', 'outcome' => 'held'];
    $this->record->phases = ['recovery' => $this->saved, 'hold' => $hold];
    $attributes = $this->record->getAttributes();
    if (in_array($field, ['id', 'sha'], true)) {
        $attributes[$field] = $field === 'id' ? 8 : str_repeat('f', 40);
    } else {
        $hold[$field] = $field === 'record' ? 8 : 'changed';
        $attributes['phases'] = json_encode(['recovery' => $this->saved, 'hold' => $hold], JSON_THROW_ON_ERROR);
    }
    $this->agents->answer('gateway.read-release-receipt', ['attributes' => $attributes]);

    expect(fn () => $this->restore->read($this->sha, 7))->toThrow(GatewayReleaseException::class, 'does not identify');
    expect($this->agents->commands())->toBe(['gateway.read-release-receipt']);
})->with(['id', 'sha', 'record', 'source', 'outcome']);

it('keeps native receipt refusals in the abandon phase', function (string $command): void {
    $this->agents->refuse($command, 'gateway.release_restore_required');

    try {
        if ($command === 'gateway.write-release-receipt') {
            $this->restore->remember($this->record);
        } else {
            $this->restore->read($this->sha, 7);
        }
        $this->fail('Expected a receipt refusal.');
    } catch (GatewayReleaseException $error) {
        expect($error->step)->toBe('abandon')->and($error->errorCode)->toBe('gateway.release_restore_required');
    }
})->with(['gateway.write-release-receipt', 'gateway.read-release-receipt']);

it('rejects malformed native receipt attributes', function (array $output): void {
    $this->agents->answer('gateway.read-release-receipt', $output);

    expect(fn () => $this->restore->read($this->sha, 7))->toThrow(GatewayReleaseException::class, 'invalid release command result');
})->with([[[]], [['attributes' => null]], [['attributes' => [1]]]]);
