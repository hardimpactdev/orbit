<?php

declare(strict_types=1);

use App\Domain\Operations\OperationFamilySpec;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\ResourceOperationException;
use Tests\Support\PolledOperationFixture;

it('reuses the kind registry across family admission checks', function (): void {
    $kinds = app(OperationKinds::class);

    expect(OperationFamilySpec::available('tool.install'))->toBeTrue()
        ->and(OperationFamilySpec::available('tool.remove'))->toBeTrue()
        ->and(app(OperationKinds::class))->toBe($kinds);
});

it('refuses an undeclared completion command before registration', function (): void {
    $family = ['completion' => PolledOperationFixture::class, 'execution' => PolledOperationFixture::class,
        'transport' => 'agent', 'done_step' => 'verified', 'variant' => 'manager', 'result_fields' => ['version']];

    expect(fn () => OperationFamilySpec::compile($family, ['tool.install' => [
        'commands' => ['tool.vp-version'], 'done_when' => ['vp' => 'tool.apt-version'],
    ]]))->toThrow(LogicException::class, 'Completion command must belong to the family action.');
});

it('refuses unsupported family or action fields', function (bool $familyField): void {
    $family = ['completion' => PolledOperationFixture::class, 'execution' => PolledOperationFixture::class,
        'transport' => 'agent', 'done_step' => 'verified', 'variant' => 'manager', 'result_fields' => ['version']];
    $action = ['commands' => ['tool.vp-version'], 'done_when' => ['vp' => 'tool.vp-version']];
    if ($familyField) {
        $family['trust_everything'] = true;
    } else {
        $action['arbitrary_shell'] = true;
    }

    expect(fn () => OperationFamilySpec::compile($family, ['tool.install' => $action]))->toThrow(LogicException::class);
})->with([true, false]);

it('withholds a family whose required capability is unavailable', function (): void {
    $family = ['completion' => PolledOperationFixture::class, 'execution' => PolledOperationFixture::class,
        'transport' => 'agent', 'done_step' => 'verified', 'variant' => 'manager', 'result_fields' => ['version'],
        'requires' => ['unsupported-capability']];

    expect(OperationFamilySpec::compile($family, ['tool.install' => [
        'commands' => ['tool.vp-version'], 'done_when' => ['vp' => 'tool.vp-version'],
    ]]))->toBe([]);
    expect(fn () => OperationFamilySpec::assertAvailable('unavailable.kind'))
        ->toThrow(ResourceOperationException::class);
});
