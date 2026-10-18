<?php

declare(strict_types=1);

use App\Providers\E2EGuidelineComposer;
use Laravel\Boost\Install\GuidelineComposer;
use Laravel\Boost\Install\SkillComposer;
use Laravel\Boost\Support\RenderFailures;
use Tests\Support\TemporaryPaths;

describe('Boost guidance', function (): void {
    it('has a committed guidance index', function (): void {
        $index = base_path('.ai/rules/index.md');

        expect($index)
            ->toBeFile()
            ->and(file_get_contents($index))
            ->toContain(
                '.ai/rules/app.md',
                '.ai/rules/bootstrap.md',
                '.ai/rules/commands.md',
                '.ai/rules/state.md',
                '.ai/rules/tests.md',
                '.ai/rules/tooling.md',
            );
    });

    it('has valid configuration and non-empty generated skills', function (): void {
        $configuration = json_decode(
            (string) file_get_contents(base_path('boost.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        expect($configuration)->toBeArray();

        foreach ($configuration['skills'] as $skill) {
            $path = base_path(".agents/skills/{$skill}/SKILL.md");

            expect($path)
                ->toBeFile()
                ->and(filesize($path))
                ->toBeGreaterThan(0);
        }
    });

    it('renders all configured guidance without Boost failures', function (): void {
        $configuration = json_decode(
            (string) file_get_contents(base_path('boost.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        expect($configuration['skills'])
            ->toBe([
                'laravel-best-practices',
                'testing-best-practices',
            ]);

        // Gate users must not share compiled Blade files, including in the full-suite fallback.
        $views = TemporaryPaths::path('orbit-boost-views-');
        mkdir($views, 0700, true);
        config(['view.compiled' => $views]);

        app(SkillComposer::class)->skills();

        expect(app(RenderFailures::class)->paths())
            ->toBe([]);
    });

    it('drops stock guidance that contradicts Orbit policy from the composed and committed guidance', function (): void {
        $views = TemporaryPaths::path('orbit-boost-views-');
        mkdir($views, 0700, true);
        config(['view.compiled' => $views]);

        $composer = app(GuidelineComposer::class);
        $removed = ['## Documentation Files', '## Frontend Bundling', '## Vite Error'];

        expect($composer)->toBeInstanceOf(E2EGuidelineComposer::class);

        $composed = $composer->compose();
        $committed = (string) file_get_contents(base_path('AGENTS.md'));

        foreach ($removed as $heading) {
            expect($composed)->not->toContain($heading)
                ->and($committed)->not->toContain($heading);
        }
    });
});
