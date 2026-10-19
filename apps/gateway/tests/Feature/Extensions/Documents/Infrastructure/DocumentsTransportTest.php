<?php

declare(strict_types=1);

use App\Domain\Shared\ResourceOperationException;
use Aws\Handler\Guzzle\GuzzleHandler;
use Aws\S3\S3Client;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Exception\ResponseTimeoutException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Orbit\Extensions\Documents\Actions\ReconcileProbesAction;
use Orbit\Extensions\Documents\Actions\UpdateDocumentStorageAction;
use Orbit\Extensions\Documents\Data\DocumentBody;
use Orbit\Extensions\Documents\Data\UpdateDocumentStorageData;
use Orbit\Extensions\Documents\Infrastructure\DocumentBodies;
use Orbit\Extensions\Documents\Infrastructure\DocumentsFilesystem;
use Orbit\Extensions\Documents\Infrastructure\ProbeJournal;
use Orbit\Extensions\Documents\Infrastructure\ProbeResponseBuffer;
use Orbit\Extensions\Documents\Infrastructure\VerifyDocumentStorage;
use Orbit\Extensions\Documents\Models\ProjectDocumentStorage;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Process\Process;
use Tests\Support\SubprocessFixture;
use Tests\Support\WithCommittedSqliteDatabase;

uses(WithCommittedSqliteDatabase::class);

beforeEach(function (): void {
    $this->probeTransportHome = sys_get_temp_dir().'/orbit-probe-transport-'.Str::uuid();
    mkdir($this->probeTransportHome, 0700, true);
    config(['orbit.home' => $this->probeTransportHome]);
    $this->beforeApplicationDestroyed(function (): void {
        File::deleteDirectory($this->probeTransportHome);
    });
});

/** @return array{Process, S3Client, ProjectDocumentStorage} */
function probe_transport_fixture(string $mode): array
{
    // Allow 60 seconds for the transport fixture to print its readiness line.
    $server = new Process(['python3', base_path('tests/Fixtures/ProjectDocuments/probe_http_server.py'), $mode, config()->string('orbit.home').'/http-state.json'], timeout: 60);
    $server->start();
    $port = SubprocessFixture::line($server);
    if (! ctype_digit($port)) {
        $server->stop();
        throw new RuntimeException('Loopback S3 transport fixture did not start.');
    }
    $storage = new ProjectDocumentStorage([
        'endpoint' => ($mode === 'tls-stall' ? 'https' : 'http').'://127.0.0.1:'.$port,
        'region' => 'europe-2', 'bucket' => 'fixture-bucket',
        'access_key_id' => 'fixture-access', 'secret_access_key' => 'fixture-secret',
    ]);

    return [$server, app(DocumentsFilesystem::class)->forConfiguration($storage)->getClient(), $storage];
}

/** @return array{Process|null, DocumentBodies} */
function body_transport_fixture(string $mode): array
{
    test()->useCommittedSqliteDatabase(config()->string('orbit.home').'/bodies.sqlite');
    $server = null;
    if (in_array($mode, ['tls-stall', 'body-timeout'], true)) {
        $storage = new ProjectDocumentStorage(['endpoint' => 'https://documents.example.test', 'region' => 'europe-2', 'bucket' => 'fixture-bucket']);
        $requests = [];
        config(['filesystems.disks.documents.http_handler' => new GuzzleHandler(new Client([
            'handler' => static function (RequestInterface $request, array $options) use ($mode, &$requests): PromiseInterface {
                $record = ['method' => $request->getMethod(), 'connect_timeout' => $options['connect_timeout'] ?? null,
                    'timeout' => $options['timeout'] ?? null, 'allow_redirects' => $options['allow_redirects'] ?? null];
                if ($mode === 'tls-stall') {
                    $exception = new ConnectTimeoutException('fixture-access fixture-secret timeout', $request);
                } else {
                    $response = new Response(200, ['Content-Length' => '32'], 'aaaaaaaa');
                    $options['on_headers']($response);
                    $options['sink']->write('aaaaaaaa');
                    $record['status'] = $options['sink']->responseStatus;
                    $record['partial_body'] = (string) $options['sink'];
                    $exception = new ResponseTimeoutException('fixture-access fixture-secret timeout', $request, $response);
                }
                $requests[] = $record;
                file_put_contents(config()->string('orbit.home').'/http-state.json', json_encode([
                    'requests' => $requests,
                ], JSON_THROW_ON_ERROR));

                return Create::rejectionFor($exception);
            },
        ]))]);
    } else {
        [$server, , $storage] = probe_transport_fixture($mode);
    }
    ProjectDocumentStorage::query()->findOrFail(1)->update([
        'endpoint' => $storage->endpoint, 'region' => $storage->region, 'bucket' => $storage->bucket,
        'access_key_id' => 'fixture-access', 'secret_access_key' => 'fixture-secret',
    ]);

    return [$server, app(DocumentBodies::class)];
}

/** @return array<string, mixed> */
function probe_transport_state(): array
{
    return json_decode(file_get_contents(config()->string('orbit.home').'/http-state.json'), true, flags: JSON_THROW_ON_ERROR);
}

describe('committed document body HTTP transport', function (): void {
    it('round trips DocumentBodies without an object ACL and verifies empty and binary bodies through real bounded HTTP responses', function (string $encoded): void {
        [$server, $bodies] = body_transport_fixture('short');
        $body = DocumentBody::base64($encoded);
        $bytes = $body->bytes;
        try {
            $bodies->put('orbit-documents/1/1/fixture', $body);

            expect($bodies->get('orbit-documents/1/1/fixture', $body->sizeBytes, $body->sha256))->toBe($bytes);
            expect(array_column(probe_transport_state()['requests'], 'method'))->toBe(['PUT', 'GET', 'GET']);
            expect(probe_transport_state()['requests'][0]['acl'])->toBeNull();
        } finally {
            $server?->stop();
            DB::disconnect();
        }
    })->with(['empty' => '', 'binary' => 'YQD/Cg==']);

    it('distinguishes missing and corrupt bodies from provider outages through the HTTP sink with controlled timeouts', function (string $mode, int $size, string $code, int $status): void {
        [$server, $bodies] = body_transport_fixture($mode);
        try {
            try {
                $bodies->get('orbit-documents/1/1/fixture', $size, hash('sha256', str_repeat('a', $size)));
                test()->fail('The provider failure must not return content.');
            } catch (ResourceOperationException $exception) {
                expect($exception->errorCode)->toBe($code);
                expect($exception->status)->toBe($status);
                expect($exception->getPrevious())->toBeNull();
                expect((string) $exception)->not->toContain('fixture-access', 'fixture-secret', 'orbit-documents/');
            }
            // Assert outside the handler: DocumentBodies deliberately catches every transport exception.
            if (in_array($mode, ['tls-stall', 'body-timeout'], true)) {
                $requests = probe_transport_state()['requests'];
                expect($requests)->toHaveCount(1);
                expect($requests[0]['method'])->toBe('GET');
                expect($requests[0]['connect_timeout'])->toBe(2);
                expect($requests[0]['timeout'])->toBe(30);
                expect($requests[0]['allow_redirects'])->toBeFalse();
                if ($mode === 'body-timeout') {
                    expect($requests[0]['status'])->toBe(200);
                    expect($requests[0]['partial_body'])->toBe('aaaaaaaa');
                }
            }
            if ($mode !== 'tls-stall') {
                expect(probe_transport_state()['requests'])->toHaveCount(1);
                expect(probe_transport_state()['requests'][0]['method'])->toBe('GET');
            }
        } finally {
            $server?->stop();
            DB::disconnect();
        }
    })->with([
        'missing empty file' => ['get-404', 0, 'project_documents.body_unavailable', 502],
        'missing small file' => ['get-404', 5, 'project_documents.body_unavailable', 502],
        'oversized successful response' => ['oversized', 5, 'project_documents.body_unavailable', 502],
        'denied empty file' => ['get-403', 0, 'project_documents.storage_unavailable', 503],
        'server failure small file' => ['get-500', 5, 'project_documents.storage_unavailable', 503],
        'connection timeout' => ['tls-stall', 5, 'project_documents.storage_unavailable', 503],
        'successful headers followed by body timeout' => ['body-timeout', 32, 'project_documents.storage_unavailable', 503],
        'missing bucket is a provider failure' => ['get-404-bucket', 0, 'project_documents.storage_unavailable', 503],
        'oversized denied diagnostic' => ['get-403-oversized', 0, 'project_documents.storage_unavailable', 503],
        'oversized server diagnostic' => ['get-500-oversized', 5, 'project_documents.storage_unavailable', 503],
    ]);
});

describe('document probe HTTP transport', function (): void {
    it('rejects small HTTP DELETE error and redirect responses without retrying or following redirects', function (int $status): void {
        [$server, $client] = probe_transport_fixture('delete-'.$status);
        try {
            expect(fn () => $client->deleteObject([
                'Bucket' => 'fixture-bucket', 'Key' => 'orbit-document-probes/fixture',
                '@http' => ['stream' => false, 'sink' => new ProbeResponseBuffer(65_536)],
            ]))->toThrow(RuntimeException::class);
            expect(probe_transport_state()['requests'])->toHaveCount(1);
            expect(probe_transport_state()['requests'][0]['status'])->toBe($status);
        } finally {
            $server->stop();
        }
    })->with(['denied' => 403, 'not found' => 404, 'server failure' => 500, 'redirect' => 302]);

    it('completes a filesystem PUT GET DELETE round trip without an object ACL with the selected transport', function (): void {
        [$server, $client, $storage] = probe_transport_fixture('short');
        $disk = app(DocumentsFilesystem::class)->forConfiguration($storage);
        $key = 'orbit-document-probes/private-round-trip';
        $body = "private synthetic bytes\0\xff";
        try {
            expect($disk->put($key, $body))->toBeTrue();
            expect($disk->get($key))->toBe($body);
            expect($disk->delete($key))->toBeTrue();
            $state = probe_transport_state();
            expect(array_column($state['requests'], 'method'))->toBe(['PUT', 'GET', 'DELETE']);
            expect(array_column($state['requests'], 'status'))->toBe([200, 200, 204]);
            expect(array_column($state['requests'], 'authorized'))->toBe([true, true, true]);
            expect($state['requests'][0]['acl'])->toBeNull();
            expect($state['object_count'])->toBe(0);
        } finally {
            $server->stop();
        }
    });

    it('verifies successful real PUT GET DELETE responses and keeps the cleanup fence', function (): void {
        [$server, $client, $storage] = probe_transport_fixture('short');
        try {
            app(VerifyDocumentStorage::class)->handle($storage);
            $state = probe_transport_state();
            expect(array_column($state['requests'], 'method'))->toBe(['PUT', 'GET', 'DELETE']);
            expect(array_column($state['requests'], 'status'))->toBe([200, 200, 204]);
            expect($state['object_count'])->toBe(0);
            expect($state['requests'][0]['acl'])->toBeNull();
            $this->travel(2)->minutes();
            expect(app(ReconcileProbesAction::class)->handle())->toBe(['attempted' => 1, 'succeeded' => 1, 'failed' => 0]);
        } finally {
            $server->stop();
        }
    });

    it('preserves prior configuration when real PUT GET succeed but DELETE is denied', function (): void {
        [$server, $client, $storage] = probe_transport_fixture('delete-403');
        $prior = ProjectDocumentStorage::query()->findOrFail(1);
        $prior->fill(['endpoint' => 'https://prior.example.test', 'region' => 'prior-region', 'bucket' => 'prior-bucket',
            'access_key_id' => 'prior-access', 'secret_access_key' => 'prior-secret'])->save();
        $original = $prior->getRawOriginal();
        $data = new UpdateDocumentStorageData($storage->endpoint, $storage->region, $storage->bucket, $storage->access_key_id, $storage->secret_access_key);
        try {
            try {
                app(UpdateDocumentStorageAction::class)->handle($data);
                test()->fail('Denied DELETE must reject configuration.');
            } catch (ResourceOperationException $exception) {
                expect($exception->status)->toBe(503);
                expect($exception->errorCode)->toBe('project_documents.storage_unavailable');
                expect($exception->getPrevious())->toBeNull();
                expect((string) $exception)->not->toContain('fixture-access', 'fixture-secret');
            }
            expect($prior->refresh()->getRawOriginal())->toBe($original);
            $state = probe_transport_state();
            expect(array_column($state['requests'], 'method'))->toBe(['PUT', 'GET', 'DELETE', 'DELETE']);
            expect(array_column($state['requests'], 'status'))->toBe([200, 200, 403, 403]);
            expect($state['object_count'])->toBe(1);
            $this->travel(2)->minutes();
            expect(app(ReconcileProbesAction::class)->handle())->toBe(['attempted' => 1, 'succeeded' => 0, 'failed' => 1]);
        } finally {
            $server->stop();
        }
    });

    it('records reconciliation failure and backoff for small HTTP DELETE errors and redirects', function (int $status): void {
        $this->freezeTime();
        [$server, $client, $storage] = probe_transport_fixture('delete-'.$status);
        $record = app(ProbeJournal::class)->create($storage);
        try {
            $client->putObject(['Bucket' => $storage->bucket, 'Key' => $record->key(), 'Body' => 'synthetic probe']);
            $this->travel(2)->minutes();
            expect(app(ReconcileProbesAction::class)->handle())->toBe(['attempted' => 1, 'succeeded' => 0, 'failed' => 1]);
            expect(app(ProbeJournal::class)->find($record->id)->failureCount)->toBe(1);
            $database = new PDO('sqlite:'.config()->string('orbit.home').'/project-document-probes/journal.sqlite');
            $row = $database->query('SELECT * FROM probes')->fetch(PDO::FETCH_ASSOC);
            expect($row['last_error_code'])->toBe('project_documents.storage_unavailable');
            expect($row['next_attempt_at'])->toBe(now()->getTimestamp() + 60);
            expect(app(ReconcileProbesAction::class)->handle())->toBe(['attempted' => 0, 'succeeded' => 0, 'failed' => 0]);
            $state = probe_transport_state();
            expect(array_column($state['requests'], 'status'))->toBe([200, $status]);
            expect($state['object_count'])->toBe(1);
        } finally {
            $server->stop();
        }
    })->with(['denied' => 403, 'not found' => 404, 'server failure' => 500, 'redirect' => 302]);

    it('enforces the connection bound during a stalled TLS handshake', function (): void {
        [$server, $client] = probe_transport_fixture('tls-stall');
        $command = $client->getCommand('GetObject', [
            'Bucket' => 'fixture-bucket', 'Key' => 'orbit-document-probes/fixture',
            '@http' => ['stream' => false, 'sink' => new ProbeResponseBuffer],
        ]);
        expect($command['@http']['connect_timeout'])->toBe(2);

        try {
            expect(fn () => $client->execute($command))->toThrow(function (RuntimeException $exception): void {
                $previous = $exception->getPrevious();
                expect($previous)->toBeInstanceOf(ConnectTimeoutException::class);
            });
        } finally {
            $server->stop();
        }
    });

    it('enforces the total request deadline when a response body stalls', function (): void {
        [$server, $client] = probe_transport_fixture('body-stall');
        $command = $client->getCommand('GetObject', [
            'Bucket' => 'fixture-bucket', 'Key' => 'orbit-document-probes/fixture',
            '@http' => ['stream' => false, 'sink' => new ProbeResponseBuffer],
        ]);
        expect($command['@http']['timeout'])->toBe(5);

        try {
            expect(fn () => $client->execute($command))->toThrow(function (RuntimeException $exception): void {
                $previous = $exception->getPrevious();
                expect($previous)->toBeInstanceOf(ResponseTimeoutException::class);
                expect($previous->getResponse()->getStatusCode())->toBe(200);
                expect((string) $previous->getResponse()->getBody())->toBe('aaaaaaaa');
            });
            expect(array_column(probe_transport_state()['requests'], 'method'))->toBe(['GET']);
        } finally {
            $server->stop();
        }
    });

    it('accepts a complete body transferred in short HTTP chunks', function (): void {
        [$server, $client] = probe_transport_fixture('short');
        try {
            $result = $client->getObject([
                'Bucket' => 'fixture-bucket', 'Key' => 'orbit-document-probes/fixture', 'Range' => 'bytes=0-32',
                '@http' => ['stream' => false, 'sink' => new ProbeResponseBuffer],
            ]);
            expect((string) $result['Body'])->toBe(str_repeat('a', 32));
        } finally {
            $server->stop();
        }
    });

    it('bounds DELETE error responses used by reconciliation', function (): void {
        [$server, $client] = probe_transport_fixture('oversized');
        $buffer = new ProbeResponseBuffer(65_536);
        try {
            expect(fn () => $client->deleteObject([
                'Bucket' => 'fixture-bucket', 'Key' => 'orbit-document-probes/fixture',
                '@http' => ['stream' => false, 'sink' => $buffer],
            ]))->toThrow(RuntimeException::class);
            expect($buffer->getSize())->toBe(65_536);
        } finally {
            $server->stop();
        }
    });

    it('bounds buffered bytes when an HTTP server ignores Range', function (): void {
        [$server, $client] = probe_transport_fixture('oversized');
        $buffer = new ProbeResponseBuffer;
        try {
            expect(fn () => $client->getObject([
                'Bucket' => 'fixture-bucket', 'Key' => 'orbit-document-probes/fixture', 'Range' => 'bytes=0-32',
                '@http' => ['stream' => false, 'sink' => $buffer],
            ]))->toThrow(RuntimeException::class);
            expect($buffer->getSize())->toBe(33);
        } finally {
            $server->stop();
        }
    });
});
