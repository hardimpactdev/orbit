<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Apps\AppRuntimeContainerApplyOutcome;
use App\Enums\Apps\AppRuntimeKind;
use App\Models\App;
use App\Models\Node;
use App\Services\Apps\AppInstanceEnvApplier;
use App\Services\Apps\AppRuntimeContainer;
use App\Services\Apps\AppRuntimeContainerRenderer;
use App\Services\Php\PhpRuntimeCatalog;
use App\Services\Php\PhpRuntimePolicy;
use App\Services\Runtime\OrbitContainerNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

describe('AppInstanceEnvApplier', function (): void {
    it('updates the app env file on the owning node', function (): void {
        $node = Node::factory()->gateway()->create(['status' => 'active']);
        $path = storage_path('framework/testing/app-instance-env-applier');
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', <<<'ENV'
            APP_NAME=Docs
            MAIL_MAILER=log
            ENV);

        $app = App::factory()->create([
            'node_id' => $node->id,
            'path' => $path,
            'runtime' => AppRuntimeKind::Static,
        ]);

        $result = app(AppInstanceEnvApplier::class)->apply($app, 'MAIL_MAILER', 'smtp');

        expect($result->envPath)
            ->toBe($path.'/.env')
            ->and($result->cacheCleared)
            ->toBeFalse()
            ->and($result->runtimeOutcome)
            ->toBeNull()
            ->and(File::get($path.'/.env'))
            ->toContain('APP_NAME=Docs')
            ->and(File::get($path.'/.env'))
            ->toContain('MAIL_MAILER=smtp');
    });

    it('clears Laravel caches and reapplies the runtime container for PHP apps', function (): void {
        [$app, $node] = appAndNodeForEnvApplierTest();
        $container = renderEnvApplierTestContainer($app);
        $shell = new AppInstanceEnvApplierRecordingRemoteShell(
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '[{"Id":"sha256:abc"}]', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        );
        app()->instance(RemoteShell::class, $shell);

        $result = app(AppInstanceEnvApplier::class)->apply($app, 'MAIL_MAILER', 'smtp');

        expect($result->cacheCleared)
            ->toBeTrue()
            ->and($result->runtimeOutcome)
            ->toBe(AppRuntimeContainerApplyOutcome::Created)
            ->and($shell->scripts[2])
            ->toContain('php artisan config:clear')
            ->and($shell->scripts[2])
            ->toContain('bootstrap/cache');
    });
});

function appAndNodeForEnvApplierTest(): array
{
    $node = Node::factory()->create(['user' => 'orbit']);
    $app = App::factory()->for($node, 'node')->create([
        'name' => 'billing',
        'path' => '/home/orbit/apps/billing',
        'php_version' => '8.5',
        'runtime' => AppRuntimeKind::Php,
    ]);

    return [$app, $node];
}

function renderEnvApplierTestContainer(App $app): AppRuntimeContainer
{
    return new AppRuntimeContainerRenderer(
        new PhpRuntimePolicy(new PhpRuntimeCatalog),
        new OrbitContainerNames,
    )->render($app);
}

final class AppInstanceEnvApplierRecordingRemoteShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    /**
     * @param  RemoteShellResult|list<RemoteShellResult>  $results
     */
    public function __construct(RemoteShellResult|array $results)
    {
        $this->results = is_array($results) ? $results : [$results];
    }

    /** @var list<RemoteShellResult> */
    private array $results;

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return array_shift($this->results) ?? new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
