<?php

declare(strict_types=1);

use Orbit\Extensions\Documents\Infrastructure\CleanupGate;
use Orbit\Extensions\Documents\Models\ProjectDocumentCleanup;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Tests\Support\WithDocumentDeletionFixture;

require_once __DIR__.'/../../../../Support/DocumentDeletionFixtures.php';

uses(WithDocumentDeletionFixture::class);

pest()->group('subprocess');

beforeEach(function (): void {
    $this->initializeDocumentDeletionFixture();
});

it('resumes recurring children and pauses them on restart', function (): void {
    deletion_provider($this, ['restored' => 'preserved body', 'next' => 'next body']);
    $restored = ProjectDocumentCleanup::query()->create(['storage_key' => 'restored', 'next_attempt_at' => now()]);
    $next = ProjectDocumentCleanup::query()->create(['storage_key' => 'next', 'next_attempt_at' => now()->addYears(20)]);
    deletion_resume();
    $oldGeneration = app(CleanupGate::class)->status()->generation;
    deletion_scheduler_clock('2030-01-01T00:00:01Z');
    $consumer = deletion_scheduler();
    $restart = deletion_scheduler();
    try {
        deletion_scheduler_start($consumer);
        $generation = app(CleanupGate::class)->status()->generation;
        expect($generation)->not->toBe($oldGeneration);
        expect(deletion_scheduler_tick($consumer, '2030-01-01T00:05:00Z'))->toMatchArray(['cleanup_state' => 'paused', 'cleanup_generation' => $generation]);
        expect(deletion_requests())->toBe([]);
        expect($restored->fresh()->pending)->toBeTrue();

        deletion_resume();
        expect(deletion_scheduler_tick($consumer, '2030-01-01T00:10:00Z'))->toMatchArray(['cleanup_state' => 'running', 'cleanup_generation' => $generation]);
        expect($restored->fresh())->toBeNull();
        expect($next->fresh()->pending)->toBeTrue();
        expect(array_column(deletion_requests(), 'path'))->toBe(['/fixture-bucket/restored']);
        $next->update(['next_attempt_at' => now()]);
        expect(deletion_scheduler_tick($consumer, '2030-01-01T00:15:00Z'))->toMatchArray(['cleanup_state' => 'running', 'cleanup_generation' => $generation]);
        expect($next->fresh())->toBeNull();
        expect(array_column(deletion_requests(), 'path'))->toBe(['/fixture-bucket/restored', '/fixture-bucket/next']);

        $paused = app(CleanupGate::class)->invalidate();
        deletion_bucket(['paused' => 'preserve after pause']);
        $pending = ProjectDocumentCleanup::query()->create(['storage_key' => 'paused', 'next_attempt_at' => now()]);
        expect(deletion_scheduler_tick($consumer, '2030-01-01T00:20:00Z'))->toMatchArray(['cleanup_state' => 'paused', 'cleanup_generation' => $paused->generation]);
        expect($pending->fresh()->pending)->toBeTrue();
        expect(deletion_requests())->toHaveCount(2);
        deletion_resume();
        expect(deletion_scheduler_tick($consumer, '2030-01-01T00:25:00Z'))->toMatchArray(['cleanup_state' => 'running', 'cleanup_generation' => $paused->generation]);
        expect($pending->fresh())->toBeNull();
        expect(deletion_requests())->toHaveCount(3);

        $staleToken = file_get_contents($this->deletionHome.'/runtime/scheduler.session');
        $consumer->signal(SIGKILL);
        try {
            $consumer->wait();
        } catch (ProcessSignaledException) {
            // The session file cannot stand in for the daemon's lifetime lock.
        }
        deletion_bucket(['restart' => 'preserve after death']);
        $afterDeath = ProjectDocumentCleanup::query()->create(['storage_key' => 'restart', 'next_attempt_at' => now()]);
        deletion_scheduler_clock('2030-01-01T00:30:00Z');
        $standalone = deletion_scheduler('schedule:run', $staleToken);
        expect($standalone->run())->toBe(0, $standalone->getErrorOutput());
        expect(app(CleanupGate::class)->status()->state)->toBe('paused');
        expect($afterDeath->fresh()->pending)->toBeTrue();
        expect(deletion_requests())->toHaveCount(3);

        deletion_scheduler_clock('2030-01-01T00:30:01Z');
        deletion_scheduler_start($restart);
        $restartedGeneration = app(CleanupGate::class)->status()->generation;
        expect($restartedGeneration)->not->toBe($paused->generation);
        expect(deletion_scheduler_tick($restart, '2030-01-01T00:35:00Z'))->toMatchArray(['cleanup_state' => 'paused', 'cleanup_generation' => $restartedGeneration]);
        expect($afterDeath->fresh()->pending)->toBeTrue();
        expect(deletion_requests())->toHaveCount(3);
        deletion_resume();
        expect(deletion_scheduler_tick($restart, '2030-01-01T00:40:00Z'))->toMatchArray(['cleanup_state' => 'running', 'cleanup_generation' => $restartedGeneration]);
        expect($afterDeath->fresh())->toBeNull();
        expect(array_column(deletion_requests(), 'path'))->toBe(['/fixture-bucket/restored', '/fixture-bucket/next', '/fixture-bucket/paused', '/fixture-bucket/restart']);
    } finally {
        $consumer->stop();
        $restart->stop();
    }
});
