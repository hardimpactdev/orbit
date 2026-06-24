<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Tests\Unit\Plugins;

use Mockery;
use Orbit\Sdk\Laravel\Plugins\HasCorrelationHeader;
use Orbit\Sdk\Laravel\Tests\TestCase;
use Saloon\Contracts\ArrayStore;
use Saloon\Http\PendingRequest;

uses(TestCase::class);

afterEach(function (): void {
    Mockery::close();
});

it('adds X-Orbit-Request-Id header when correlation id is set', function (): void {
    $expected = '11111111-1111-1111-1111-111111111111';

    $trait = new class($expected) {
        use HasCorrelationHeader;

        public function __construct(
            private readonly string $uuid,
        ) {}

        protected function orbitClientName(): string
        {
            return 'cli';
        }

        protected function orbitCorrelationId(): ?string
        {
            return $this->uuid;
        }
    };

    $pending = Mockery::mock(PendingRequest::class);
    $headers = Mockery::mock(ArrayStore::class);
    $headers->shouldReceive('add')->with('X-Orbit-Request-Id', $expected)->once();
    $headers->shouldReceive('add')->with('X-Orbit-Client', 'cli')->once();
    $pending->shouldReceive('headers')->andReturn($headers);

    $trait->bootHasCorrelationHeader($pending);
});

it('omits X-Orbit-Request-Id header when no correlation id is active', function (): void {
    $trait = new class {
        use HasCorrelationHeader;

        protected function orbitClientName(): string
        {
            return 'cli';
        }
    };

    $pending = Mockery::mock(PendingRequest::class);
    $headers = Mockery::mock(ArrayStore::class);
    $headers->shouldReceive('add')->with('X-Orbit-Client', 'cli')->once();
    $headers->shouldNotReceive('add')->with('X-Orbit-Request-Id', Mockery::any());
    $pending->shouldReceive('headers')->andReturn($headers);

    $trait->bootHasCorrelationHeader($pending);
});

it('uses an overridden client name when a gateway caller supplies one', function (): void {
    $trait = new class {
        use HasCorrelationHeader;

        protected function orbitClientName(): string
        {
            return 'scheduler';
        }
    };

    $pending = Mockery::mock(PendingRequest::class);
    $headers = Mockery::mock(ArrayStore::class);
    $headers->shouldReceive('add')->with('X-Orbit-Client', 'scheduler')->once();
    $headers->shouldNotReceive('add')->with('X-Orbit-Request-Id', Mockery::any());
    $pending->shouldReceive('headers')->andReturn($headers);

    $trait->bootHasCorrelationHeader($pending);
});
