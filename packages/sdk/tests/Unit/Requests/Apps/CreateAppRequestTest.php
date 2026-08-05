<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Tests\Unit\Requests\Apps;

use Orbit\Sdk\Laravel\Requests\Apps\CreateAppRequest;
use Orbit\Sdk\Laravel\Tests\TestCase;
use Saloon\Enums\Method;

uses(TestCase::class);

it('serializes an existing repository clone source', function (): void {
    $request = new CreateAppRequest(
        name: 'docs',
        node: 'app-1',
        repository: 'hardimpact/docs',
        root: 'public',
        phpVersion: '8.5',
        domain: null,
    );

    expect($request->resolveEndpoint())
        ->toBe('/api/apps')
        ->and($request->getMethod())
        ->toBe(Method::POST)
        ->and($request->body()->all())
        ->toBe([
            'name' => 'docs',
            'node' => 'app-1',
            'repository' => 'hardimpact/docs',
            'template_repository' => null,
            'new_repository' => null,
            'root' => 'public',
            'php_version' => '8.5',
            'domain' => null,
        ]);
});

it('serializes a new private repository source', function (): void {
    $request = new CreateAppRequest(
        name: 'docs',
        node: 'app-1',
        repository: null,
        root: 'public',
        phpVersion: '8.5',
        domain: null,
        templateRepository: 'hardimpact/laravel-template',
        newRepository: 'hardimpact/docs',
    );

    expect($request->body()->all())->toBe([
        'name' => 'docs',
        'node' => 'app-1',
        'repository' => null,
        'template_repository' => 'hardimpact/laravel-template',
        'new_repository' => 'hardimpact/docs',
        'root' => 'public',
        'php_version' => '8.5',
        'domain' => null,
    ]);
});
