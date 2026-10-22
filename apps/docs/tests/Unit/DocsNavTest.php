<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

function docsNavFixture(): string
{
    $root = sys_get_temp_dir().'/docs-nav-'.bin2hex(random_bytes(6));
    foreach (['decisions', 'domains', 'solutions', 'reference'] as $folder) {
        mkdir($root.'/docs/'.$folder, 0777, true);
    }
    foreach (['README', 'decisions/README', 'domains/README', 'solutions/README', 'powersync-path-proxy-STATUS', 'index', 'reference/routes'] as $page) {
        file_put_contents($root.'/docs/'.$page.'.md', "# Page\n");
    }
    docsNavWriteNavigation($root, ['index', 'reference/routes']);

    return $root;
}

/** @param list<string> $pages */
function docsNavWriteNavigation(string $root, array $pages): void
{
    file_put_contents($root.'/docs/docs.json', json_encode([
        'navigation' => ['tabs' => [['tab' => 'Documentation', 'groups' => [['group' => 'Start', 'pages' => $pages]]]]],
    ], JSON_THROW_ON_ERROR));
}

function docsNavCheck(?string $root = null): Process
{
    $process = new Process([PHP_BINARY, dirname(__DIR__, 4).'/bin/docs-nav', ...($root === null ? [] : ['--root', $root])]);
    $process->setTimeout(60);
    $process->run();

    return $process;
}

function docsNavRemove(string $root): void
{
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        if ($file instanceof SplFileInfo) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
    }
    rmdir($root);
}

it('passes when every page is in the navigation or allowed outside it', function (): void {
    $root = docsNavFixture();
    try {
        $process = docsNavCheck($root);

        expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());
    } finally {
        docsNavRemove($root);
    }
});

it('fails for a page that exists only on disk', function (): void {
    $root = docsNavFixture();
    try {
        file_put_contents($root.'/docs/reference/lost.mdx', "# Lost\n");

        $process = docsNavCheck($root);

        expect($process->getExitCode())->toBe(1)
            ->and($process->getOutput())->toContain('docs/reference/lost.mdx: page is not in the docs.json navigation');
    } finally {
        docsNavRemove($root);
    }
});

it('fails when an allowed page is gone or has joined the navigation', function (): void {
    $root = docsNavFixture();
    try {
        unlink($root.'/docs/solutions/README.md');
        docsNavWriteNavigation($root, ['index', 'reference/routes', 'domains/README']);

        $process = docsNavCheck($root);

        expect($process->getExitCode())->toBe(1)
            ->and($process->getOutput())
            ->toContain('bin/docs-nav: solutions/README is allowed outside the navigation but has no page')
            ->toContain('bin/docs-nav: domains/README is in the navigation, so remove it from the allowed list');
    } finally {
        docsNavRemove($root);
    }
});

it('accepts this repository', function (): void {
    $process = docsNavCheck();

    expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());
});
