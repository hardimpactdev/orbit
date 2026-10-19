<?php

declare(strict_types=1);

use Orbit\Extensions\AiRouter\Domain\AiRouterAccount;
use Orbit\Extensions\AiRouter\Domain\AiRouterPoolCompiler;
use Orbit\Extensions\AiRouter\Domain\AiRouterWindow;

describe('ai-router pooling', function (): void {
    it('recompiles pools from cache after an account toggle without inventing windows', function (): void {
        $compiler = new AiRouterPoolCompiler;
        $accounts = [
            new AiRouterAccount('plus.json', 'codex', 'plus', false, 'enabled', [
                new AiRouterWindow('7d', 20),
                new AiRouterWindow('5h', 5),
            ]),
            new AiRouterAccount('pro.json', 'codex', 'pro', false, 'enabled', [
                new AiRouterWindow('7d', 80),
            ]),
        ];

        $before = $compiler->compile($accounts, '2026-09-20T12:00:00+00:00');
        $after = $compiler->compile($compiler->withDisabled($accounts, 'pro.json', true), '2026-09-20T12:01:00+00:00');

        expect($before->provider('codex')?->windows)->toHaveCount(2)
            ->and($before->provider('codex')?->windows[0]->label)->toBe('7d')
            ->and($before->provider('codex')?->windows[0]->usedPercent)->toBe(50.0)
            ->and($after->provider('codex')?->windows)->toHaveCount(2)
            ->and($after->accounts[1]->disabled)->toBeTrue()
            ->and($after->provider('codex')?->windows[0]->usedPercent)->toBe(20.0);
    });
});
