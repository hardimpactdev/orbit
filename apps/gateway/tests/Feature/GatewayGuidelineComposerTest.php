<?php

declare(strict_types=1);

use Laravel\Boost\Install\GuidelineComposer;
use Laravel\Boost\Install\GuidelineConfig;

it('keeps always-loaded guidance short without dropping scoped framework rules', function (): void {
    $configuration = new GuidelineConfig;
    $configuration->aiGuidelines = [];
    $configuration->enforceTests = true;
    $composer = app(GuidelineComposer::class)->config($configuration);

    $guidance = $composer->compose();

    expect($guidance)
        ->toContain('.ai/rules/index.md', 'Required Guidance Bootstrap above', 'search-docs', '# Test Enforcement')
        ->not->toContain('continue without it', '## Search Syntax');
    expect(str_word_count($guidance))->toBeLessThan(800);

    $scoped = collect($composer->resolvedGuidelines()->pluck('scoped')->filter()->flatten(1));

    expect($scoped->pluck('paths')->flatten()->all())
        ->toContain('app/Models/**', 'app/Http/**', 'tests/**');
    expect($scoped->pluck('body')->implode("\n"))
        ->toContain('Spatie Data response objects', 'stock Pest');
});
