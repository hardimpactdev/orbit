<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Instances\ShowInstanceDependenciesRequest;
use Orbit\Sdk\Requests\Projects\ShowProjectDependenciesRequest;
use Orbit\Sdk\Responses\Dependencies\DependencyResultsResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('reads typed Swamp results through bodyless Instance and Project requests', function (string $kind): void {
    $record = json_decode(file_get_contents(dirname(__DIR__, 4).'/fixtures/dependencies/'.$kind.'/default.json'), true, flags: JSON_THROW_ON_ERROR);
    $request = $kind === 'instance' ? new ShowInstanceDependenciesRequest(1, str_repeat('a', 64), 2, 3) : new ShowProjectDependenciesRequest(20);
    $mock = new MockClient([$request::class => MockResponse::make($record['body'])]);
    $connector = new GatewayConnector('https://gateway.test');
    $connector->withMockClient($mock);
    $dto = $connector->send($request)->dtoOrFail();
    expect($dto)->toBeInstanceOf(DependencyResultsResponse::class);
    expect($dto->toArray())->toBe([...$record['body']['data'], 'request_id' => $record['body']['meta']['request_id']]);
    expect($dto->report->basis)->toBe('locked');
    expect($dto->report->packages[2]->origin)->toBe('path');
    expect($mock->getLastPendingRequest()->getMethod())->toBe(Method::GET);
    expect((string) $mock->getLastPendingRequest()->createPsrRequest()->getBody())->toBe('');
    expect($request->query()->all())->toBe($kind === 'instance' ? ['source' => str_repeat('a', 64), 'packages_page' => 2, 'findings_page' => 3] : []);
})->with(['instance', 'project']);

it('rejects malformed dependency results with safe request correlation', function (string $invalid): void {
    $record = json_decode(file_get_contents(dirname(__DIR__, 4).'/fixtures/dependencies/instance/default.json'), true, flags: JSON_THROW_ON_ERROR);
    $body = $record['body'];
    if ($invalid === 'wrong target') {
        $body['data']['instance_id'] = 2;
    }
    if ($invalid === 'bad type') {
        $body['data']['report']['packages'][0]['direct'] = 'true';
    }
    if ($invalid === 'oversized list') {
        $body['data']['report']['packages'] = array_fill(0, 101, $body['data']['report']['packages'][0]);
    }
    if ($invalid === 'missing provenance') {
        unset($body['data']['report']['basis']);
    }
    if ($invalid === 'duplicate') {
        $body = str_replace('"instance_id":1', '"instance_id":2,"instance_\\u0069d":1', json_encode($body, JSON_THROW_ON_ERROR));
    }
    $connector = new GatewayConnector('https://gateway.test');
    $connector->withMockClient(new MockClient([ShowInstanceDependenciesRequest::class => MockResponse::make($body)]));
    try {
        $connector->send(new ShowInstanceDependenciesRequest(1))->dtoOrFail();
        test()->fail('Invalid response accepted.');
    } catch (GatewayApiException $exception) {
        expect($exception->getMessage())->toBe('Gateway response contains invalid dependency results.');
        expect($exception->requestId())->toBe($record['body']['meta']['request_id']);
        expect($exception->getPrevious())->toBeNull();
    }
})->with(['wrong target', 'bad type', 'oversized list', 'missing provenance', 'duplicate']);

it('preserves bounded Unicode evidence without requiring a new PHP extension', function (): void {
    $record = json_decode(file_get_contents(dirname(__DIR__, 4).'/fixtures/dependencies/instance/default.json'), true, flags: JSON_THROW_ON_ERROR);
    $record['body']['data']['report']['packages'][0]['name'] = str_repeat('界', 512);
    $record['body']['data']['report']['findings'][0]['reason']['text'] = str_repeat('界', 1024);
    $connector = new GatewayConnector('https://gateway.test');
    $connector->withMockClient(new MockClient([ShowInstanceDependenciesRequest::class => MockResponse::make($record['body'])]));
    $dto = $connector->send(new ShowInstanceDependenciesRequest(1))->dtoOrFail();
    expect($dto->report->packages[0]->name)->toBe(str_repeat('界', 512));
    expect($dto->report->findings[0]->reason->text)->toBe(str_repeat('界', 1024));
});
