<?php

declare(strict_types=1);

use Aws\S3\S3Client;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Scheduling\ScheduleWorkCommand;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Orbit\Extensions\Documents\Infrastructure\CleanupGate;
use Symfony\Component\Console\Input\ArgvInput;

// Keep Laravel's real worker loop, child command construction, scheduler, and scheduled command execution.
// Only the clock, its acknowledgement transport, and unrelated scheduled jobs are isolated for this fixture.
// Config, routes and provider manifests are immutable native caches shared with its real children.
define('ARTISAN_BINARY', __FILE__);
require __DIR__.'/../../../vendor/autoload.php';
$home = getenv('ORBIT_HOME');
if (! is_string($home)) {
    throw new RuntimeException('Missing disposable scheduler home.');
}
$clock = trim(file_get_contents($home.'/scheduler-clock'));
Carbon::setTestNow($clock);
Date::setTestNow($clock);
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->loadEnvironmentFrom('.env.example');
$kernel = $app->make(Kernel::class);
// Mirror the real artisan entry point, including lifecycle events in APP_ENV=testing.
$kernel->rerouteSymfonyCommandEvents();
$kernel->bootstrap();
if (($argv[1] ?? '') === 'fixture:bootstrap') {
    // Build only immutable configuration, registrations and PHP bytecode once, before the lifecycle case.
    $kernel->all();
    file_put_contents($home.'/bootstrap/config.php', '<?php return '.var_export(config()->all(), true).';');
    $routes = new RouteCollection;
    foreach (app('router')->getRoutes() as $route) {
        $prepared = clone $route;
        $prepared->prepareForSerialization();
        $routes->add($prepared);
    }
    $routes->refreshNameLookups();
    $routes->refreshActionLookups();
    file_put_contents($home.'/bootstrap/routes.php', '<?php app("router")->setCompiledRoutes('.var_export($routes->compile(), true).');');
    // The SDK's immutable service model is bootstrap data too; this constructs a client without a transport call.
    new S3Client(['version' => 'latest', 'region' => 'fixture-1', 'credentials' => ['key' => 'fixture', 'secret' => 'fixture']]);
    exit(0);
}
config(['app.key' => getenv('APP_KEY'), 'database.default' => 'sqlite', 'database.connections.sqlite.database' => $home.'/database.sqlite', 'orbit.home' => $home,
    'documents.cleanup_runtime' => $home.'/runtime']);
DB::purge('sqlite');
// CommandStarting follows Artisan's real registration callbacks. Do not call Kernel::all(): it eagerly
// constructs every unrelated command (including in each cleanup child) just to initialize the schedule.
Event::listen(CommandStarting::class, static function (CommandStarting $event): void {
    if ($event->command !== 'schedule:run') {
        return;
    }
    $schedule = app(Schedule::class);
    $cleanup = array_values(array_filter($schedule->events(), static fn ($scheduled): bool => str_contains($scheduled->command ?? '', 'project-documents:cleanup:work')));
    if (count($cleanup) !== 1 || $cleanup[0]->expression !== '*/5 * * * *') {
        throw new RuntimeException('The production cleanup schedule is missing or has changed.');
    }
    new ReflectionProperty(Schedule::class, 'events')->setValue($schedule, $cleanup);
});
$socket = getenv('ORBIT_TEST_SCHEDULER_SOCKET');
if (($argv[1] ?? '') === 'schedule:work') {
    $control = stream_socket_client('unix://'.$socket);
    if ($control === false) {
        throw new RuntimeException('Could not connect the scheduler clock.');
    }
    $kernel->registerCommand(new class($control) extends ScheduleWorkCommand
    {
        /** @param resource $control */
        public function __construct(private $control)
        {
            parent::__construct();
        }

        protected function sleep(): void
        {
            // The real work loop dispatches each child. Only its clock waits for an explicit next tick.
            $clock = fgets($this->control);
            if ($clock === false) {
                throw new RuntimeException('The scheduler clock disconnected.');
            }
            Carbon::setTestNow(trim($clock));
            Date::setTestNow(trim($clock));
        }
    });
    Event::listen(CommandStarting::class, static function (CommandStarting $event) use ($control): void {
        if ($event->command === 'schedule:work') {
            app(CleanupGate::class)->status();
            fwrite($control, "scheduler-ready\n");
        }
    });
}
$status = $app->handleCommand(new ArgvInput);
if (($argv[1] ?? '') === 'schedule:run' && is_string($socket)) {
    $ack = stream_socket_client('unix://'.$socket);
    if ($ack === false) {
        throw new RuntimeException('Could not acknowledge the scheduler tick.');
    }
    fwrite($ack, json_encode([
        'clock' => $clock, 'exit_code' => $status, ...app(CleanupGate::class)->status()->toArray(),
    ], JSON_THROW_ON_ERROR)."\n");
    fclose($ack);
}
exit($status);
