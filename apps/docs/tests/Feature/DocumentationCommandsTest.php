<?php

declare(strict_types=1);

use HardImpact\Librarian\Linting\Rules\CoreDocsStructureRule;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

it('lints documentation without imposing Librarian core page templates', function (): void {
    $root = sys_get_temp_dir().'/orbit-docs-policy-'.bin2hex(random_bytes(8));
    mkdir($root.'/docs/decisions', 0777, true);
    mkdir($root.'/docs/domains', 0777, true);
    mkdir($root.'/docs/solutions', 0777, true);
    mkdir($root.'/apps/docs/config', 0777, true);

    try {
        foreach (['README', 'mission', 'architecture', 'tech-stack', 'concepts', 'decisions/README', 'domains/README', 'solutions/README'] as $page) {
            file_put_contents($root.'/docs/'.$page.'.md', "# Documentation\n\nOrbit checks documentation.\n");
        }
        file_put_contents($root.'/docs/mission.md', "# Mission\n\nOrbit checks documentation.\n\n## Principles\n\nAgents operate, humans steer.\n");
        file_put_contents($root.'/docs/decisions/overview.mdx', "# Decisions\n\n## Records\n\n| Record | Decision | Status |\n| --- | --- | --- |\n\n## Retired decisions\n\n| Record | Decision | Now in |\n| --- | --- | --- |\n");
        file_put_contents($root.'/docs/docs.json', json_encode(['navigation' => ['tabs' => [
            ['tab' => 'Guides', 'groups' => [
                ['group' => 'Overview', 'pages' => ['mission', 'architecture', 'tech-stack', 'concepts']],
            ]],
        ]], 'redirects' => []], JSON_THROW_ON_ERROR));
        foreach (['adr-legacy-allowlist', 'adr-retired-slugs', 'docs-covers-ratchet'] as $config) {
            file_put_contents($root.'/apps/docs/config/'.$config.'.php', '<?php return [];');
        }
        Process::run(['git', 'init', '-q', '-b', 'main', $root])->throw();
        Process::path($root)->run(['git', 'config', 'gc.auto', '0'])->throw();
        Process::path($root)->run(['git', 'config', 'maintenance.auto', 'false'])->throw();
        config()->set('librarian.path', $root.'/docs');
        config()->set('orbit-docs.index_path', $root.'/docs/generated/context.json');
        expect(Artisan::call('orbit:docs-index'))->toBe(0);
        Process::path($root)->run(['git', 'add', '.'])->throw();
        Process::path($root)->run(['git', '-c', 'user.name=Docs', '-c', 'user.email=docs@example.test', '-c', 'core.hooksPath=/dev/null', '-c', 'commit.gpgsign=false', 'commit', '-qm', 'fixture'])->throw();

        // Prove the input exercises the ignored rule, not merely an empty corpus.
        expect(app(CoreDocsStructureRule::class)->check())->toHaveCount(3);
        $exitCode = Artisan::call('orbit:docs-lint', ['--format' => 'json']);
        $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($output['findings'] ?? [])->toBe([]);
        expect($exitCode)
            ->toBe(0)
            ->and($output['tool'])
            ->toBe('librarian')
            ->and($output['result'])
            ->toBe('passed')
            ->and(config('orbit-docs.ignored_librarian_rules'))
            ->toContain('librarian.core_docs_structure');
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
});

it('keeps the committed documentation context index current', function (): void {
    $exitCode = Artisan::call('orbit:docs-index', ['--check' => true]);

    expect($exitCode)->toBe(0)->and(Artisan::output())->toContain('Documentation context index is current.');
});

it('returns ordered context for a repository component', function (): void {
    $exitCode = Artisan::call('orbit:docs-context', [
        '--component' => ['apps/docs'],
        '--format' => 'json',
    ]);

    $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)
        ->toBe(0)
        ->and($output['schema_version'])
        ->toBe(1)
        ->and(collect($output['documents'])->pluck('path')->all())
        ->toContain('docs/README.md');
});

it('keeps the application console-only', function (): void {
    expect(base_path('routes/web.php'))
        ->not->toBeFile()->and(base_path('database'))
        ->not->toBeDirectory()->and(base_path('package.json'))
        ->not->toBeFile()->and(base_path('resources'))
        ->not->toBeDirectory();
});
