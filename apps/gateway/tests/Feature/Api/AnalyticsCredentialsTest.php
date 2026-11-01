<?php

declare(strict_types=1);

use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Analytics\NativeAnalyticsStatsKeyStore;
use App\Models\Activity;
use App\Models\Node;
use App\Models\Setting;

beforeEach(function (): void {
    $this->gateway = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.1',
        'wireguard_ip' => '10.44.0.1',
    ]);
    $this->markAsGateway($this->gateway);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.1']);
    $this->secret = 'plausible-stats-sentinel-key';
});

it('stores all Plausible credentials protected and reports only their presence', function (): void {
    $credentials = ['api_key' => $this->secret, 'management_email' => 'private-owner@example.test', 'management_password' => '  private-password-sentinel  '];
    $response = $this->putJson('/api/v1/analytics/credentials', $credentials)->assertOk();
    $shown = $this->getJson('/api/v1/analytics/credentials')->assertOk();
    foreach ([$response, $shown] as $result) {
        $result->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.management_email_configured', true)
            ->assertJsonPath('data.management_password_configured', true);
        foreach ($credentials as $value) {
            expect($result->getContent())->not->toContain($value);
        }
    }
    $scope = new SettingScope(SettingScopeType::Gateway);
    foreach (['api_key' => 'analytics.stats_api_key', 'management_email' => 'analytics.management_email', 'management_password' => 'analytics.management_password'] as $field => $key) {
        $setting = Setting::query()->where('key', $key)->sole();
        expect($setting->is_secret)->toBeTrue()
            ->and($setting->value)->not->toContain($credentials[$field])
            ->and(app(SettingRepository::class)->get($scope, $key))->toBe($credentials[$field]);
    }
    expect(json_encode(Activity::query()->get()->toArray()))->not->toContain(...array_values($credentials));
    $this->putJson('/api/v1/analytics/credentials', ['api_key' => 'replacement-stats-key'])->assertOk();
    expect(app(SettingRepository::class)->get($scope, 'analytics.management_password'))->toBe($credentials['management_password']);
    $this->deleteJson('/api/v1/analytics/credentials')->assertOk();
    $this->getJson('/api/v1/analytics/credentials')->assertJsonPath('data.management_email_configured', false)
        ->assertJsonPath('data.management_password_configured', false)->assertJsonPath('data.configured', false);
});

it('refuses invalid management credentials without changing stored credentials', function (string $field, mixed $value): void {
    $this->putJson('/api/v1/analytics/credentials', ['api_key' => $this->secret, $field => $value])
        ->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
    expect(app(NativeAnalyticsStatsKeyStore::class)->get())->toBeNull();
})->with(['empty email' => ['management_email', ''], 'null email' => ['management_email', null], 'invalid email' => ['management_email', 'invalid'], 'empty password' => ['management_password', ''], 'null password' => ['management_password', null], 'oversized password' => ['management_password', str_repeat('x', 4097)], 'control password' => ['management_password', "private\0sentinel"]]);

describe('analytics:credentials', function (): void {
    it('reports that no Stats API key is stored', function (): void {
        $this->getJson('/api/v1/analytics/credentials')
            ->assertOk()
            ->assertJsonPath('data', [
                'configured' => false,
                'driver' => 'plausible_ce',
                'management_email_configured' => false,
                'management_password_configured' => false,
            ]);
    });

    it('stores a key and never returns it', function (): void {
        $response = $this->putJson('/api/v1/analytics/credentials', ['api_key' => $this->secret])
            ->assertOk();

        expect($response->json('data'))->toBe([
            'configured' => true,
            'driver' => 'plausible_ce',
            'management_email_configured' => false,
            'management_password_configured' => false,
        ])
            ->and($response->getContent())->not->toContain($this->secret)
            ->and(app(NativeAnalyticsStatsKeyStore::class)->get())->toBe($this->secret);

        $this->getJson('/api/v1/analytics/credentials')
            ->assertOk()
            ->assertJsonPath('data.configured', true)
            ->assertJsonMissing(['api_key' => $this->secret]);
    });

    it('clears the stored key', function (): void {
        $this->putJson('/api/v1/analytics/credentials', ['api_key' => $this->secret])->assertOk();

        $this->deleteJson('/api/v1/analytics/credentials')
            ->assertOk()
            ->assertJsonPath('data.configured', false);

        expect(app(NativeAnalyticsStatsKeyStore::class)->get())->toBeNull();
    });

    it('refuses an empty key', function (): void {
        $this->putJson('/api/v1/analytics/credentials', ['api_key' => ''])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation.failed');
    });

    it('redacts the key from activity input', function (): void {
        $this->putJson('/api/v1/analytics/credentials', ['api_key' => $this->secret])->assertOk();

        $activity = Activity::query()->where('command', 'analytics:credentials:set')->latest('id')->first();

        expect($activity)->not->toBeNull()
            ->and(json_encode($activity?->properties))->not->toContain($this->secret);
    });
});
