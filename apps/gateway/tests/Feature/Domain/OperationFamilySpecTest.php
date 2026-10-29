<?php

declare(strict_types=1);

use App\Domain\Operations\OperationFamilySpec;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\ToolOperationCompletion;
use App\Domain\Tools\ToolOperationExecution;

it('reuses the kind registry across family admission checks', function (): void {
    $kinds = app(OperationKinds::class);

    expect(OperationFamilySpec::available('tool.install'))->toBeTrue()
        ->and(OperationFamilySpec::available('tool.remove'))->toBeTrue()
        ->and(app(OperationKinds::class))->toBe($kinds);
});

it('keeps every Tools command and completion contract unchanged', function (): void {
    $definitions = require app_path('Domain/Tools/operation-kinds.php');

    expect(hash('sha256', json_encode($definitions, JSON_THROW_ON_ERROR)))
        ->toBe('85715dada17c2b862266064eb20551976632dbf98760a4b72540ba76ad5a2bd4');
    expect(new OperationKinds(app(), $definitions))->toBeInstanceOf(OperationKinds::class);
});

it('refuses an undeclared completion command before registration', function (): void {
    $family = ['completion' => ToolOperationCompletion::class, 'execution' => ToolOperationExecution::class,
        'transport' => 'agent', 'done_step' => 'verified', 'variant' => 'manager', 'result_fields' => ['version']];

    expect(fn () => OperationFamilySpec::compile($family, ['tool.install' => [
        'commands' => ['tool.vp-version'], 'done_when' => ['vp' => 'tool.apt-version'],
    ]]))->toThrow(LogicException::class, 'Completion command must belong to the family action.');
});

it('refuses unsupported family or action fields', function (bool $familyField): void {
    $family = ['completion' => ToolOperationCompletion::class, 'execution' => ToolOperationExecution::class,
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
    $family = ['completion' => ToolOperationCompletion::class, 'execution' => ToolOperationExecution::class,
        'transport' => 'agent', 'done_step' => 'verified', 'variant' => 'manager', 'result_fields' => ['version'],
        'requires' => ['unsupported-capability']];

    expect(OperationFamilySpec::compile($family, ['tool.install' => [
        'commands' => ['tool.vp-version'], 'done_when' => ['vp' => 'tool.vp-version'],
    ]]))->toBe([]);
    expect(fn () => OperationFamilySpec::assertAvailable('unavailable.kind'))
        ->toThrow(ResourceOperationException::class);
});
