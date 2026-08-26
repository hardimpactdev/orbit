<?php

declare(strict_types=1);

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Models\App;
use App\Models\AppDevelopmentSetupStep;
use App\Models\Instance;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

const APP_DEFAULTS_API_IP = '10.6.0.121';

/** @return array{0: Node, 1: App} */
function app_defaults_api_target(array $permissions = ['app:read', 'app:write']): array
{
    $serving = Node::factory()->appDev()->create(['name' => 'defaults-app-node']);
    $app = App::factory()->create(['name' => 'fitta']);
    Instance::factory()->for($app)->create([
        'name' => 'development',
        'driver_config' => new OrbitInstanceDriverConfigData(node_id: $serving->id, node: $serving->name),
    ]);
    $caller = Node::factory()->create([
        'name' => 'defaults-caller',
        'host' => APP_DEFAULTS_API_IP,
        'wireguard_address' => APP_DEFAULTS_API_IP,
    ]);
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $serving->id,
        'permissions' => json_encode($permissions, JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$caller, $app];
}

function app_defaults_api_headers(): array
{
    return ['REMOTE_ADDR' => APP_DEFAULTS_API_IP, 'CONTENT_TYPE' => 'application/json'];
}

/** @mago-expect lint:halstead */
describe('app development setup step API', function (): void {
    it('lists and mutates ordered defaults with canonical envelopes', function (): void {
        [, $app] = app_defaults_api_target();
        $first = AppDevelopmentSetupStep::factory()->for($app)->create(['sort_order' => 1, 'command' => 'one']);
        $second = AppDevelopmentSetupStep::factory()->for($app)->create(['sort_order' => 2, 'command' => 'two']);

        $this->call('GET', "/api/apps/{$app->name}/development-setup-steps", [], [], [], app_defaults_api_headers())
            ->assertOk()
            ->assertJsonPath('success.data.action', 'listed')
            ->assertJsonPath('success.meta', [])
            ->assertJsonPath('success.data.steps.0.id', $first->id)
            ->assertJsonPath('success.data.steps.1.id', $second->id);

        $added = $this->postJson("/api/apps/{$app->name}/development-setup-steps", [
            'command' => 'three', 'timeout' => 42, 'before' => $second->id,
        ], app_defaults_api_headers());
        $added->assertCreated()->assertJsonPath('success.data.action', 'added')->assertJsonPath('success.meta', []);
        $newId = $added->json('success.data.step.id');
        expect(AppDevelopmentSetupStep::query()->orderBy('sort_order')->pluck('command')->all())
            ->toBe(['one', 'three', 'two']);

        $updated = $this->patchJson("/api/apps/{$app->name}/development-setup-steps/{$newId}", [
            'command' => 'updated', 'after' => $second->id,
        ], app_defaults_api_headers())->assertOk()->assertJsonPath('success.data.step.id', $newId)->assertJsonPath('success.meta', []);
        expect(AppDevelopmentSetupStep::query()->orderBy('sort_order')->pluck('command')->all())
            ->toBe(['one', 'two', 'updated']);

        $removed = $this->deleteJson("/api/apps/{$app->name}/development-setup-steps/{$newId}", [
            'destructive_consent' => true,
        ], app_defaults_api_headers())->assertOk()->assertJsonPath('success.data.action', 'removed')->assertJsonPath('success.meta', []);
        expect(AppDevelopmentSetupStep::query()->orderBy('sort_order')->pluck('sort_order')->all())->toBe([1, 2]);
    });

    it('allows read with app:read and denies mutations with canonical permission metadata', function (): void {
        [, $app] = app_defaults_api_target(['app:read']);

        $this->getJson("/api/apps/{$app->name}/development-setup-steps", app_defaults_api_headers())->assertOk();
        $this->postJson("/api/apps/{$app->name}/development-setup-steps", ['command' => 'x'], app_defaults_api_headers())
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'app:write');
    });

    it('rejects unknown callers, missing apps, invalid inputs, and destructive requests without consent', function (): void {
        [, $app] = app_defaults_api_target();
        $headers = ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '10.6.0.250'];

        $this->getJson("/api/apps/{$app->name}/development-setup-steps", $headers)
            ->assertForbidden()->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta', []);
        $this->getJson('/api/apps/missing/development-setup-steps', app_defaults_api_headers())
            ->assertNotFound()->assertJsonPath('error.code', 'app.not_found');
        $this->postJson("/api/apps/{$app->name}/development-setup-steps", ['command' => '', 'timeout' => 0], app_defaults_api_headers())
            ->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
        $this->postJson("/api/apps/{$app->name}/development-setup-steps", ['command' => 'x', 'before' => 1, 'after' => 2], app_defaults_api_headers())
            ->assertUnprocessable();
        $step = AppDevelopmentSetupStep::factory()->for($app)->create();
        $this->deleteJson("/api/apps/{$app->name}/development-setup-steps/{$step->id}", [], app_defaults_api_headers())
            ->assertUnprocessable()->assertJsonPath('error.meta.reason', 'destructive_consent_required');
    });

    it('rejects an empty update body', function (): void {
        [$caller, $app] = app_defaults_api_target();
        $step = AppDevelopmentSetupStep::factory()->for($app)->create();

        $this->patchJson("/api/apps/{$app->name}/development-setup-steps/{$step->id}", [], app_defaults_api_headers())
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed');
    });

    it('logs mutation subject, effect, type, and properties', function (): void {
        [, $app] = app_defaults_api_target();
        $response = $this->postJson("/api/apps/{$app->name}/development-setup-steps", ['command' => 'x'], app_defaults_api_headers());
        $stepId = $response->json('success.data.step.id');
        $activity = Activity::query()->latest('id')->first();

        expect($activity)->not->toBeNull()
            ->and($activity->subject_type)->toBe(AppDevelopmentSetupStep::class)
            ->and((int) $activity->subject_id)->toBe((int) $stepId)
            ->and($activity->event)->toBe('api:POST /apps/{app}/development-setup-steps')
            ->and($activity->properties->get('type'))->toBe('write')
            ->and($activity->properties->get('app'))->toBe($app->name);

        $this->patchJson("/api/apps/{$app->name}/development-setup-steps/{$stepId}", ['command' => 'updated'], app_defaults_api_headers())->assertOk();
        expect(Activity::query()->latest('id')->first()->event)->toBe('api:PATCH /apps/{app}/development-setup-steps/{step}');

        $this->deleteJson("/api/apps/{$app->name}/development-setup-steps/{$stepId}", ['destructive_consent' => true], app_defaults_api_headers())->assertOk();
        $activity = Activity::query()->latest('id')->first();
        expect($activity->event)->toBe('api:DELETE /apps/{app}/development-setup-steps/{step}')
            ->and($activity->properties->get('type'))->toBe('destructive')
            ->and($activity->subject_type)->toBe(AppDevelopmentSetupStep::class)
            ->and((int) $activity->subject_id)->toBe((int) $stepId);
    });

    it('does not expose or accept a step from another app', function (): void {
        [, $app] = app_defaults_api_target();
        $other = App::factory()->create(['name' => 'other']);
        $step = AppDevelopmentSetupStep::factory()->for($other)->create();

        $this->patchJson("/api/apps/{$app->name}/development-setup-steps/{$step->id}", ['command' => 'x'], app_defaults_api_headers())
            ->assertNotFound()->assertJsonPath('error.code', 'app.development_setup_step_not_found');

        $this->patchJson("/api/apps/{$app->name}/development-setup-steps/999999", ['command' => 'x'], app_defaults_api_headers())
            ->assertNotFound()->assertJsonPath('error.code', 'app.development_setup_step_not_found');
        $this->deleteJson("/api/apps/{$app->name}/development-setup-steps/999999", ['destructive_consent' => true], app_defaults_api_headers())
            ->assertNotFound()->assertJsonPath('error.code', 'app.development_setup_step_not_found');
    });

    it('rejects invalid and cross-app anchors on add and update', function (): void {
        [, $app] = app_defaults_api_target();
        $step = AppDevelopmentSetupStep::factory()->for($app)->create();
        $other = App::factory()->create(['name' => 'other-anchor']);
        $foreign = AppDevelopmentSetupStep::factory()->for($other)->create();

        foreach ([['before' => 0], ['after' => 'nope'], ['before' => $step->id, 'after' => $step->id], ['before' => 999_999]] as $input) {
            $this->postJson("/api/apps/{$app->name}/development-setup-steps", ['command' => 'x'] + $input, app_defaults_api_headers())
                ->assertUnprocessable();
        }
        $this->patchJson("/api/apps/{$app->name}/development-setup-steps/{$step->id}", ['before' => $step->id, 'after' => $step->id], app_defaults_api_headers())
            ->assertUnprocessable();
        $this->postJson("/api/apps/{$app->name}/development-setup-steps", ['command' => 'x', 'before' => $foreign->id], app_defaults_api_headers())
            ->assertUnprocessable();
        $this->patchJson("/api/apps/{$app->name}/development-setup-steps/{$step->id}", ['after' => $foreign->id], app_defaults_api_headers())
            ->assertUnprocessable();
    });

    it('authorizes through any owned instance, including a non-first instance', function (): void {
        $serving = Node::factory()->appDev()->create(['name' => 'second-instance-node']);
        $app = App::factory()->create(['name' => 'multi']);
        Instance::factory()->for($app)->create(['name' => 'first', 'driver_config' => new OrbitInstanceDriverConfigData(node_id: Node::factory()->appDev()->create()->id)]);
        Instance::factory()->for($app)->create(['name' => 'second', 'driver_config' => new OrbitInstanceDriverConfigData(node_id: $serving->id)]);
        $caller = Node::factory()->create(['host' => APP_DEFAULTS_API_IP, 'wireguard_address' => APP_DEFAULTS_API_IP]);
        DB::table('node_access')->insert([
            'consumer_node_id' => $caller->id, 'serving_node_id' => $serving->id,
            'permissions' => json_encode(['app:write'], JSON_THROW_ON_ERROR), 'custom_permissions' => json_encode([]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postJson("/api/apps/{$app->name}/development-setup-steps", ['command' => 'x'], app_defaults_api_headers())
            ->assertCreated();
    });

    it('cascades defaults when the owning app is deleted', function (): void {
        [, $app] = app_defaults_api_target();
        $step = AppDevelopmentSetupStep::factory()->for($app)->create();

        $app->delete();
        expect(AppDevelopmentSetupStep::query()->whereKey($step->id)->exists())->toBeFalse();
    });
});
