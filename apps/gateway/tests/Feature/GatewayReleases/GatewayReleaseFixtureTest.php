<?php

declare(strict_types=1);

use App\Infrastructure\Processes\ProcessInvocation;
use Tests\Support\GatewayReleaseFixture;

pest()->group('subprocess');

it('omits host INI startup only for the core-only Artisan interpreter', function (): void {
    $fixture = GatewayReleaseFixture::create();
    $input = str_repeat(implode('', array_map(chr(...), range(0, 255))), 257)."\0\n\n";
    $arguments = ['', "spaces and\nlines", '☄'];
    $source = <<<'PHP'
        fwrite(STDOUT, stream_get_contents(STDIN));
        fwrite(STDERR, json_encode([
            array_slice($argv, 1), getenv('FIXTURE_VALUE'), php_ini_loaded_file(), php_ini_scanned_files(),
        ], JSON_THROW_ON_ERROR));
        exit(7);
        PHP;

    try {
        $result = $fixture->run(new ProcessInvocation(
            arguments: [$fixture->php, '-r', $source, '--', ...$arguments],
            input: $input,
            environment: ['FIXTURE_VALUE' => "spaces and\nlines"],
        ));
        expect($result->exitCode)->toBe(7)
            ->and($result->stdout)->toBe($input)
            ->and(json_decode($result->stderr, true, flags: JSON_THROW_ON_ERROR))->toBe([
                $arguments, "spaces and\nlines", false, false,
            ])
            ->and(fileperms($fixture->php) & 0o777)->toBe(0o500);

        $native = $fixture->run(new ProcessInvocation(arguments: [PHP_BINARY, '-r',
            'echo json_encode([php_ini_loaded_file(), php_ini_scanned_files()], JSON_THROW_ON_ERROR);',
        ]));
        expect($native->exitCode)->toBe(0)
            ->and(json_decode($native->stdout, true, flags: JSON_THROW_ON_ERROR))->toBe([
                php_ini_loaded_file(), php_ini_scanned_files(),
            ]);
    } finally {
        $fixture->cleanup();
    }
});

it('copies immutable Git seeds into independent origins repositories and shared files', function (): void {
    $first = GatewayReleaseFixture::create();
    $second = GatewayReleaseFixture::create();
    $third = null;

    try {
        $initial = trim($second->git($second->origin, 'rev-parse', 'HEAD'));
        expect(trim($first->git($first->origin, 'rev-parse', 'HEAD')))->toBe($initial)
            ->and(trim($first->git($first->layout->repositoryPath(), 'remote', 'get-url', 'origin')))->toBe($first->origin)
            ->and(trim($second->git($second->layout->repositoryPath(), 'remote', 'get-url', 'origin')))->toBe($second->origin)
            ->and(fileperms($first->layout->sharedPath()) & 0o777)->toBe(0o700);

        $first->write('case-only', 'private change');
        $changed = $first->commit('Private seed-copy change');
        $first->git($first->layout->repositoryPath(), 'fetch', '--quiet', 'origin', '+refs/heads/main:refs/heads/main');
        file_put_contents($first->layout->environmentPath(), 'private environment');

        expect($changed)->not->toBe($initial)
            ->and(trim($first->git($first->layout->repositoryPath(), 'rev-parse', 'main')))->toBe($changed)
            ->and(trim($second->git($second->origin, 'rev-parse', 'HEAD')))->toBe($initial)
            ->and(trim($second->git($second->layout->repositoryPath(), 'rev-parse', 'main')))->toBe($initial)
            ->and(is_file($second->origin.'/case-only'))->toBeFalse()
            ->and(file_get_contents($second->layout->environmentPath()))->toBe("APP_ENV=production\n");

        $first->cleanup();
        $third = GatewayReleaseFixture::create();
        expect(trim($third->git($third->origin, 'rev-parse', 'HEAD')))->toBe($initial)
            ->and(trim($third->git($third->layout->repositoryPath(), 'rev-parse', 'main')))->toBe($initial)
            ->and(is_file($third->origin.'/case-only'))->toBeFalse()
            ->and(file_get_contents($third->layout->environmentPath()))->toBe("APP_ENV=production\n")
            ->and(trim($second->git($second->origin, 'rev-parse', 'HEAD')))->toBe($initial);
    } finally {
        $first->cleanup();
        $second->cleanup();
        $third?->cleanup();
    }
});

it('copies adopted releases without retaining a seed or another cases Git worktree paths', function (): void {
    $first = GatewayReleaseFixture::create(adopted: true);
    $second = GatewayReleaseFixture::create(adopted: true);
    $third = null;
    try {
        $sha = trim($first->git($first->layout->currentPath(), 'rev-parse', 'HEAD'));
        foreach ([$first, $second] as $fixture) {
            $current = $fixture->layout->currentPath();
            $administrativePath = trim($fixture->git($current, 'rev-parse', '--git-dir'));
            $release = $fixture->layout->releasePath(substr($sha, 0, 12));
            expect($administrativePath)->toStartWith($fixture->layout->repositoryPath().'/worktrees/')
                ->and(file_get_contents($administrativePath.'/gitdir'))->toBe($release."/.git\n")
                ->and(trim($fixture->git($current, 'rev-parse', '--git-common-dir')))->toBe($fixture->layout->repositoryPath())
                ->and(trim($fixture->git($current, 'status', '--porcelain')))->toBe('')
                ->and(readlink($current))->toBe($fixture->layout->linkTarget(substr($sha, 0, 12)))
                ->and(fileperms($release.'/.git') & 0200)->toBe(0)
                ->and(readlink($release.'/apps/gateway/.env'))->toBe($fixture->layout->environmentPath())
                ->and(readlink($release.'/apps/gateway/storage'))->toBe($fixture->layout->storagePath())
                ->and(fileperms($release.'/apps/gateway') & 0200)->toBe(0);
            $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture->base, FilesystemIterator::SKIP_DOTS));
            foreach ($entries as $entry) {
                if ($entry->isLink()) {
                    expect(realpath($entry->getPathname()))->toStartWith($fixture->base.'/');
                }
            }
        }
        $path = $first->layout->currentPath().'/apps/gateway/public/index.php';
        chmod($path, 0644);
        file_put_contents($path, "<?php // private case\n");
        $first->git($first->layout->currentPath(), 'update-ref', 'refs/fixture/private', $sha);
        file_put_contents($first->layout->currentPath().'/apps/gateway/.env', 'private adopted environment');
        file_put_contents($first->layout->currentPath().'/apps/gateway/storage/logs/case-only.log', 'private storage');
        expect($first->git($first->layout->currentPath(), 'status', '--porcelain'))->toContain('apps/gateway/public/index.php')
            ->and(trim($second->git($second->layout->currentPath(), 'status', '--porcelain')))->toBe('')
            ->and($second->git($second->layout->currentPath(), 'for-each-ref', 'refs/fixture'))->toBe('')
            ->and(file_get_contents($second->layout->environmentPath()))->toBe("APP_ENV=production\n")
            ->and(is_file($second->layout->currentPath().'/apps/gateway/storage/logs/case-only.log'))->toBeFalse();

        $first->cleanup();
        $third = GatewayReleaseFixture::create(adopted: true);
        expect(trim($third->git($third->layout->currentPath(), 'rev-parse', 'HEAD')))->toBe($sha)
            ->and(trim($third->git($third->layout->currentPath(), 'status', '--porcelain')))->toBe('')
            ->and($third->git($third->layout->currentPath(), 'for-each-ref', 'refs/fixture'))->toBe('')
            ->and(file_get_contents($third->layout->currentPath().'/apps/gateway/public/index.php'))->toBe("<?php\n")
            ->and(file_get_contents($third->layout->environmentPath()))->toBe("APP_ENV=production\n")
            ->and(is_file($third->layout->currentPath().'/apps/gateway/storage/logs/case-only.log'))->toBeFalse()
            ->and(trim($second->git($second->layout->currentPath(), 'rev-parse', 'HEAD')))->toBe($sha);
    } finally {
        $first->cleanup();
        $second->cleanup();
        $third?->cleanup();
    }
});

it('removes frozen directories without changing file modes through private hard links', function (int $mode): void {
    $fixture = GatewayReleaseFixture::create();
    $outside = $fixture->base.'-linked-directory';
    try {
        mkdir($outside, 0o700);
        file_put_contents($outside.'/file', 'keep these bytes');
        chmod($outside.'/file', 0o444);
        mkdir($fixture->base.'/frozen/nested', 0o700, true);
        link($outside.'/file', $fixture->base.'/frozen/nested/file');
        symlink($outside, $fixture->base.'/frozen/nested/outside');
        chmod($outside, 0o500);
        chmod($fixture->base.'/frozen/nested', $mode);
        chmod($fixture->base.'/frozen', $mode);

        $fixture->cleanup();
        clearstatcache();
        expect(is_dir($fixture->base))->toBeFalse()
            ->and(fileperms($outside) & 0o777)->toBe(0o500)
            ->and(fileperms($outside.'/file') & 0o777)->toBe(0o444)
            ->and(file_get_contents($outside.'/file'))->toBe('keep these bytes');
    } finally {
        $fixture->cleanup();
        if (is_dir($outside)) {
            chmod($outside, 0o700);
            unlink($outside.'/file');
            rmdir($outside);
        }
    }
})->with(['read-only' => 0o500, 'traversal-only' => 0o100]);
