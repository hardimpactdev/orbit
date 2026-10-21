<?php

declare(strict_types=1);

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\WebSocket\NativeWebSocketRouteInspector;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

it('inspects the current release route list as the production user without exposing application output', function (string $output, ?bool $collision): void {
    $home = sys_get_temp_dir().'/orbit-route-inspection-'.Str::uuid();
    $files = new Filesystem;
    $files->ensureDirectoryExists($home.'/releases/one');
    symlink($home.'/releases/one', $home.'/current');
    file_put_contents($home.'/releases/one/artisan', '<?php echo '.var_export($output, true).';');
    $node = new Node(['name' => 'prod', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.4']);
    orbit_test_set_app_placement_role($node, true);
    $instance = new Instance(['production_user' => 'orbit-mealou', 'production_home' => '/home/orbit-mealou',
        'checkout_path' => '/home/orbit-mealou/releases/one', 'selected_php_version' => '8.5', 'source_is_laravel' => true]);
    $instance->setRelation('node', $node)->setRelation('project', new Project(['root' => 'public']));
    $ssh = Mockery::mock(SshExecutor::class);
    $ssh->shouldReceive('execute')->once()->andReturnUsing(function (SshConnection $connection, RemoteCommand $command) use ($home): CommandResult {
        expect(array_slice($command->arguments, 0, 6))->toBe(['sudo', '-u', 'orbit-mealou', '-H', 'python3', '-c'])
            ->and(array_slice($command->arguments, 7))->toBe(['/home/orbit-mealou', '/home/orbit-mealou/current', 'php8.5']);
        $arguments = array_slice($command->arguments, 4);
        $arguments[3] = $home;
        $arguments[4] = $home.'/current';
        $arguments[5] = PHP_BINARY;
        $process = new Process($arguments);
        $process->run();

        return new CommandResult((int) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput(), 1, false);
    });
    app()->instance(SshExecutor::class, $ssh);
    try {
        if ($collision === null) {
            expect(fn () => app(NativeWebSocketRouteInspector::class)->hasCollisions($instance))->toThrow(function (ResourceOperationException $exception): void {
                expect($exception->errorCode)->toBe('websocket.route_inspection_failed')
                    ->and($exception->getMessage())->not->toContain('sensitive');
            });
        } else {
            expect(app(NativeWebSocketRouteInspector::class)->hasCollisions($instance))->toBe($collision);
        }
    } finally {
        $files->deleteDirectory($home);
    }
})->with([
    'ordinary routes' => ['[{"uri":"dashboard"},{"uri":"broadcasting/auth"},{"uri":"application/{id}"}]', false],
    'app prefix' => ['[{"uri":"app/{id}"}]', true],
    'apps prefix' => ['[{"uri":"apps"}]', true],
    'root fallback' => ['[{"uri":"{fallbackPlaceholder}"}]', false],
    'prefixed fallback' => ['[{"uri":"app/{fallbackPlaceholder}"}]', true],
    'mixed case prefix' => ['[{"uri":"/App/page"}]', true],
    'mixed case apps' => ['[{"uri":"APPS"}]', true],
    'root parameter' => ['[{"uri":"{page?}"}]', true],
    'failed Artisan boot' => ['sensitive application error', null],
    'unexpected JSON' => ['{"sensitive":"value"}', null],
]);
