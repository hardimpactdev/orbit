<?php

declare(strict_types=1);

use Orbit\Extensions\AiRouter\Infrastructure\ArrayAiRouterCache;

it('preserves cache expiry and rate limit schedules across repeated cutover attempts', function (): void {
    $this->freezeTime();
    $cache = new ArrayAiRouterCache;
    $cache->put('orbit:proxycli:backoff:limited', 'retry-after', 60);
    $cache->put('orbit:proxycli:account:limited', 'next-check', 120);
    $cache->put('orbit:proxycli:snapshot', 'snapshot');
    $cache->put('orbit:proxycli:lock', 'old-holder', 60);
    $cache->put('unrelated', 'keep');
    $this->travel(45)->seconds();

    $cache->renameCollectorKeys('orbit:proxycli:', 'orbit:ai-router:');
    $cache->renameCollectorKeys('orbit:proxycli:', 'orbit:ai-router:');

    expect($cache->get('orbit:ai-router:backoff:limited'))->toBe('retry-after')
        ->and($cache->get('orbit:ai-router:account:limited'))->toBe('next-check')
        ->and($cache->get('orbit:proxycli:backoff:limited'))->toBeNull()
        ->and($cache->get('orbit:ai-router:lock'))->toBeNull()
        ->and($cache->get('orbit:proxycli:lock'))->toBeNull()
        ->and($cache->get('unrelated'))->toBe('keep');
    $this->travel(16)->seconds();
    expect($cache->get('orbit:ai-router:backoff:limited'))->toBeNull()
        ->and($cache->get('orbit:ai-router:account:limited'))->toBe('next-check');
    $this->travel(60)->seconds();
    expect($cache->get('orbit:ai-router:account:limited'))->toBeNull()
        ->and($cache->get('orbit:ai-router:snapshot'))->toBe('snapshot');
});
