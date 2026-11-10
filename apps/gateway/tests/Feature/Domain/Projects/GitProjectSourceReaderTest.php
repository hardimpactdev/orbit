<?php

declare(strict_types=1);

use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\SourceControl\GitProjectSourceReader;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

it('reads source files from the requested revision after the branch advances', function (bool $pinned): void {
    $directory = storage_path('framework/source-reader-test-'.Str::uuid());
    File::ensureDirectoryExists($directory);
    $git = static function (array $arguments) use ($directory): string {
        $result = app(ProcessRunner::class)->run(new ProcessInvocation(
            ['git', '-c', 'user.name=Source reader test', '-c', 'user.email=test@example.com', '-C', $directory, ...$arguments]));
        expect($result->exitCode)->toBe(0, $result->stderr);

        return trim($result->stdout);
    };
    try {
        $git(['init', '--quiet', '--initial-branch=main']);
        File::put($directory.'/composer.json', '{"require":{"php":"~8.4.0"}}');
        $git(['add', 'composer.json']);
        $git(['commit', '--quiet', '-m', 'First version']);
        $first = $git(['rev-parse', 'HEAD']);
        File::put($directory.'/composer.json', '{"require":{"php":"^8.5"}}');
        File::put($directory.'/.env.example', "APP_NAME=new-head\n");
        $git(['add', 'composer.json', '.env.example']);
        $git(['commit', '--quiet', '-m', 'New PHP version']);
        $latest = $git(['rev-parse', 'HEAD']);

        $read = app(GitProjectSourceReader::class)->read($directory, ProjectSourceAccess::GitHubApp, 'main',
            ['composer.json', '.env.example'], $pinned ? $first : null);

        expect($read['head'])->toBe($pinned ? $first : $latest);
        expect($read['files']['composer.json']['content'])->toBe($pinned ? '{"require":{"php":"~8.4.0"}}' : '{"require":{"php":"^8.5"}}');
        expect($read['files']['.env.example']['content'] ?? null)->toBe($pinned ? null : "APP_NAME=new-head\n");
    } finally {
        File::deleteDirectory($directory);
    }
})->with(['pinned older commit' => true, 'current branch head' => false]);

it('refuses an unavailable commit instead of reading the branch head', function (): void {
    $this->mock(ProcessRunner::class)->shouldReceive('run')->andReturnUsing(static function (ProcessInvocation $invocation) {
        $fetch = in_array('fetch', $invocation->arguments, true);

        return new CommandResult($fetch ? 128 : 0, '', '', 0, false);
    });
    $refusal = null;

    try {
        app(GitProjectSourceReader::class)->read('https://example.com/source.git', ProjectSourceAccess::GitHubApp, 'main', ['composer.json'], str_repeat('a', 40));
    } catch (ResourceOperationException $exception) {
        $refusal = $exception;
    }

    expect($refusal)->not->toBeNull()->errorCode->toBe('project.source_unavailable')->status->toBe(502);
});
