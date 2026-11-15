<?php

declare(strict_types=1);

use App\Domain\Schedules\NativeSchedules;
use App\Domain\Schedules\ScheduleTarget;
use App\Models\Node;
use App\Models\Schedule;
use App\Support\ValidatedData;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);
beforeEach(fn () => SwampNodeFixture::ensure());

/**
 * The workflow that the Gateway writes on serve for a native Schedule, as NativeSchedules builds it (JSON is YAML).
 *
 * @return array<string,mixed>
 */
function nativeScheduleWorkflow(): array
{
    $node = (new Node)->forceFill(['id' => 214, 'wireguard_ip' => '192.0.2.90', 'user' => 'orbit',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'approved-host-key']);
    $schedule = (new Schedule)->forceFill(['id' => 31, 'name' => 'nightly', 'command' => 'php artisan schedule:run', 'timeout_seconds' => 300,
        'target_type' => 'node', 'target_id' => 214]);
    $target = new ScheduleTarget($node, 'orbit', null, null, null, null, false, null);
    $content = Closure::bind(fn (Schedule $schedule, ScheduleTarget $target, string $id): string => $this->content($schedule, $target, $id),
        app(NativeSchedules::class), NativeSchedules::class);

    return ValidatedData::object(json_decode($content($schedule, $target, 'wf-31'), true, flags: JSON_THROW_ON_ERROR));
}

it('writes a Schedule workflow whose fire stays interrupted when serve restarts', function (): void {
    $workflow = nativeScheduleWorkflow();

    // From Swamp 537e03d1 serve continues an interrupted run at boot unless the workflow opts out (swamp/docs/swamp-shape.md, rule 6).
    expect($workflow)->toHaveKey('autoContinue')
        ->and($workflow['autoContinue'])->toBeFalse()
        ->and($workflow['name'])->toBe('schedule-31');
});
