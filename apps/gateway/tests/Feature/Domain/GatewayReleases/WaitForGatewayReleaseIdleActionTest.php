<?php

declare(strict_types=1);

use App\Console\Commands\WaitForGatewayReleaseIdleCommand;
use App\Models\Operation;
use Carbon\CarbonInterval;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Sleep;
use Illuminate\Testing\PendingCommand;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

uses(LazilyRefreshDatabase::class);

/** An Operation whose state the release guard must read from the database on every poll. */
function releaseBlocker(string $kind, string $status = 'running'): Operation
{
    return Operation::query()->create(['kind' => $kind, 'target_type' => 'node', 'target_id' => 1,
        'status' => $status, 'input' => [], 'triggered_by' => 'test', 'state' => []]);
}

it('proceeds immediately when only terminal or unrelated Operations exist', function (): void {
    /** @var TestCase $this */
    Sleep::fake();
    foreach (['succeeded', 'failed', 'cancelled', 'interrupted'] as $status) {
        releaseBlocker('instance.create', $status);
        releaseBlocker('instance.deploy', $status);
        releaseBlocker('conn.setup', $status);
        releaseBlocker('process.restart', $status);
    }
    foreach (['gateway.release', 'task_vm.image_refresh', 'connection.setup', 'processes.start'] as $kind) {
        releaseBlocker($kind);
    }

    /** @var PendingCommand $command */
    $command = $this->artisan('gateway:release:wait-idle');
    $command->assertSuccessful()->run();

    Sleep::assertNeverSlept();
});

it('waits then refuses each protected kind family and active status after ten minutes', function (string $kind, string $status): void {
    /** @var TestCase $this */
    $this->freezeTime();
    Sleep::fake(syncWithCarbon: true);
    $blocker = releaseBlocker($kind, $status);

    /** @var PendingCommand $command */
    $command = $this->artisan('gateway:release:wait-idle');
    $command
        ->expectsOutputToContain('gateway.release_busy: Active Operations after 600 seconds: '.$blocker->id)
        ->assertFailed()->run();

    Sleep::assertSlept(fn (CarbonInterval $duration): bool => $duration->totalSeconds === 5.0, 120);
    expect($blocker->refresh()->status->value)->toBe($status);
})->with(['instance.create', 'instance.deploy', 'instance.deploy_production', 'instance.deploy.preview', 'instance.migrate', 'instance.clone', 'instance.destroy', 'instance.restore', 'conn.setup', 'process.restart'])
    ->with(['queued', 'running', 'waiting']);

it('proceeds on the next poll after each added lifecycle kind settles', function (string $kind, string $status): void {
    /** @var TestCase $this */
    $this->freezeTime();
    Sleep::fake(syncWithCarbon: true);
    $blocker = releaseBlocker($kind, $status);
    Sleep::whenFakingSleep(fn () => $blocker->update(['status' => 'succeeded']));

    /** @var PendingCommand $command */
    $command = $this->artisan('gateway:release:wait-idle');
    $command->assertSuccessful()->run();

    Sleep::assertSequence([Sleep::for(5)->seconds()]);
})->with(['instance.migrate', 'instance.clone', 'instance.destroy', 'instance.restore'])->with(['queued', 'running', 'waiting']);

it('reports the remaining blocker IDs from the final poll', function (): void {
    /** @var TestCase $this */
    $this->freezeTime();
    Sleep::fake(syncWithCarbon: true);
    $finished = releaseBlocker('instance.create');
    $first = releaseBlocker('conn.setup', 'queued');
    $second = releaseBlocker('process.start', 'waiting');
    Sleep::whenFakingSleep(fn () => $finished->update(['status' => 'succeeded']));

    /** @var PendingCommand $command */
    $command = $this->artisan('gateway:release:wait-idle');
    $command
        ->expectsOutputToContain('gateway.release_busy: Active Operations after 600 seconds: '.$first->id.', '.$second->id)
        ->assertFailed()->run();

    Sleep::assertSleptTimes(120);
});

it('writes the busy refusal to stderr, where the release SSH step reads its failure', function (): void {
    /** @var TestCase $this */
    $this->freezeTime();
    Sleep::fake(syncWithCarbon: true);
    $blocker = releaseBlocker('instance.create');
    $command = app(WaitForGatewayReleaseIdleCommand::class);
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    $tester->execute([], ['capture_stderr_separately' => true]);

    expect($tester->getStatusCode())->toBe(1)
        ->and($tester->getErrorOutput())->toContain('gateway.release_busy: Active Operations after 600 seconds: '.$blocker->id)
        ->and($tester->getDisplay())->not->toContain('gateway.release_busy');
});

it('does not wait for excluded proof, VM or scheduled vault work', function (string $kind, string $status): void {
    /** @var TestCase $this */
    Sleep::fake();
    $operation = releaseBlocker($kind, $status);

    /** @var PendingCommand $command */
    $command = $this->artisan('gateway:release:wait-idle');
    $command->assertSuccessful()->run();

    Sleep::assertNeverSlept();
    expect($operation->refresh()->status->value)->toBe($status);
})->with(['proof.run', 'proof.command', 'proof.verify', 'task_vm.image_refresh', 'task_vm.resume', 'vault.sync'])
    ->with(['queued', 'running', 'waiting']);
