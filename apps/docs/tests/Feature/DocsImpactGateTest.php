<?php

declare(strict_types=1);

use App\Documentation\DocsImpact;
use Illuminate\Support\Facades\Artisan;

$GLOBALS['docsImpactGateFixtureRoots'] = [];

afterEach(function (): void {
    foreach ($GLOBALS['docsImpactGateFixtureRoots'] as $root) {
        if (! is_dir($root)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($root);
    }
    $GLOBALS['docsImpactGateFixtureRoots'] = [];
});

function docsImpactGateFixture(): array
{
    $root = sys_get_temp_dir().'/docs-impact-gate-'.bin2hex(random_bytes(6));
    $GLOBALS['docsImpactGateFixtureRoots'][] = $root;
    mkdir($root.'/docs/reference', 0777, true);
    mkdir($root.'/apps/gateway/app/Domain/Tasks', 0777, true);
    mkdir($root.'/apps/gateway/resources/mcp', 0777, true);
    file_put_contents($root.'/docs/reference/tasks.md', "---\ntitle: Tasks\ncovers:\n  - \"apps/gateway/app/Domain/Tasks/**\"\n---\nTasks\n");
    file_put_contents($root.'/docs/reference/mcp.mdx', "---\ntitle: MCP\n---\nMCP documentation.\n");
    file_put_contents($root.'/apps/gateway/resources/mcp/tools.json', '{"tools":[]}');
    file_put_contents($root.'/apps/gateway/app/Domain/Tasks/RunTask.php', "<?php\nclass RunTask {}\n");
    exec('git -C '.escapeshellarg($root).' init -q -b task-1');
    // Detached maintenance can create object files while teardown removes the repository.
    exec('git -C '.escapeshellarg($root).' config gc.auto 0');
    exec('git -C '.escapeshellarg($root).' config maintenance.auto false');
    exec('git -C '.escapeshellarg($root).' add .');
    exec('git -C '.escapeshellarg($root).' -c user.name=Docs -c user.email=docs@example.test commit -qm fixture');
    $base = trim((string) shell_exec('git -C '.escapeshellarg($root).' rev-parse HEAD'));

    return [$root, $base];
}

function changeDocsImpactGateSource(string $root): void
{
    file_put_contents($root.'/apps/gateway/app/Domain/Tasks/RunTask.php', "<?php\nclass RunTask { public function changed(): void {} }\n");
}

function writeUnaffectedPageNote(string $root, string $branch, string $note): void
{
    $directory = dirname($root.'/.git/orbit/docs-unaffected/'.$branch.'.txt');
    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }
    file_put_contents($root.'/.git/orbit/docs-unaffected/'.$branch.'.txt', $note."\n");
}

function runDocsImpactGateCommand(string $root, string $base): array
{
    config(['librarian.path' => $root.'/docs']);
    $exitCode = Artisan::call('orbit:docs-impact', ['--base' => $base, '--gate' => true]);

    return [$exitCode, Artisan::output()];
}

function unownedMigrationContents(): string
{
    return <<<'PHP'
<?php
Schema::create('orphaned', function (Blueprint $table): void { $table->string('name'); });
PHP;
}

it('docs impact rejects an invalid base option without a type error', function (mixed $base): void {
    [$root] = docsImpactGateFixture();
    config(['librarian.path' => $root.'/docs']);

    expect(Artisan::call('orbit:docs-impact', ['--base' => $base]))->toBe(1)
        ->and(Artisan::output())->toContain('The --base commit must be a non-empty string.');
})->with(['empty' => [''], 'blank' => ['  '], 'integer' => [42], 'boolean' => [true], 'array' => [['HEAD']]]);

it('docs impact rejects an invalid path option without a type error', function (mixed $path): void {
    [$root] = docsImpactGateFixture();
    config(['librarian.path' => $root.'/docs']);

    expect(Artisan::call('orbit:docs-impact', ['--paths' => [$path]]))->toBe(1)
        ->and(Artisan::output())->toContain('Every --paths value must be a non-empty repository-relative path.');
})->with(['empty' => [''], 'blank' => ['  '], 'missing' => [null], 'integer' => [42], 'array' => [['docs/reference/tasks.md']]]);

it('docs impact reports explicitly selected paths without a base', function (): void {
    [$root] = docsImpactGateFixture();
    config(['librarian.path' => $root.'/docs']);

    expect(Artisan::call('orbit:docs-impact', ['--paths' => ['apps/gateway/app/Domain/Tasks/RunTask.php']]))->toBe(0);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($report['paths'])->toContain('apps/gateway/app/Domain/Tasks/RunTask.php');
});

it('docs impact gate fixtures disable automatic Git maintenance before cleanup', function (): void {
    [$root] = docsImpactGateFixture();
    $settings = [];
    exec('git -C '.escapeshellarg($root).' config --local --get-regexp '.escapeshellarg('^(gc|maintenance)\.auto$'), $settings, $status);

    expect($status)->toBe(0)
        ->and($settings)->toContain('gc.auto 0', 'maintenance.auto false');
});

it('docs impact gate fails and names an impacted page that was not changed', function (): void {
    [$root, $base] = docsImpactGateFixture();
    changeDocsImpactGateSource($root);

    $gate = new DocsImpact($root)->gate($base);

    expect($gate['passed'])->toBeFalse()
        ->and($gate['missing_pages'])->toContain('docs/reference/tasks.md');
});

it('private notes for the checked-out branch cannot waive an impacted page', function (): void {
    [$root, $base] = docsImpactGateFixture();
    changeDocsImpactGateSource($root);
    writeUnaffectedPageNote($root, 'task-1', 'docs/reference/tasks.md: This branch changes only internal behavior.');

    $gate = new DocsImpact($root)->gate($base);

    expect($gate['passed'])->toBeFalse()
        ->and($gate['missing_pages'])->toBe(['docs/reference/tasks.md'])
        ->and($gate['exceptions'])->toBe([]);
});

it('a note for another branch does not waive an impacted page', function (): void {
    [$root, $base] = docsImpactGateFixture();
    changeDocsImpactGateSource($root);
    writeUnaffectedPageNote($root, 'task-2', 'docs/reference/tasks.md: Another branch changed only internal behavior.');
    writeUnaffectedPageNote($root, 't3code/task-1', 'docs/reference/tasks.md: A nested branch name is a different branch.');

    $gate = new DocsImpact($root)->gate($base);

    expect($gate['passed'])->toBeFalse()
        ->and($gate['missing_pages'])->toContain('docs/reference/tasks.md')
        ->and($gate['exceptions'])->toBe([]);
});

it('a detached HEAD has no notes', function (): void {
    [$root, $base] = docsImpactGateFixture();
    exec('git -C '.escapeshellarg($root).' checkout -q --detach');
    changeDocsImpactGateSource($root);
    writeUnaffectedPageNote($root, 'task-1', 'docs/reference/tasks.md: This branch changes only internal behavior.');

    $gate = new DocsImpact($root)->gate($base);

    expect($gate['passed'])->toBeFalse()
        ->and($gate['exceptions'])->toBe([]);
});

it('a committed docs/.docs-unaffected file does not waive an impacted page', function (): void {
    [$root, $base] = docsImpactGateFixture();
    file_put_contents($root.'/docs/.docs-unaffected', "docs/reference/tasks.md: The old committed waiver file.\n");
    exec('git -C '.escapeshellarg($root).' add docs/.docs-unaffected');
    changeDocsImpactGateSource($root);

    $gate = new DocsImpact($root)->gate($base);

    expect($gate['passed'])->toBeFalse()
        ->and($gate['missing_pages'])->toContain('docs/reference/tasks.md')
        ->and($gate['exceptions'])->toBe([]);
});

it('docs impact gate rejects unowned migration errors', function (): void {
    [$root, $base] = docsImpactGateFixture();
    mkdir($root.'/apps/gateway/database/migrations', 0777, true);
    $path = 'apps/gateway/database/migrations/2026_01_01_create_orphans.php';
    file_put_contents($root.'/'.$path, unownedMigrationContents());

    $gate = new DocsImpact($root)->gate($base);

    expect($gate['passed'])->toBeFalse()
        ->and($gate['failures'])->toContain("Unowned migration surface in {$path}; add a matching covers glob.");
});

it('docs impact gate accepts deleting an unowned migration without adding a stale owner', function (): void {
    [$root] = docsImpactGateFixture();
    mkdir($root.'/apps/gateway/database/migrations', 0777, true);
    $path = 'apps/gateway/database/migrations/2026_01_01_create_orphans.php';
    file_put_contents($root.'/'.$path, unownedMigrationContents());
    exec('git -C '.escapeshellarg($root).' add .');
    exec('git -C '.escapeshellarg($root).' -c user.name=Docs -c user.email=docs@example.test commit -qm migration');
    unlink($root.'/'.$path);

    $gate = new DocsImpact($root)->gate('HEAD');

    expect($gate['passed'])->toBeTrue()
        ->and($gate['failures'])->toBe([])
        ->and($gate['report']['surfaces'])->toBe([]);
});

it('docs impact gate still requires the owner page when deleting an owned migration', function (): void {
    [$root] = docsImpactGateFixture();
    mkdir($root.'/apps/gateway/database/migrations', 0777, true);
    $path = 'apps/gateway/database/migrations/2026_01_01_create_orphans.php';
    file_put_contents($root.'/'.$path, unownedMigrationContents());
    file_put_contents($root.'/apps/gateway/database/migrations/2026_01_02_keep_tasks.php', "<?php\n");
    file_put_contents($root.'/docs/reference/tasks.md', "---\ntitle: Tasks\ncovers:\n  - apps/gateway/app/Domain/Tasks/**\n  - apps/gateway/database/migrations/*.php\n---\nTasks\n");
    exec('git -C '.escapeshellarg($root).' add .');
    exec('git -C '.escapeshellarg($root).' -c user.name=Docs -c user.email=docs@example.test commit -qm migration');
    unlink($root.'/'.$path);

    $gate = new DocsImpact($root)->gate('HEAD');

    expect($gate['passed'])->toBeFalse()
        ->and($gate['failures'])->toBe([])
        ->and($gate['missing_pages'])->toBe(['docs/reference/tasks.md']);
});

it('docs impact gate rejects failed generators even when their pages are updated', function (string $path, string $contents, string $page, string $generator): void {
    [$root, $base] = docsImpactGateFixture();
    file_put_contents($root.'/'.$path, $contents);
    file_put_contents($root.'/'.$page, file_get_contents($root.'/'.$page)." Updated for this change.\n");

    $gate = new DocsImpact($root)->gate($base);

    expect($gate['passed'])->toBeFalse()
        ->and($gate['failures'])->toContain($generator.' generator status is missing.');
})->with([
    'MCP' => ['apps/gateway/resources/mcp/tools.json', '{"tools":[{"name":"new-tool","method":"GET","path":"/api/v1/widgets"}]}', 'docs/reference/mcp.mdx', 'bin/mcp-tools'],
    'OpenAPI' => ['docs/openapi.json', '{"openapi":"3.1.0","info":{"title":"Changed","version":"1"},"paths":{}}', 'docs/reference/tasks.md', 'bin/docs-openapi'],
]);

it('docs impact gate command exits nonzero for extractor errors and failed generators', function (string $path, string $contents, string $error): void {
    [$root, $base] = docsImpactGateFixture();
    if (str_contains($path, 'migrations/')) {
        mkdir(dirname($root.'/'.$path), 0777, true);
    }
    file_put_contents($root.'/'.$path, $contents);
    if ($path === 'apps/gateway/resources/mcp/tools.json') {
        file_put_contents($root.'/docs/reference/mcp.mdx', "---\ntitle: MCP\n---\nUpdated MCP documentation.\n");
    }

    [$exitCode, $output] = runDocsImpactGateCommand($root, $base);

    expect($exitCode)->toBe(1)->and($output)->toContain($error);
})->with([
    'migration error' => ['apps/gateway/database/migrations/2026_01_01_create_orphans.php', unownedMigrationContents(), 'Unowned migration surface'],
    'MCP generator failure' => ['apps/gateway/resources/mcp/tools.json', '{"tools":[{"name":"new-tool","method":"GET","path":"/api/v1/widgets"}]}', 'bin/mcp-tools generator status is missing.'],
    'OpenAPI generator failure' => ['docs/openapi.json', '{"openapi":"3.1.0","info":{"title":"Changed","version":"1"},"paths":{}}', 'bin/docs-openapi generator status is missing.'],
]);

it('docs impact gate passes when the impacted page is updated', function (): void {
    [$root, $base] = docsImpactGateFixture();
    changeDocsImpactGateSource($root);
    file_put_contents($root.'/docs/reference/tasks.md', "---\ntitle: Tasks\ncovers:\n  - \"apps/gateway/app/Domain/Tasks/**\"\n---\nUpdated tasks documentation.\n");

    $gate = new DocsImpact($root)->gate($base);

    expect($gate['passed'])->toBeTrue()
        ->and($gate['missing_pages'])->toBe([]);
});

it('an explicit PR body reason waives only its named page on detached CI checkouts', function (): void {
    [$root, $base] = docsImpactGateFixture();
    exec('git -C '.escapeshellarg($root).' checkout -q --detach');
    changeDocsImpactGateSource($root);
    file_put_contents($root.'/docs/reference/second.md', "---\ntitle: Second\ncovers:\n  - apps/gateway/app/Domain/Tasks/**\n---\nSecond\n");
    exec('git -C '.escapeshellarg($root).' add docs/reference/second.md');
    exec('git -C '.escapeshellarg($root).' -c user.name=Docs -c user.email=docs@example.test commit -qm second');
    $body = "Summary\nDocs-unaffected: docs/reference/tasks.md: Restore documented behavior.\r\n";

    $gate = new DocsImpact($root)->gate('HEAD', $body);

    expect($gate['passed'])->toBeFalse()
        ->and($gate['exceptions'])->toBe(['docs/reference/tasks.md' => 'Restore documented behavior.'])
        ->and($gate['missing_pages'])->toBe(['docs/reference/second.md']);
});

it('empty broad and malformed PR reasons do not waive pages', function (string $body): void {
    [$root, $base] = docsImpactGateFixture();
    changeDocsImpactGateSource($root);
    expect(new DocsImpact($root)->gate($base, $body)['passed'])->toBeFalse();
})->with([
    'blank' => 'Docs-unaffected: docs/reference/tasks.md:   ',
    'wildcard' => 'Docs-unaffected: docs/reference/*.md: No change.',
    'global' => 'Docs-unaffected: all: No change.',
    'bare local syntax' => 'docs/reference/tasks.md: No change.',
    'next line' => "Docs-unaffected: docs/reference/tasks.md:\nNo change.",
]);

it('the local gate reads the proposed PR body as data and prints accepted reasons', function (): void {
    [$root, $base] = docsImpactGateFixture();
    changeDocsImpactGateSource($root);
    $bodyFile = $root.'/.git/pr-body.txt';
    $reason = 'Only internal code: $(touch should-not-exist); `false`.';
    file_put_contents($bodyFile, 'Docs-unaffected: docs/reference/tasks.md: '.$reason);
    config(['librarian.path' => $root.'/docs']);

    expect(Artisan::call('orbit:docs-impact', ['--base' => $base, '--gate' => true, '--pr-body-file' => $bodyFile]))->toBe(0);
    $gate = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($gate['exceptions']['docs/reference/tasks.md'])->toBe($reason)
        ->and(file_exists($root.'/should-not-exist'))->toBeFalse();
});

it('a missing PR body file fails instead of silently ignoring its input', function (): void {
    [$root, $base] = docsImpactGateFixture();
    config(['librarian.path' => $root.'/docs']);
    expect(Artisan::call('orbit:docs-impact', ['--base' => $base, '--gate' => true, '--pr-body-file' => $root.'/missing']))->toBe(1)
        ->and(Artisan::output())->toContain('readable PR body file');
});

it('the failure tells contributors the exact visible acknowledgement and local command', function (): void {
    [$root, $base] = docsImpactGateFixture();
    changeDocsImpactGateSource($root);
    [$status, $output] = runDocsImpactGateCommand($root, $base);
    expect($status)->toBe(1)
        ->and($output)->toContain('Docs-unaffected: docs/reference/tasks.md: <explain why no docs change is needed>', '--pr-body-file', 'PR body');
});

it('a stock change with no docs impact passes without a PR body', function (): void {
    [$root, $base] = docsImpactGateFixture();
    file_put_contents($root.'/README.md', 'Internal note.');
    [$status, $output] = runDocsImpactGateCommand($root, $base);
    expect($status)->toBe(0)
        ->and(json_decode($output, true, flags: JSON_THROW_ON_ERROR)['missing_pages'])->toBe([]);
});

it('PR acknowledgements cannot waive generator and ownership failures', function (): void {
    [$root, $base] = docsImpactGateFixture();
    mkdir($root.'/apps/gateway/database/migrations', 0777, true);
    file_put_contents($root.'/apps/gateway/database/migrations/2026_01_01_create_orphans.php', unownedMigrationContents());
    file_put_contents($root.'/apps/gateway/resources/mcp/tools.json', '{"tools":[{"name":"new-tool","method":"GET","path":"/api/v1/widgets"}]}');
    $gate = new DocsImpact($root)->gate($base, 'Docs-unaffected: docs/reference/mcp.mdx: No wording changes.');
    expect($gate['passed'])->toBeFalse()
        ->and($gate['failures'])->toContain('bin/mcp-tools generator status is missing.')
        ->and(implode(' ', $gate['failures']))->toContain('Unowned migration surface');
});

it('local checks and the hook use the proposed body from their environment', function (): void {
    [$root, $base] = docsImpactGateFixture();
    changeDocsImpactGateSource($root);
    $bodyFile = $root.'/.git/proposed-pr-body.txt';
    file_put_contents($bodyFile, 'Docs-unaffected: docs/reference/tasks.md: Fix matches the documented task behavior.');
    $previous = getenv('ORBIT_DOCS_PR_BODY_FILE');
    putenv('ORBIT_DOCS_PR_BODY_FILE='.$bodyFile);
    try {
        [$status, $output] = runDocsImpactGateCommand($root, $base);
        expect($status)->toBe(0)
            ->and(json_decode($output, true, flags: JSON_THROW_ON_ERROR)['exceptions']['docs/reference/tasks.md'])
            ->toBe('Fix matches the documented task behavior.');
        file_put_contents($bodyFile, '');
        expect(runDocsImpactGateCommand($root, $base)[0])->toBe(1);
    } finally {
        putenv($previous === false ? 'ORBIT_DOCS_PR_BODY_FILE' : 'ORBIT_DOCS_PR_BODY_FILE='.$previous);
    }
});

it('the explicit PR body overrides an inherited handoff body and reporting ignores it', function (): void {
    [$root, $base] = docsImpactGateFixture();
    changeDocsImpactGateSource($root);
    $bodyFile = $root.'/.git/actual-pr-body.txt';
    file_put_contents($bodyFile, 'Docs-unaffected: docs/reference/tasks.md: Documentation still describes this fix.');
    config(['librarian.path' => $root.'/docs']);
    $previous = getenv('ORBIT_DOCS_PR_BODY_FILE');
    putenv('ORBIT_DOCS_PR_BODY_FILE='.$root.'/missing');
    try {
        expect(Artisan::call('orbit:docs-impact', ['--base' => $base, '--gate' => true, '--pr-body-file' => $bodyFile]))->toBe(0)
            ->and(Artisan::call('orbit:docs-impact', ['--base' => $base]))->toBe(0);
    } finally {
        putenv($previous === false ? 'ORBIT_DOCS_PR_BODY_FILE' : 'ORBIT_DOCS_PR_BODY_FILE='.$previous);
    }
});

it('a failed CI gate prints the exact failed-job rerun command', function (): void {
    [$root, $base] = docsImpactGateFixture();
    changeDocsImpactGateSource($root);
    $previous = getenv('GITHUB_RUN_ID');
    putenv('GITHUB_RUN_ID=123456789');
    try {
        [$status, $output] = runDocsImpactGateCommand($root, $base);
        expect($status)->toBe(1)
            ->and($output)->toContain('gh run rerun 123456789 --failed', 'Docs-unaffected: docs/reference/tasks.md:', 'live PR body');
    } finally {
        putenv($previous === false ? 'GITHUB_RUN_ID' : 'GITHUB_RUN_ID='.$previous);
    }
});
