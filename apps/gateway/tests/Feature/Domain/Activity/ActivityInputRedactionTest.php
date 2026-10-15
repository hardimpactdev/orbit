<?php

declare(strict_types=1);

use App\Domain\Activity\ActivityInputRedaction;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Activity;
use App\Models\Node;

final class KeepOnlyActivityInputRedaction implements ActivityInputRedaction
{
    public function commands(): array
    {
        return ['extension:list'];
    }

    public function input(string $command, array $input): array
    {
        return array_intersect_key($input, ['keep' => true]);
    }
}

beforeEach(function (): void {
    $gateway = $this->markAsGateway(Node::query()->create([
        'name' => 'redaction-gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.19',
        'wireguard_ip' => '10.44.0.19',
    ]));
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
});

it('keeps only what a contributed redaction returns for its commands', function (): void {
    $this->app->tag([KeepOnlyActivityInputRedaction::class], ActivityInputRedaction::class);

    $this->json('GET', '/api/v1/extensions', ['keep' => 'visible', 'secret' => 'hidden-value'])->assertOk();

    $activity = Activity::query()->where('command', 'extension:list')->sole();
    expect($activity->properties?->toArray()['input'])->toBe(['keep' => 'visible'])
        ->and(json_encode($activity->properties))->not->toContain('hidden-value');
});

it('redacts an extension command while its switch is off', function (): void {
    expect(app(ExtensionStore::class)->enabled('conn'))->toBeFalse();

    $this->postJson('/api/v1/conn/environments', [
        'environment_id' => 'env-1',
        'label' => 'Beast',
        'url' => 'https://t3.beast.orbit',
        'admin_session' => 'admin-session-token',
    ])->assertStatus(409)->assertJsonPath('error.code', 'extension.disabled');

    $activity = Activity::query()->where('command', 'conn:environment:register')->sole();
    expect($activity->properties?->toArray()['input'])->toBe(['environment_id' => 'env-1', 'label' => 'Beast', 'url' => 'https://t3.beast.orbit'])
        ->and(json_encode($activity->properties))->not->toContain('admin-session-token');
});
