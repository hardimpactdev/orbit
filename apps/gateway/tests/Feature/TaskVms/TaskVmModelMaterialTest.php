<?php

declare(strict_types=1);

use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\ModelKeyIssuer;
use App\Domain\TaskVms\TaskVmException;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;
use Tests\Support\TaskVmSettingsBuilder;

it('returns existing router credentials through the plug-in without a router request', function (): void {
    Http::preventStrayRequests();
    TaskVmSettingsBuilder::bind();
    app(AiRouterState::class)->enable(42, 'cache', TaskVmSettingsBuilder::Origin, 'management-secret', 'read-secret', 'control-secret');
    $issuer = app(ModelKeyIssuer::class);

    $first = $issuer->privateMaterial(TaskVmSettingsBuilder::Origin, 42);
    $again = $issuer->privateMaterial(TaskVmSettingsBuilder::Origin, 42);

    expect($issuer->ownerNodeId())->toBe(42)
        ->and($first)->toBe($again)
        ->and($first['management_key'])->toBe('management-secret')
        ->and($first['anchor_key'])->toMatch('/\A[a-f0-9]{64}\z/D');
    $stored = Setting::query()->where('key', 'ai_router.anchor.'.hash('sha256', TaskVmSettingsBuilder::Origin))->sole();
    expect($stored->is_secret)->toBeTrue()->and($stored->value)->not->toBe($first['anchor_key']);
    Http::assertNothingSent();
});

it('refuses a router owner mismatch before staging or generating an anchor', function (): void {
    Http::preventStrayRequests();
    TaskVmSettingsBuilder::bind();
    app(AiRouterState::class)->enable(42, 'cache', TaskVmSettingsBuilder::Origin, 'management-secret', 'read-secret', 'control-secret');

    expect(fn () => app(ModelKeyIssuer::class)->privateMaterial(TaskVmSettingsBuilder::Origin, 43))
        ->toThrow(ResourceOperationException::class);
    expect(Setting::query()->where('key', 'like', 'ai_router.anchor.%')->exists())->toBeFalse();
    Http::assertNothingSent();
});

it('refuses absent router management credentials without returning material', function (): void {
    Http::preventStrayRequests();
    TaskVmSettingsBuilder::bind();
    app(AiRouterState::class)->enable(42, 'cache', TaskVmSettingsBuilder::Origin, '', 'read-secret', 'control-secret');

    expect(fn () => app(ModelKeyIssuer::class)->privateMaterial(TaskVmSettingsBuilder::Origin, 42))
        ->toThrow(TaskVmException::class);
    expect(Setting::query()->where('key', 'like', 'ai_router.anchor.%')->exists())->toBeFalse();
    Http::assertNothingSent();
});
