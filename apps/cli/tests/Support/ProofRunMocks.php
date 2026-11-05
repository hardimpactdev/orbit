<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Orbit\Sdk\Requests\Proof\RunProofRequest;
use Orbit\Sdk\Requests\Realtime\ShowRealtimeRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

const PROOF_RUN_SHA = '0123456789abcdef0123456789abcdef01234567';

/** @return array<string, mixed> The recorded operation or error body of one proof fixture. */
function proof_run_body(string $fixture): array
{
    return json_decode(gateway_fixture('proof/proof-run/'.$fixture)['body'], true, flags: JSON_THROW_ON_ERROR);
}

/** @return array<string, mixed> */
function proof_run_operation(string $fixture): array
{
    return proof_run_body($fixture)['data'];
}

/** @return array<string, mixed> */
function proof_run_running(): array
{
    return [...proof_run_operation('queued'), 'status' => 'running', 'sequence' => 3, 'current_step' => 'run-cases', 'started_at' => '2026-11-05T12:00:01+00:00'];
}

/**
 * A Gateway without Reverb that admits the run and then answers each read of the operation in turn.
 *
 * @param  list<array<string, mixed>>  $reads
 * @param  array<class-string, MockResponse|Closure>  $extra
 */
function proof_run_gateway(array $reads, array $extra = []): MockClient
{
    $queued = gateway_fixture('proof/proof-run/queued');

    return MockClient::global([
        ...gateway_fixture_mock(),
        ShowRealtimeRequest::class => MockResponse::make(['data' => ['url' => null, 'key' => null, 'channel' => 'orbit'], 'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844']]),
        RunProofRequest::class => MockResponse::make($queued['body'], 202, ['Content-Type' => 'application/json']),
        ShowOperationRequest::class => static function () use (&$reads): MockResponse {
            $read = count($reads) > 1 ? array_shift($reads) : $reads[0];

            return MockResponse::make(['data' => $read, 'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844']]);
        },
        ...$extra,
    ]);
}

/** @return array<string, mixed> */
function proof_run_json_output(): array
{
    return json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
}
