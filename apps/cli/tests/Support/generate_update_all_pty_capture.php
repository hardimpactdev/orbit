<?php

declare(strict_types=1);

if ($argc < 4) {
    fwrite(STDERR, "usage: generate_update_all_pty_capture.php <cli-root> <delay-us> <output-path>\n");
    exit(1);
}

$cliRoot = $argv[1];
$delay = max(0, (int) $argv[2]);
$outputPath = $argv[3];
$escapedCliRoot = addslashes($cliRoot);

$source = <<<PHP
    <?php

    declare(strict_types=1);

    use App\Services\GatewayApiClient;
    use App\Services\GatewayOperationEventStreamClient;
    use App\Services\GatewayOperationFollower;
    use App\Services\Updates\RunsLocalUpdate;
    use Illuminate\Contracts\Console\Kernel;
    use Illuminate\Support\Facades\Http;
    use Orbit\Core\Http\JsonEnvelope;
    use Orbit\Core\Progress\ProgressEventType;
    use Symfony\Component\Console\Input\ArgvInput;
    use Symfony\Component\Console\Output\StreamOutput;

    define('LARAVEL_START', microtime(true));

    require '{$escapedCliRoot}/vendor/autoload.php';

    /** @var \LaravelZero\Framework\Application \$app */
    \$app = require '{$escapedCliRoot}/bootstrap/app.php';
    /** @var Kernel \$kernel */
    \$kernel = \$app->make(Kernel::class);
    \$kernel->bootstrap();

    config()->set('orbit.gateway.url', 'https://gateway.test');
    config()->set('orbit.gateway.timeout', 30);
    config()->set('app.version', '0.0.0');
    \$app->forgetInstance(GatewayApiClient::class);
    \$app->forgetInstance(GatewayOperationEventStreamClient::class);
    \$app->forgetInstance(GatewayOperationFollower::class);

    Http::fake(['https://gateway.test/*' => Http::response(
        JsonEnvelope::success([
            'operation_run' => ['id' => 'run-1', 'type' => 'update:all', 'status' => 'queued'],
            'update_plan' => [
                'target_version' => '1.2.3',
                'gateway_image' => 'ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:'.str_repeat('a', 64),
                'manifest_source' => 'github-release',
                'manifest_version' => '1.2.3',
            ],
            'events_url' => '/api/operations/run-1/events',
        ]),
        200,
    )]);

    \$app->instance(GatewayOperationFollower::class, new class extends GatewayOperationFollower
    {
        public function __construct() {}

        /** @param  callable(ProgressEventType, array<string, mixed>): void  \$onEvent */
        public function follow(string \$eventsUrl, callable \$onEvent): array
        {
            foreach ([
                ['type' => ProgressEventType::Step, 'payload' => ['key' => 'check-updates', 'status' => 'done', 'message' => 'Done: latest version is 1.2.3']],
                ['type' => ProgressEventType::Step, 'payload' => ['key' => 'check-fleet-versions', 'status' => 'done', 'message' => 'Done: 1 outdated node found', 'update_targets' => ['gateway', 'local', 'beast']]],
                ['type' => ProgressEventType::Step, 'payload' => ['key' => 'workload.beast', 'status' => 'done', 'message' => 'Workload node beast updated']],
                ['type' => ProgressEventType::Complete, 'payload' => ['status' => 'succeeded', 'target_version' => '1.2.3']],
            ] as \$event) {
                \$onEvent(\$event['type'], \$event['payload']);
            }

            return ['type' => ProgressEventType::Complete, 'payload' => ['status' => 'succeeded', 'target_version' => '1.2.3']];
        }
    });

    \$app->instance(RunsLocalUpdate::class, new class({$delay}) implements RunsLocalUpdate
    {
        public function __construct(private int \$replaceDelayMicroseconds) {}

        public function pullSource(): array
        {
            return ['successful' => true, 'exit_code' => 0, 'output' => ''];
        }

        public function downloadBinary(string \$expectedVersion = ''): array
        {
            return [
                'successful' => true,
                'exit_code' => 0,
                'output' => '',
                'staged_path' => '/tmp/staged-orbit',
                'version' => '1.2.3',
            ];
        }

        public function replaceBinary(string \$stagedPath, string \$version, ?string \$releasedAt = null): array
        {
            if (\$this->replaceDelayMicroseconds > 0) {
                \$deadline = hrtime(true) + (\$this->replaceDelayMicroseconds * 1000);

                while (hrtime(true) < \$deadline) {
                    usleep(50_000);
                }
            }

            return ['successful' => true, 'exit_code' => 0, 'output' => '', 'skipped' => false];
        }

        public function runDoctor(): array
        {
            return ['issues' => 0];
        }

        public function installDependencies(): array
        {
            return ['successful' => true, 'exit_code' => 0, 'output' => ''];
        }

        public function ensureShellIntegrations(): array
        {
            return ['successful' => true, 'exit_code' => 0, 'output' => ''];
        }

        public function verifyCurrentInstallation(string \$expectedVersion): array
        {
            return ['successful' => true, 'exit_code' => 0, 'output' => ''];
        }

        public function runMigrations(): array
        {
            return ['successful' => true, 'exit_code' => 0, 'output' => ''];
        }
    });

    if (function_exists('stream_set_write_buffer') && defined('STDOUT') && is_resource(STDOUT)) {
        stream_set_write_buffer(STDOUT, 0);
    }

    \$output = new StreamOutput(STDOUT, Symfony\Component\Console\Output\OutputInterface::VERBOSITY_NORMAL, true);

    exit(\$kernel->handle(new ArgvInput(['orbit', 'update:all']), \$output));

    PHP;

file_put_contents($outputPath, $source);
