<?php

declare(strict_types=1);

use App\Actions\Projects\CaptureProjectSourceFactsAction;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Projects\ProjectSourceReader;
use App\Domain\Projects\ProjectType;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\SourceControl\GitProjectSourceReader;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Support\Facades\File;
use Tests\Support\FakeProjectSourceReader;

beforeEach(function (): void {
    $this->operator = $this->markAsGateway(Node::query()->create(['name' => 'operator', 'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.2', 'wireguard_ip' => '10.44.0.2']));
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
    $this->fakeRepositoryBranches();
    $this->source = new FakeProjectSourceReader([
        'apps/site/.env.example' => ['mode' => '100644', 'content' => "DB_CONNECTION=sqlite\n"],
        'apps/site/composer.json' => ['mode' => '100644', 'content' => '{"require":{"php":"^8.4","laravel/framework":"^12.0"}}'],
        'apps/site/artisan' => ['mode' => '100755', 'content' => '<?php'],
    ]);
    app()->instance(ProjectSourceReader::class, $this->source);
});

it('captures the PHP version, Laravel flag and environment template from the application directory when a Project is added', function (): void {
    $this->postJson('/api/v1/projects', ['slug' => 'site', 'type' => 'laravel-app', 'repository_url' => 'https://github.com/acme/site.git',
        'default_branch' => 'main', 'root' => 'apps/site/public'])->assertCreated()
        ->assertJsonPath('data.is_laravel', true)->assertJsonPath('data.php_version', Project::query()->sole()->php_version);
    $project = Project::query()->sole();
    expect($project->php_version)->not->toBeNull()
        ->and($project->environmentTemplate->entries)->toBe([['key' => 'DB_CONNECTION', 'value' => 'sqlite', 'example' => 'sqlite', 'removed' => false]])
        ->and($project->environmentTemplate->source_path)->toBe('apps/site/.env.example')
        ->and($this->source->reads[0])->toBe(['repository' => 'https://github.com/acme/site.git', 'branch' => 'main',
            'paths' => ['apps/site/.env.example', 'apps/site/composer.json', 'apps/site/artisan']]);
});

it('refreshes captured facts and the template when the source changes', function (): void {
    $this->postJson('/api/v1/projects', ['slug' => 'site', 'type' => 'laravel-app', 'repository_url' => 'https://github.com/acme/site.git',
        'default_branch' => 'main', 'root' => 'apps/site/public'])->assertCreated();
    $project = Project::query()->sole();
    $this->source->files['apps/site/.env.example']['content'] = "DB_CONNECTION=mysql\n";
    $this->source->head = str_repeat('b', 40);
    $this->patchJson("/api/v1/projects/{$project->id}", ['default_branch' => 'next'])->assertOk();
    expect(end($this->source->reads)['branch'])->toBe('next')
        ->and($project->refresh()->environmentTemplate->entries[0]['value'])->toBe('mysql')
        ->and($project->environmentTemplate->source_commit)->toBe(str_repeat('b', 40));
});

it('keeps no template from a committed .env.example that is not a regular file', function (): void {
    $this->source->files['.env.example'] = ['mode' => '120000', 'content' => ''];
    $this->postJson('/api/v1/projects', ['slug' => 'node', 'type' => 'node-package', 'repository_url' => 'https://github.com/acme/node.git',
        'default_branch' => 'main', 'root' => '.'])->assertCreated();
    expect(Project::query()->sole()->environmentTemplate->entries)->toBe([]);
});

it('skips the PHP profile of a monorepo root, as legacy create did', function (): void {
    $this->source->files['composer.json'] = ['mode' => '100644', 'content' => 'not composer metadata'];
    $facts = app(CaptureProjectSourceFactsAction::class)->read('https://github.com/acme/site.git', ProjectSourceAccess::GitHubApp, 'main', '.', ProjectType::Monorepo)['facts'];
    expect($facts['php_version'])->toBeNull()->and($facts['is_laravel'])->toBeFalse();
});

it('reads committed files from the branch head without blobs it does not need', function (): void {
    $origin = storage_path('framework/testing-source-'.bin2hex(random_bytes(4)));
    File::ensureDirectoryExists($origin);
    $git = static fn (string ...$arguments): string => trim((string) shell_exec('git -C '.escapeshellarg($origin).' '.implode(' ', array_map(escapeshellarg(...), $arguments)).' 2>&1'));
    $git('init', '-q', '--initial-branch=main');
    $git('config', 'uploadpack.allowFilter', 'true');
    file_put_contents($origin.'/.env.example', "APP_NAME=Committed\n");
    symlink('.env.example', $origin.'/artisan');
    $git('add', '.');
    $git('-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.test', 'commit', '-qm', 'fixture');
    try {
        $files = new GitProjectSourceReader(app(ProcessRunner::class), app(App\Domain\GitHub\RepositoryReadAccess::class))
            ->read('file://'.$origin, ProjectSourceAccess::GitHubApp, 'main', ['.env.example', 'composer.json', 'artisan']);
    } finally {
        File::deleteDirectory($origin);
    }
    expect($files['head'])->toMatch('/\A[0-9a-f]{40}\z/')
        ->and($files['files']['.env.example'])->toMatchArray(['mode' => '100644', 'content' => "APP_NAME=Committed\n"])
        ->and($files['files']['composer.json'])->toBeNull()
        ->and($files['files']['artisan']['mode'])->toBe('120000');
});

it('records an unsupported PHP constraint as a fact instead of refusing the Project', function (): void {
    $this->source->files['apps/site/composer.json']['content'] = '{"require":{"php":"^7.4","laravel/framework":"^12.0"}}';
    $this->postJson('/api/v1/projects', ['slug' => 'site', 'type' => 'laravel-app', 'repository_url' => 'https://github.com/acme/site.git',
        'default_branch' => 'main', 'root' => 'apps/site/public'])->assertCreated();
    expect(Project::query()->sole()->source_profile_error)->toBe('app-dev.php_version_unsupported')
        ->and(Project::query()->sole()->php_version)->toBeNull();
});

it('reads the changed source before an update commits, so a failed read changes nothing', function (): void {
    $this->postJson('/api/v1/projects', ['slug' => 'site', 'type' => 'laravel-app', 'repository_url' => 'https://github.com/acme/site.git',
        'default_branch' => 'main', 'root' => 'apps/site/public'])->assertCreated();
    $project = Project::query()->sole();
    app()->instance(ProjectSourceReader::class, new class implements ProjectSourceReader
    {
        public function read(string $repositoryUrl, ProjectSourceAccess $access, string $branch, array $paths): array
        {
            throw new App\Domain\Shared\ResourceOperationException('project.source_unavailable', 'unreadable', 502);
        }
    });
    $this->patchJson("/api/v1/projects/{$project->id}", ['default_branch' => 'next'])->assertStatus(502);
    expect($project->refresh()->default_branch)->toBe('main');
});
