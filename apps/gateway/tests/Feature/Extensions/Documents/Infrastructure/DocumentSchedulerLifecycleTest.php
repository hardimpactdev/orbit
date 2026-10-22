<?php

declare(strict_types=1);

use Orbit\Extensions\Documents\Infrastructure\CleanupGate;
use Orbit\Extensions\Documents\Models\ProjectDocumentCleanup;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Tests\Support\DocumentSchedulerFixture;
use Tests\Support\WithDocumentDeletionFixture;

require_once __DIR__.'/../../../../Support/DocumentDeletionFixtures.php';

uses(WithDocumentDeletionFixture::class);

pest()->group('subprocess');

beforeAll(fn () => DocumentSchedulerFixture::prepareBootstrap());

beforeEach(function (): void {
    $this->initializeDocumentDeletionFixture();
});

it('pauses at startup and resumes successive recurring children', function (): void {
    deletion_provider($this, ['restored' => 'preserved body', 'next' => 'next body']);
    $restored = ProjectDocumentCleanup::query()->create(['storage_key' => 'restored', 'next_attempt_at' => now()]);
    $next = ProjectDocumentCleanup::query()->create(['storage_key' => 'next', 'next_attempt_at' => now()->addYears(20)]);
    deletion_resume();
    $oldGeneration = app(CleanupGate::class)->status()->generation;
    deletion_scheduler_clock('2030-01-01T00:00:01Z');
    $consumer = deletion_scheduler();
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
    } finally {
        $consumer->process->stop(0);
    }
});

it('preserves an explicit pause until a live worker resumes', function (): void {
    deletion_provider($this, ['restored' => 'preserved body', 'next' => 'next body']);
    foreach (['restored', 'next'] as $key) {
        ProjectDocumentCleanup::query()->create(['storage_key' => $key, 'next_attempt_at' => now()]);
    }
    deletion_scheduler_clock('2030-01-01T00:00:01Z');
    $consumer = deletion_scheduler();
    try {
        deletion_scheduler_start($consumer);
        deletion_resume();
        $generation = app(CleanupGate::class)->status()->generation;
        expect(deletion_scheduler_tick($consumer, '2030-01-01T00:15:00Z'))->toMatchArray(['cleanup_state' => 'running', 'cleanup_generation' => $generation]);
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
        expect(array_column(deletion_requests(), 'path'))->toBe(['/fixture-bucket/restored', '/fixture-bucket/next', '/fixture-bucket/paused']);
    } finally {
        $consumer->process->stop(0);
    }
});

it('fences the stale token of a dead worker from a standalone child', function (): void {
    deletion_provider($this, ['restored' => 'preserved body', 'next' => 'next body', 'paused' => 'preserve after pause']);
    foreach (['restored', 'next', 'paused'] as $key) {
        ProjectDocumentCleanup::query()->create(['storage_key' => $key, 'next_attempt_at' => now()]);
    }
    deletion_resume();
    deletion_scheduler_clock('2030-01-01T00:00:01Z');
    $consumer = deletion_scheduler();
    try {
        deletion_scheduler_start($consumer);
        deletion_resume();
        $generationBeforeDeath = app(CleanupGate::class)->status()->generation;
        expect(deletion_scheduler_tick($consumer, '2030-01-01T00:25:00Z'))->toMatchArray(['cleanup_state' => 'running', 'cleanup_generation' => $generationBeforeDeath]);
        expect(array_column(deletion_requests(), 'path'))->toBe(['/fixture-bucket/restored', '/fixture-bucket/next', '/fixture-bucket/paused']);

        $staleToken = file_get_contents($this->deletionHome.'/runtime/scheduler.session');
        $consumer->process->signal(SIGKILL);
        try {
            $consumer->process->wait();
        } catch (ProcessSignaledException) {
            // The session file cannot stand in for the daemon's lifetime lock.
        }
        deletion_bucket(['restart' => 'preserve after death']);
        $afterDeath = ProjectDocumentCleanup::query()->create(['storage_key' => 'restart', 'next_attempt_at' => now()]);
        deletion_scheduler_clock('2030-01-01T00:30:00Z');
        $standalone = deletion_scheduler('schedule:run', $staleToken);
        expect($standalone->process->run())->toBe(0, $standalone->process->getErrorOutput());
        expect(app(CleanupGate::class)->status()->state)->toBe('paused');
        expect($afterDeath->fresh()->pending)->toBeTrue();
        expect(deletion_requests())->toHaveCount(3);
    } finally {
        $consumer->process->stop(0);
    }
});

it('pauses recurring children after a daemon restart until resumed', function (): void {
    deletion_provider($this, ['restored' => 'preserved body', 'next' => 'next body', 'paused' => 'preserve after pause']);
    foreach (['restored', 'next', 'paused'] as $key) {
        ProjectDocumentCleanup::query()->create(['storage_key' => $key, 'next_attempt_at' => now()]);
    }
    deletion_scheduler_clock('2030-01-01T00:00:01Z');
    $consumer = deletion_scheduler();
    $restart = deletion_scheduler();
    try {
        deletion_scheduler_start($consumer);
        deletion_resume();
        $generationBeforeDeath = app(CleanupGate::class)->status()->generation;
        // Earlier deletions are setup here; the separate death proof dispatches them through the old worker.
        deletion_work();
        expect(array_column(deletion_requests(), 'path'))->toBe(['/fixture-bucket/restored', '/fixture-bucket/next', '/fixture-bucket/paused']);
        $consumer->process->signal(SIGKILL);
        try {
            $consumer->process->wait();
        } catch (ProcessSignaledException) {
            // Restart must invalidate even the permit left by an unclean daemon exit.
        }
        deletion_bucket(['restart' => 'preserve after death']);
        $afterDeath = ProjectDocumentCleanup::query()->create(['storage_key' => 'restart', 'next_attempt_at' => now()]);

        deletion_scheduler_clock('2030-01-01T00:30:01Z');
        deletion_scheduler_start($restart);
        $restartedGeneration = app(CleanupGate::class)->status()->generation;
        expect($restartedGeneration)->not->toBe($generationBeforeDeath);
        expect(deletion_scheduler_tick($restart, '2030-01-01T00:35:00Z'))->toMatchArray(['cleanup_state' => 'paused', 'cleanup_generation' => $restartedGeneration]);
        expect($afterDeath->fresh()->pending)->toBeTrue();
        expect(deletion_requests())->toHaveCount(3);
        deletion_resume();
        expect(deletion_scheduler_tick($restart, '2030-01-01T00:40:00Z'))->toMatchArray(['cleanup_state' => 'running', 'cleanup_generation' => $restartedGeneration]);
        expect($afterDeath->fresh())->toBeNull();
        expect(array_column(deletion_requests(), 'path'))->toBe(['/fixture-bucket/restored', '/fixture-bucket/next', '/fixture-bucket/paused', '/fixture-bucket/restart']);
    } finally {
        $consumer->process->stop(0);
        $restart->process->stop(0);
    }
});
