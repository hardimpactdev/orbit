<?php

declare(strict_types=1);

use App\Models\Node;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

use function Pest\Laravel\getJson;
use function Pest\Laravel\withServerVariables;

uses(LazilyRefreshDatabase::class);

function openapi_document_peer(string $status): Node
{
    return Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => "peer-{$status}",
        'status' => $status,
        'platform' => 'linux',
        'wireguard_ip' => '10.44.0.7',
        'public_ssh_host' => '10.44.0.7',
        'user' => 'orbit',
    ]));
}

it('serves the document bin/build-api wrote with its SHA-256 to an active WireGuard peer', function (): void {
    $document = file_get_contents(base_path('../../docs/openapi.json'));
    expect($document)->toBeString();
    withServerVariables(['REMOTE_ADDR' => openapi_document_peer('active')->wireguard_ip]);

    $response = getJson('/api/v1/openapi.json')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertHeader('X-Orbit-OpenAPI-SHA256', hash('sha256', (string) $document));

    $paths = $response->json('paths');
    expect($response->getContent())->toBe($document)
        ->and($paths['/api/v1/openapi.json']['get']['operationId'])->toBe('gateway-openapi')
        ->and($paths['/api/v1/conn/managed/ack']['post']['operationId'])->toBe('conn-managed-ack');
});

it('refuses a caller that is not an active WireGuard peer', function (?string $status): void {
    $address = $status === null ? '10.44.0.9' : openapi_document_peer($status)->wireguard_ip;
    withServerVariables(['REMOTE_ADDR' => $address]);

    getJson('/api/v1/openapi.json')
        ->assertForbidden()
        ->assertHeaderMissing('X-Orbit-OpenAPI-SHA256')
        ->assertJsonPath('error.code', 'peer.identity_unknown');
})->with(['unknown address' => [null], 'provisioning Node' => ['provisioning']]);
