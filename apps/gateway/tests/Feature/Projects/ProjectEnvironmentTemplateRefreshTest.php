<?php

declare(strict_types=1);

use App\Domain\GitHub\GitHubApi;
use App\Domain\Projects\ProjectEnvironmentTemplateRefresh;
use App\Models\Project;
use App\Models\ProjectEnvironmentTemplate;
use Illuminate\Support\Facades\Http;
use Tests\Feature\GitHub\GitHubTestSupport;

beforeEach(function (): void {
    Http::preventStrayRequests();
    GitHubTestSupport::storeApp();
    $this->project = Project::query()->create([
        'name' => 'Shop', 'slug' => 'shop', 'repository_url' => 'https://github.com/acme/shop.git',
        'source_access' => 'github_app', 'default_branch' => 'main', 'root' => 'public',
    ]);
    $this->github = Mockery::mock(GitHubApi::class);
    $this->github->shouldReceive('repositoryInstallation')->andReturn(17);
    $this->github->shouldReceive('repositoryReadToken')->andReturn('contents-token');
    app()->instance(GitHubApi::class, $this->github);
    $this->refresh = fn (string $head) => app(ProjectEnvironmentTemplateRefresh::class)->observe($this->project->fresh(), $head);
    $this->template = fn (): ProjectEnvironmentTemplate => ProjectEnvironmentTemplate::query()->where('project_id', $this->project->id)->sole();
    $this->file = function (string $head, string $blob, string $contents): void {
        $this->github->shouldReceive('fileBlob')->once()->with('contents-token', Mockery::any(), $head, '.env.example')->andReturn($blob);
        $this->github->shouldReceive('blobContents')->once()->with('contents-token', Mockery::any(), $blob)->andReturn($contents);
    };
});

it('builds the template from the committed .env.example and records its commit and blob', function (): void {
    ($this->file)(str_repeat('a', 40), str_repeat('1', 40), "APP_NAME=Shop\nAPP_URL=\"http://localhost\"\nMAIL_FROM=\"\${APP_NAME} <a@b.test>\"\n");

    ($this->refresh)(str_repeat('a', 40));

    $template = ($this->template)();
    expect($template->source_path)->toBe('.env.example')
        ->and($template->source_commit)->toBe(str_repeat('a', 40))
        ->and($template->source_blob)->toBe(str_repeat('1', 40))
        ->and($template->checked_head)->toBe(str_repeat('a', 40))
        ->and(array_column($template->entries, 'value', 'key'))->toBe(['APP_NAME' => 'Shop', 'APP_URL' => 'http://localhost', 'MAIL_FROM' => '${APP_NAME} <a@b.test>']);
});

it('does nothing for a head it already checked', function (): void {
    ($this->file)(str_repeat('a', 40), str_repeat('1', 40), "APP_NAME=Shop\n");
    ($this->refresh)(str_repeat('a', 40));

    ($this->refresh)(str_repeat('a', 40));

    $this->github->shouldNotHaveReceived('fileBlob', [Mockery::any(), Mockery::any(), str_repeat('b', 40), Mockery::any()]);
    expect(($this->template)()->checked_head)->toBe(str_repeat('a', 40));
});

it('compares only the blob SHA on a new head when .env.example is unchanged', function (): void {
    ($this->file)(str_repeat('a', 40), str_repeat('1', 40), "APP_NAME=Shop\n");
    ($this->refresh)(str_repeat('a', 40));
    $this->github->shouldReceive('fileBlob')->once()->with('contents-token', Mockery::any(), str_repeat('b', 40), '.env.example')->andReturn(str_repeat('1', 40));

    ($this->refresh)(str_repeat('b', 40));

    $template = ($this->template)();
    expect($template->checked_head)->toBe(str_repeat('b', 40))
        ->and($template->source_commit)->toBe(str_repeat('a', 40))
        ->and($template->source_blob)->toBe(str_repeat('1', 40));
});

it('adds new keys, flags removed keys and keeps them, and keeps edited values', function (): void {
    ($this->file)(str_repeat('a', 40), str_repeat('1', 40), "APP_NAME=Shop\nAPP_URL=http://localhost\nLEGACY=1\nQUEUE=sync\n");
    ($this->refresh)(str_repeat('a', 40));
    $template = ($this->template)();
    $template->update(['entries' => array_map(static fn (array $entry): array => $entry['key'] === 'APP_URL'
        ? [...$entry, 'value' => 'https://{{instance.domain}}'] : $entry, $template->entries)]);
    ($this->file)(str_repeat('b', 40), str_repeat('2', 40), "APP_NAME=Shop\nAPP_URL=http://example.test\nQUEUE=redis\nCACHE=file\n");

    ($this->refresh)(str_repeat('b', 40));

    $template = ($this->template)();
    expect($template->entries)->toBe([
        ['key' => 'APP_NAME', 'value' => 'Shop', 'example' => 'Shop', 'removed' => false],
        ['key' => 'APP_URL', 'value' => 'https://{{instance.domain}}', 'example' => 'http://example.test', 'removed' => false],
        ['key' => 'QUEUE', 'value' => 'redis', 'example' => 'redis', 'removed' => false],
        ['key' => 'CACHE', 'value' => 'file', 'example' => 'file', 'removed' => false],
        ['key' => 'LEGACY', 'value' => '1', 'example' => '1', 'removed' => true],
    ])->and($template->source_commit)->toBe(str_repeat('b', 40))
        ->and($template->source_blob)->toBe(str_repeat('2', 40));
});

it('keeps every key, flagged removed, when .env.example is deleted', function (): void {
    ($this->file)(str_repeat('a', 40), str_repeat('1', 40), "APP_NAME=Shop\n");
    ($this->refresh)(str_repeat('a', 40));
    $this->github->shouldReceive('fileBlob')->once()->with('contents-token', Mockery::any(), str_repeat('b', 40), '.env.example')->andReturnNull();

    ($this->refresh)(str_repeat('b', 40));

    $template = ($this->template)();
    expect($template->entries)->toBe([['key' => 'APP_NAME', 'value' => 'Shop', 'example' => 'Shop', 'removed' => true]])
        ->and($template->source_blob)->toBeNull();
});

it('reads the .env.example of a nested application', function (): void {
    $this->project->update(['root' => 'apps/web/public']);
    $this->github->shouldReceive('fileBlob')->once()->with('contents-token', Mockery::any(), str_repeat('a', 40), 'apps/web/.env.example')->andReturn(str_repeat('1', 40));
    $this->github->shouldReceive('blobContents')->once()->andReturn("APP_NAME=Web\n");

    ($this->refresh)(str_repeat('a', 40));

    expect(($this->template)()->source_path)->toBe('apps/web/.env.example');
});

it('keeps the template on an invalid file and checks the next head again', function (): void {
    ($this->file)(str_repeat('a', 40), str_repeat('1', 40), "APP_NAME=Shop\n");
    ($this->refresh)(str_repeat('a', 40));
    ($this->file)(str_repeat('b', 40), str_repeat('2', 40), "APP_NAME=\"unterminated\n");

    ($this->refresh)(str_repeat('b', 40));

    $template = ($this->template)();
    expect($template->checked_head)->toBe(str_repeat('b', 40))
        ->and($template->source_blob)->toBe(str_repeat('1', 40))
        ->and(array_column($template->entries, 'value', 'key'))->toBe(['APP_NAME' => 'Shop']);
});

it('skips Projects without GitHub App access', function (): void {
    $this->project->update(['source_access' => 'gh_cli']);

    ($this->refresh)(str_repeat('a', 40));

    $this->github->shouldNotHaveReceived('fileBlob');
    expect(ProjectEnvironmentTemplate::query()->count())->toBe(0);
});

it('skips a repository without a GitHub App installation', function (): void {
    $github = Mockery::mock(GitHubApi::class);
    $github->shouldReceive('repositoryInstallation')->once()->andReturnNull();
    app()->instance(GitHubApi::class, $github);

    ($this->refresh)(str_repeat('a', 40));

    $github->shouldNotHaveReceived('fileBlob');
    expect(ProjectEnvironmentTemplate::query()->count())->toBe(0);
});
