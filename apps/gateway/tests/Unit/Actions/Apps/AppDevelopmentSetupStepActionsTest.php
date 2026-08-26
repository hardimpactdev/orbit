<?php

declare(strict_types=1);

use App\Actions\Apps\AddAppDevelopmentSetupStep;
use App\Actions\Apps\RemoveAppDevelopmentSetupStep;
use App\Actions\Apps\UpdateAppDevelopmentSetupStep;
use App\Models\App;
use App\Models\AppDevelopmentSetupStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('keeps app defaults ordered when adding, moving, and removing steps', function (): void {
    $app = App::factory()->create();
    $add = app(AddAppDevelopmentSetupStep::class);
    $first = $add->handle($app->id, 'first');
    $third = $add->handle($app->id, 'third');
    $second = $add->handle($app->id, 'second', 90, $third->id, null);

    expect($app->developmentSetupSteps()->pluck('command')->all())->toBe(['first', 'second', 'third']);

    app(UpdateAppDevelopmentSetupStep::class)->handle($third->refresh(), 'updated', 30, null, $first->id);
    expect($app->developmentSetupSteps()->pluck('command')->all())->toBe(['first', 'updated', 'second']);
    expect($third->refresh()->id)->toBe($app->developmentSetupSteps()->where('command', 'updated')->value('id'));
    expect($third->refresh()->timeout_seconds)->toBe(30);

    app(UpdateAppDevelopmentSetupStep::class)->handle($third->refresh(), null, null, $second->id, null);
    expect($app->developmentSetupSteps()->pluck('command')->all())->toBe(['first', 'updated', 'second']);

    app(RemoveAppDevelopmentSetupStep::class)->handle($second->refresh());
    expect($app->developmentSetupSteps()->pluck('sort_order')->all())->toBe([1, 2]);
});

it('rejects anchors from another app and both anchors', function (): void {
    $one = App::factory()->create();
    $two = App::factory()->create();
    $step = app(AddAppDevelopmentSetupStep::class)->handle($one->id, 'one');
    $other = app(AddAppDevelopmentSetupStep::class)->handle($two->id, 'other');

    expect(fn () => app(AddAppDevelopmentSetupStep::class)->handle($one->id, 'bad', 600, $step->id, $other->id))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => app(AddAppDevelopmentSetupStep::class)->handle($one->id, 'bad', 600, $other->id, null))
        ->toThrow(InvalidArgumentException::class);
});

it('inserts before the first default without violating contiguous order', function (): void {
    $app = App::factory()->create();
    $add = app(AddAppDevelopmentSetupStep::class);
    $first = $add->handle($app->id, 'first');
    $add->handle($app->id, 'second');
    $add->handle($app->id, 'third');
    $inserted = $add->handle($app->id, 'zero', 600, $first->id);

    expect($app->developmentSetupSteps()->pluck('command')->all())->toBe(['zero', 'first', 'second', 'third'])
        ->and($inserted->id)->not->toBe($first->id)
        ->and($app->developmentSetupSteps()->pluck('sort_order')->all())->toBe([1, 2, 3, 4]);
});

it('removes a middle default and preserves unaffected identities', function (): void {
    $app = App::factory()->create();
    $add = app(AddAppDevelopmentSetupStep::class);
    $steps = collect(['one', 'two', 'three', 'four'])->map(fn (string $command): AppDevelopmentSetupStep => $add->handle($app->id, $command));
    app(RemoveAppDevelopmentSetupStep::class)->handle($steps[1]);

    expect($app->developmentSetupSteps()->pluck('command')->all())->toBe(['one', 'three', 'four'])
        ->and($app->developmentSetupSteps()->pluck('sort_order')->all())->toBe([1, 2, 3])
        ->and($app->developmentSetupSteps()->pluck('id')->all())->toBe([$steps[0]->id, $steps[2]->id, $steps[3]->id]);
});

it('rejects self and cross-app anchors during update', function (): void {
    $one = App::factory()->create();
    $two = App::factory()->create();
    $add = app(AddAppDevelopmentSetupStep::class);
    $step = $add->handle($one->id, 'one');
    $other = $add->handle($two->id, 'other');
    $update = app(UpdateAppDevelopmentSetupStep::class);

    expect(fn () => $update->handle($step, null, null, $step->id, null))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $update->handle($step, null, null, $other->id, null))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $update->handle($step, null, null, $step->id, $other->id))->toThrow(InvalidArgumentException::class);
});
