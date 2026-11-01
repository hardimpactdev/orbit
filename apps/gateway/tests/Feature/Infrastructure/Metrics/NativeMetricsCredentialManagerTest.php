<?php

declare(strict_types=1);

use App\Domain\Metrics\MetricsCredentialManager;
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Settings\SettingValueProtection;
use App\Infrastructure\Metrics\NativeMetricsCredentialManager;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Setting;

it('creates once and reads the encrypted active credential without an execution', function (): void {
    $node = Node::query()->create(['name' => 'metrics', 'status' => 'active', 'public_ssh_host' => '192.0.2.9']);
    $node->roles()->create(['role' => 'metrics', 'status' => 'active']);
    $manager = app(MetricsCredentialManager::class);
    $password = $manager->passwordForConvergence($node);
    expect($manager->passwordForConvergence($node))->toBe($password);
    expect($manager->credentials()->password)->toBe($password);
    expect(Setting::query()->where('key', NativeMetricsCredentialManager::ActivePasswordKey)->sole()->getRawOriginal('value'))->not->toContain($password);
    expect(Operation::query()->count())->toBe(0);
});

it('purges both credential settings only when explicitly requested by projection', function (): void {
    $node = Node::query()->create(['name' => 'metrics', 'status' => 'active', 'public_ssh_host' => '192.0.2.9']);
    $scope = new SettingScope(SettingScopeType::Node, $node->id);
    $settings = app(SettingRepository::class);
    foreach ([NativeMetricsCredentialManager::ActivePasswordKey, NativeMetricsCredentialManager::PendingPasswordKey] as $key) {
        $settings->put($scope, $key, 'fixture-private-value', SettingValueProtection::Secret);
    }
    app(MetricsCredentialManager::class)->purge($node);
    expect($settings->get($scope, NativeMetricsCredentialManager::ActivePasswordKey))->toBeNull();
    expect($settings->get($scope, NativeMetricsCredentialManager::PendingPasswordKey))->toBeNull();
});
