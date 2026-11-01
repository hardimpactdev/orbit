<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationContribution;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationResultProjection;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\ResourceOperationException;
use Tests\TestCase;

uses(TestCase::class);

describe(OperationKinds::class, function (): void {
    it('names every kind and target type in the form that the list filters accept', function (): void {
        expect(OperationKinds::names())->not->toBeEmpty()->each->toMatch(OperationKinds::NamePattern);
        expect(OperationKinds::InstanceTarget)->toMatch(OperationKinds::TargetTypePattern);
    });

    it('discovers native workflows and the two Gateway custody jobs across domains', function (): void {
        $definitions = OperationKinds::definitions();
        $gatewayKinds = ['github.app_register', 'github.app_sync'];

        expect($definitions)->toHaveKeys(['route.caddy_converge', 'database_server.create', ...$gatewayKinds]);
        foreach ($definitions as $kind => $definition) {
            if (in_array($kind, $gatewayKinds, true)) {
                expect($definition)->toBe(['executor' => 'gateway']);
                expect(OperationKinds::executor($kind))->toBe('gateway');

                continue;
            }

            expect($definition)->toHaveKeys(['workflow', 'projection'])
                ->not->toHaveKeys(['runs', 'children', 'completion_contract', 'execution']);
            expect(is_a($definition['projection'], DirectOperationProjection::class, true))->toBeTrue();
            expect(OperationKinds::executor($kind))->toBe('swamp');
        }
    });

    it('refuses an unported kind instead of selecting a Gateway executor', function (): void {
        expect(app(OperationKinds::class)->definition('test.unported'))->toBeNull();
        expect(fn () => OperationKinds::executor('test.unported'))
            ->toThrow(ResourceOperationException::class, 'This kind has no native Swamp workflow.');
    });

    it('rejects retired Kit guarantees in a native declaration', function (string $key): void {
        $contribution = new class($key) implements DirectOperationContribution
        {
            public function __construct(private string $key) {}

            public function definitions(): array
            {
                return ['test.native' => ['workflow' => 'operation-test-native',
                    'projection' => DirectOperationResultProjection::class, $this->key => []]];
            }
        };

        expect(fn () => new DirectOperation([$contribution]))
            ->toThrow(LogicException::class, 'A direct Operation needs one fixed family declaration.');
    })->with(['runs', 'children', 'completion_contract', 'execution', 'completion', 'result_fields',
        'admission', 'compensations', 'cancellation_boundary', 'cancel_guard']);

    it('reports retired Kit capabilities unavailable', function (string $capability): void {
        expect(OperationKinds::supports($capability))->toBeFalse();
    })->with(['children', 'children_settled', 'receipts', 'list_of', 'archive_relay', 'compensations', 'cancel_guard', 'unknown']);

    it('keeps the shared private secret output boundary available', function (): void {
        expect(OperationKinds::supports('secret_outputs'))->toBeTrue();
    });
});
