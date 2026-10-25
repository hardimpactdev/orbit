<?php

declare(strict_types=1);

use App\Models\Project;
use Aws\CommandInterface;
use Aws\Result;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Str;
use Orbit\Extensions\Documents\Actions\RecoverDocumentCleanupAction;
use Orbit\Extensions\Documents\Actions\WorkDocumentCleanupAction;
use Orbit\Extensions\Documents\Infrastructure\CleanupGate;
use Orbit\Extensions\Documents\Infrastructure\DocumentsFilesystem;
use Orbit\Extensions\Documents\Models\ProjectDocumentStorage;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Process\Process;
use Tests\Support\DocumentSchedulerFixture;
use Tests\Support\SubprocessFixture;
use Tests\TestCase;

function deletion_bucket(array $objects, string $mode = ''): void
{
    $encoded = [];
    foreach ($objects as $key => $body) {
        $encoded[$key] = ['body' => base64_encode($body)];
    }
    file_put_contents(config()->string('orbit.home').'/bucket.json', json_encode(['objects' => (object) $encoded, 'mode' => $mode], JSON_THROW_ON_ERROR));
}

function deletion_provider(TestCase $case, array $objects = []): void
{
    deletion_bucket($objects);
    $home = config()->string('orbit.home');
    $server = new Process([PHP_BINARY, base_path('tests/Fixtures/ProjectDocuments/inventory_http_server.php'), $home.'/bucket.json', $home.'/requests.json'], timeout: 60);
    $case->deletionServer = $server;
    $server->start();
    $port = SubprocessFixture::line($server);
    expect(ctype_digit($port))->toBeTrue();
    ProjectDocumentStorage::query()->findOrFail(1)->update([
        'endpoint' => 'http://127.0.0.1:'.$port, 'region' => 'fixture-1', 'bucket' => 'fixture-bucket',
        'access_key_id' => 'fixture-access', 'secret_access_key' => 'fixture-secret',
    ]);
    app(CleanupGate::class)->invalidate();
}

/** An empty bucket for batch-claim proofs, not an HTTP or process-lifecycle substitute. */
function deletion_empty_transport(): void
{
    $requests = [];
    $home = config()->string('orbit.home');
    config(['filesystems.disks.documents.handler' => static function (CommandInterface $command, RequestInterface $request) use (&$requests, $home): PromiseInterface {
        $result = match ($command->getName()) {
            'ListObjectsV2' => new Result(['EncodingType' => 'url', 'IsTruncated' => false, 'Contents' => []]),
            'DeleteObject' => new Result(['@metadata' => ['statusCode' => 204]]),
            default => throw new LogicException('Unexpected batch-proof S3 command: '.$command->getName()),
        };
        $requests[] = ['method' => $request->getMethod(), 'path' => $request->getUri()->getPath()];
        file_put_contents($home.'/requests.json', json_encode($requests, JSON_THROW_ON_ERROR));

        return Create::promiseFor($result);
    }]);
    ProjectDocumentStorage::query()->findOrFail(1)->update([
        'endpoint' => 'https://batch-proof.example.test', 'region' => 'fixture-1', 'bucket' => 'fixture-bucket',
        'access_key_id' => 'fixture-access', 'secret_access_key' => 'fixture-secret',
    ]);
    // These batch proofs never rotate configuration. Reuse their fixed, private SDK setup.
    $disk = app(DocumentsFilesystem::class)->current();
    app(FilesystemManager::class)->extend('s3', static fn (): AwsS3V3Adapter => $disk);
    app(CleanupGate::class)->invalidate();
}

function deletion_resume(): void
{
    $action = app(RecoverDocumentCleanupAction::class);
    $report = $action->reconcile();
    expect($report['difference_count'])->toBe(0);
    expect($action->resume($report['report_id'])['cleanup_state'])->toBe('running');
}

function deletion_requests(): array
{
    $path = config()->string('orbit.home').'/requests.json';
    $requests = is_file($path) ? json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : [];

    return array_values(array_filter($requests, fn (array $request): bool => $request['method'] === 'DELETE'));
}

function deletion_project(): Project
{
    $name = 'deletion-'.Str::uuid();

    return Project::query()->create(['name' => $name, 'slug' => $name, 'repository_url' => 'https://github.com/example/'.$name.'.git']);
}

function deletion_child(string $mode): Process
{
    return new Process([PHP_BINARY, base_path('tests/Fixtures/ProjectDocuments/interrupted_document_cleanup.php'), config()->string('orbit.home'), $mode],
        env: ['APP_KEY' => config()->string('app.key'), 'APP_ENV' => 'testing', 'DB_URL' => '', 'CACHE_STORE' => 'array'], timeout: 60);
}

function deletion_work(): array
{
    return app(WorkDocumentCleanupAction::class)->handle();
}

function deletion_scheduler(string $command = 'schedule:work', ?string $staleToken = null): DocumentSchedulerFixture
{
    return new DocumentSchedulerFixture(config()->string('orbit.home'), $command, $staleToken);
}

function deletion_scheduler_clock(string $clock): void
{
    $home = config()->string('orbit.home');
    file_put_contents($home.'/scheduler-clock.next', $clock);
    rename($home.'/scheduler-clock.next', $home.'/scheduler-clock');
}

function deletion_scheduler_start(DocumentSchedulerFixture $consumer): void
{
    $consumer->start();
}

function deletion_scheduler_tick(DocumentSchedulerFixture $consumer, string $clock): array
{
    return $consumer->tick($clock);
}
