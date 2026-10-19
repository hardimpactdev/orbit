<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskCheckOutputBuilder;
use App\Domain\Tasks\TaskPullRequestCheck;
use App\Domain\Tasks\TaskPullRequestCheckOutput;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\GitHub\GitHubTestSupport;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

function check_output_group(): Task
{
    return (new Task)->setRelation('project', new Project(['repository_url' => 'https://github.com/acme/orbit.git']));
}

it('includes the redacted log tail and keeps embedded fences quoted', function (): void {
    $text = "##[error]Early failure\n".str_repeat("before\n", 8000)."```\nLast line";
    $output = app(TaskPullRequestCheckOutput::class)->read(check_output_group(), new TaskPullRequestCheck('Scan', null, outputSummary: $text));

    expect(strlen($output))->toBeLessThan(33 * 1024)
        ->and($output)->toContain('Last line', "\n````\n", "\n```\n")
        ->not->toContain('Early failure')
        ->toEndWith("\n````");
    Http::assertNothingSent();
});

it('redacts complete secrets before truncating the excerpt', function (): void {
    $text = 'TOKEN='.str_repeat('s', 40000)."\nLast line";
    $output = app(TaskPullRequestCheckOutput::class)->read(check_output_group(), new TaskPullRequestCheck('Scan', null, outputSummary: $text));

    expect($output)->toContain('TOKEN=[REDACTED]', 'Last line')->not->toContain('ssss');
});

it('keeps the CI failure after a large PEM without exhausting production regex limits', function (): void {
    GitHubTestSupport::storeApp();
    $text = "-----BEGIN PRIVATE KEY-----\n".str_repeat('s', 100_000)."\n-----END PRIVATE KEY-----\nLast line";
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'installation-secret', 'permissions' => ['actions' => 'read']]),
        'https://api.github.com/repos/acme/orbit/actions/jobs/7/logs' => Http::response('', 302, ['Location' => 'https://logs.blob.core.windows.net/job']),
        'https://logs.blob.core.windows.net/job' => Http::response($text),
    ]);
    $limit = ini_set('pcre.backtrack_limit', '1000000');
    try {
        $output = app(TaskPullRequestCheckOutput::class)->read(check_output_group(), new TaskPullRequestCheck('Job', null, 7, 'github-actions'));

        expect($output)->toContain('[REDACTED]', 'Last line')->not->toContain('ssss');
    } finally {
        ini_set('pcre.backtrack_limit', $limit);
    }
});

it('refuses unsafe log redirects and unreadable storage bodies without leaking details', function (string $location, int $status, string $body): void {
    GitHubTestSupport::storeApp();
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'installation-secret', 'permissions' => ['actions' => 'read']]),
        'https://api.github.com/repos/acme/orbit/actions/jobs/7/logs' => Http::response('', 302, ['Location' => $location]),
        'https://logs.blob.core.windows.net/job?sig=storage-secret' => Http::response($body, $status),
    ]);

    $output = app(TaskPullRequestCheckOutput::class)->read(check_output_group(), new TaskPullRequestCheck('Job', null, 7, 'github-actions'));

    expect($output)->toBe('Orbit could not read the log.');
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'untrusted.example'));
})->with([
    'untrusted host' => ['https://untrusted.example/job?sig=storage-secret', 200, 'private-output'],
    'HTTP' => ['http://logs.blob.core.windows.net/job', 200, 'private-output'],
    'credentials' => ['https://installation-secret@logs.blob.core.windows.net/job', 200, 'private-output'],
    'port' => ['https://logs.blob.core.windows.net:443/job', 200, 'private-output'],
    'storage error' => ['https://logs.blob.core.windows.net/job?sig=storage-secret', 403, 'private-output'],
    'second redirect' => ['https://logs.blob.core.windows.net/job?sig=storage-secret', 302, 'private-output'],
]);

it('reports missing Actions job identities or empty check summaries without reading URLs', function (TaskPullRequestCheck $check): void {
    expect(app(TaskPullRequestCheckOutput::class)->read(check_output_group(), $check))->toBe('Orbit could not read the log.');
    Http::assertNothingSent();
})->with([
    'no job id' => [new TaskPullRequestCheck('Job', 'https://untrusted.example', appSlug: 'github-actions')],
    'invalid job id' => [new TaskPullRequestCheck('Job', null, 0, 'github-actions')],
    'empty summary' => [new TaskPullRequestCheck('Scan', 'https://untrusted.example')],
]);

it('keeps redacted failure evidence after more than 16 MiB of CI output', function (): void {
    GitHubTestSupport::storeApp();
    $text = str_repeat(str_repeat('p', 1000)."\n", 18_000).'TOKEN='.str_repeat('s', 40_000)."\nFAILED last line";
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'installation-secret', 'permissions' => ['actions' => 'read']]),
        'https://api.github.com/repos/acme/orbit/actions/jobs/7/logs' => Http::response('', 302, ['Location' => 'https://logs.blob.core.windows.net/job']),
        'https://logs.blob.core.windows.net/job' => Http::response($text),
    ]);

    $output = app(TaskPullRequestCheckOutput::class)->read(check_output_group(), new TaskPullRequestCheck('Job', null, 7, 'github-actions'));

    expect($output)->toContain('FAILED last line', 'TOKEN=[REDACTED]')->not->toContain('ssss')
        ->and(strlen($output))->toBeLessThan(17 * 1024);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://logs.blob.core.windows.net/job' && ! $request->hasHeader('Authorization'));
});

it('uses the shared redactor on the raw CI window across transport chunks', function (string $secret): void {
    GitHubTestSupport::storeApp();
    $stream = fopen('php://temp', 'w+');
    fwrite($stream, str_repeat('p', 65_530)."\n".$secret."\nFAILED last line");
    rewind($stream);
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'installation-secret', 'permissions' => ['actions' => 'read']]),
        'https://api.github.com/repos/acme/orbit/actions/jobs/7/logs' => Http::response('', 302, ['Location' => 'https://logs.blob.core.windows.net/job']),
        'https://logs.blob.core.windows.net/job' => Http::response($stream),
    ]);

    $output = app(TaskPullRequestCheckOutput::class)->read(check_output_group(), new TaskPullRequestCheck('Job', null, 7, 'github-actions'));

    expect($output)->not->toContain('ssss')
        ->and(strlen($output))->toBeLessThan(17 * 1024)
        ->and(preg_match('//u', $output))->toBe(1);
    expect($output)->toContain('FAILED last line');
})->with([
    'multibyte output' => [str_repeat('🪐', 10_000)],
    'Authorization Bearer' => ['Authorization: Bearer '.str_repeat('s', 100_000)],
    'multiline quoted Bearer' => ['Bearer "'.str_repeat(str_repeat('s', 1000)."\n", 100).'"'],
    'pending JSON separator' => ["\"token\"\n:\n\"".str_repeat('s', 4000).'"'],
    'pending assignment separator' => ["TOKEN\n=\n".str_repeat('s', 100_000)],
    'pending header separator' => ["Authorization\n:\nBasic ".str_repeat('s', 100_000)],
    'pending key value' => ["TOKEN=\n".str_repeat('s', 100_000)],
    'pending Authorization value' => ["Authorization: Basic\n".str_repeat('s', 100_000)],
    'multiline quoted secret' => ['TOKEN="'.str_repeat(str_repeat('s', 1000)."\n", 100).'"'],
    'escaped multiline JSON' => ['{"token": "'.str_repeat('s\\"'.str_repeat('s', 997)."\n", 4).'"}'],
    'encrypted PEM' => ["-----BEGIN RSA PRIVATE KEY-----\nProc-Type: 4,ENCRYPTED\nDEK-Info: AES-256-CBC,0123456789ABCDEF\n\n".str_repeat(str_repeat('s', 1000)."\n", 100).'-----END RSA PRIVATE KEY-----'],
]);

it('drops a partial first raw line and keeps the final CI failure as valid UTF-8', function (): void {
    $output = app(TaskCheckOutputBuilder::class)->buildStream(['🪐'.str_repeat('s', 1048576)."\nFAILED last line"]);

    expect(preg_match('//u', $output))->toBe(1)
        ->and($output)->toBe("```\nFAILED last line\n```");
});
