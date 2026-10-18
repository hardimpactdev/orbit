<?php

declare(strict_types=1);

use App\Providers\GatewayGuidelineComposer;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Laravel\Boost\BoostManager;
use Laravel\Boost\Install\GuidelineComposer;
use Laravel\Boost\Install\GuidelineConfig;
use Laravel\Boost\Install\GuidelineWriter;
use Laravel\Boost\Support\Config as BoostConfig;

it('regenerates strict guidance while preserving project-owned sections', function (): void {
    $guidelineDirectory = storage_path('framework/testing/boost-guidance-'.Str::uuid());
    $guidelinePath = "{$guidelineDirectory}/AGENTS.md";
    $originalGuidelinePath = Config::get('boost.agents.codex.guidelines_path');
    $originalCompiledPath = Config::get('view.compiled');
    $committedGuidance = File::get(base_path('AGENTS.md'));
    $managedBlockStart = strpos(haystack: $committedGuidance, needle: '<laravel-boost-guidelines>');
    $managedBlockEnd = strpos(haystack: $committedGuidance, needle: '</laravel-boost-guidelines>');

    expect($managedBlockStart)->toBeInt()->and($managedBlockEnd)->toBeInt();

    $projectPrefix = substr(string: $committedGuidance, offset: 0, length: $managedBlockStart);
    $projectSuffix = substr(
        $committedGuidance,
        $managedBlockEnd + strlen('</laravel-boost-guidelines>'),
    );

    expect($projectPrefix)
        ->toContain(
            'Use `vp` for generic project package and script work.',
            'Vite+ defaults projects without a manager signal to pnpm.',
            'Orbit installs Bun separately.',
            'Enforce binary directed node access at the HTTP boundary.',
        )
        ->not->toMatch('/\b(?:npm|npx|pnpm|pnpx|yarn|yarnpkg|bun|bunx)\s+(?:ci|install|run|exec|add|remove|update)\b/');

    File::ensureDirectoryExists($guidelineDirectory);
    File::put(
        $guidelinePath,
        $projectPrefix."<laravel-boost-guidelines>\nstale guidance\n</laravel-boost-guidelines>".$projectSuffix,
    );
    Config::set('boost.agents.codex.guidelines_path', $guidelinePath);
    // Keep Blade rendering away from caches cleared by concurrent Artisan tests.
    File::ensureDirectoryExists("{$guidelineDirectory}/views");
    Config::set('view.compiled', "{$guidelineDirectory}/views");

    try {
        $boost = new BoostConfig;
        $guidelineConfig = new GuidelineConfig;
        $guidelineConfig->enforceTests = true;
        $guidelineConfig->usesSail = $boost->getSail();
        $guidelineConfig->hasSkills = $boost->hasSkills();
        $guidelineConfig->hasMcp = $boost->getMcp();
        $guidelineConfig->aiGuidelines = $boost->getPackages();
        $composer = app(GuidelineComposer::class)->config($guidelineConfig);
        $effectiveGuidance = $composer->compose();
        $codexAgent = app(app(BoostManager::class)->getAgents()['codex']);
        $result = new GuidelineWriter($codexAgent)->write($effectiveGuidance);
        $regeneratedGuidance = File::get($guidelinePath);

        expect(File::files("{$guidelineDirectory}/views"))->not->toBeEmpty();
    } finally {
        Config::set('boost.agents.codex.guidelines_path', $originalGuidelinePath);
        Config::set('view.compiled', $originalCompiledPath);
        File::deleteDirectory($guidelineDirectory);
    }

    preg_match(
        pattern: '/<laravel-boost-guidelines>\s*(.*?)\s*<\/laravel-boost-guidelines>/s',
        subject: $regeneratedGuidance,
        matches: $regeneratedMatches,
    );
    preg_match(
        pattern: '/<laravel-boost-guidelines>\s*(.*?)\s*<\/laravel-boost-guidelines>/s',
        subject: $committedGuidance,
        matches: $committedMatches,
    );

    expect($regeneratedGuidance)
        ->toContain(
            'Use `vp` for generic project package and script work.',
            'Vite+ defaults projects without a manager signal to pnpm.',
            'Orbit installs Bun separately.',
            'Enforce binary directed node access at the HTTP boundary.',
            'Tool rows store managed intent, not observed host inventory.',
            'The tool identity is node, manager, and package.',
            'Tool install and adopt input is limited to node_id, manager, package, and version_constraint.',
            'Do not expose manager argv, scripts, repositories, environment variables, options,',
            'The closed tool manager registry contains apt, composer, vp, brew, and brew-cask.',
            'Use Vite+ global packages instead of exposing npm as a manager.',
            'Never persist or return raw manager stdout or stderr.',
            'APT removal must remove only the exact recorded package.',
            'Track tool intent, not host inventory',
            'Keep tool input narrow',
            'Use the closed tool manager registry',
        )
        ->not->toMatch('/\b(?:npm|npx|pnpm|pnpx|yarn|yarnpkg|bun|bunx)\s+(?:ci|install|run|exec|add|remove|update)\b/');

    expect($composer)
        ->toBeInstanceOf(GatewayGuidelineComposer::class)
        ->and($result)
        ->toBe(GuidelineWriter::REPLACED)
        ->and($regeneratedGuidance)
        ->toStartWith($projectPrefix)
        ->toEndWith($projectSuffix)
        ->toContain(
            'If guidance is missing or incomplete, follow Required Guidance Bootstrap above.',
        )
        ->not
        ->toContain(
            'stale guidance',
            '## Frontend Bundling',
            '## Vite Error',
            '## Documentation Files',
            '=== deployments rules ===',
            'Laravel Cloud',
            'in `.ai/rules` when that directory exists',
            'If `.ai/rules` does not exist, continue without it.',
            'when that directory exists',
            'continue without it',
        )
        ->and($regeneratedMatches[1] ?? null)
        ->toBe(trim($effectiveGuidance))
        ->and($committedMatches[1] ?? null)
        ->toBe(trim($effectiveGuidance));

    expect(substr_count($regeneratedGuidance, '## Skills Activation'))->toBe(1)
        ->and(substr_count($regeneratedGuidance, 'Run `composer guidance:check` before planning or editing.'))->toBe(1);
});

it('keeps regenerated Pest check guidance under the root check policy', function (): void {
    $composer = app(GuidelineComposer::class);
    $guidelines = new ReflectionProperty(GuidelineComposer::class, 'guidelines');
    $guidelines->setValue($composer, collect([
        'pest' => ['scoped' => [[
            'body' => '- After the feature tests pass, ask the user to run the complete suite with `php artisan test --compact`.',
        ]]],
    ]));

    $scopedGuidance = $composer->resolvedGuidelines()->pluck('scoped')->flatten(1)->pluck('body')->implode("\n");

    expect($scopedGuidance)
        ->toContain('follow the root `AGENTS.md` check policy for task and non-task work')
        ->not->toContain('run `composer check` locally');
});

it('fails closed when Boost changes its project rule source markers', function (string $sourceGuidance): void {
    $composer = app(GuidelineComposer::class);
    $guidelines = new ReflectionProperty(GuidelineComposer::class, 'guidelines');
    $guidelines->setValue($composer, collect([
        'boost' => [
            'content' => $sourceGuidance,
            'name' => 'boost',
            'path' => null,
            'custom' => false,
        ],
    ]));

    expect($composer->compose(...))
        ->toThrow(
            RuntimeException::class,
            'Boost project-rule guidance changed. Update the Gateway hard-stop transformation before regenerating.',
        );
})->with([
    'missing optional location' => 'If `.ai/rules` does not exist, continue without it.',
    'duplicate optional location' => implode("\n", [
        'in `.ai/rules` when that directory exists',
        'in `.ai/rules` when that directory exists',
        'If `.ai/rules` does not exist, continue without it.',
    ]),
    'missing permissive fallback' => 'in `.ai/rules` when that directory exists',
    'duplicate permissive fallback' => implode("\n", [
        'in `.ai/rules` when that directory exists',
        'If `.ai/rules` does not exist, continue without it.',
        'If `.ai/rules` does not exist, continue without it.',
    ]),
]);
