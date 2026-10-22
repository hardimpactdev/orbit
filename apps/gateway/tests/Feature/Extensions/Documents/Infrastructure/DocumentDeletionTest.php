<?php

declare(strict_types=1);

use App\Domain\Shared\ResourceOperationException;
use App\Models\Project;
use Aws\Handler\Guzzle\GuzzleHandler;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Orbit\Extensions\Documents\Actions\DocumentTreeAction;
use Orbit\Extensions\Documents\Actions\UpdateDocumentStorageAction;
use Orbit\Extensions\Documents\Actions\WriteDocumentAction;
use Orbit\Extensions\Documents\Data\DocumentBody;
use Orbit\Extensions\Documents\Data\UpdateDocumentStorageData;
use Orbit\Extensions\Documents\Infrastructure\ProbeJournal;
use Orbit\Extensions\Documents\Models\ProjectDocumentCleanup;
use Orbit\Extensions\Documents\Models\ProjectDocumentEntry;
use Orbit\Extensions\Documents\Models\ProjectDocumentStorage;
use Orbit\Extensions\Documents\Models\ProjectDocumentUpload;
use Orbit\Extensions\Documents\Models\ProjectDocumentVersion;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;
use Tests\Support\SubprocessFixture;
use Tests\Support\WithDocumentDeletionFixture;

require_once __DIR__.'/../../../../Support/DocumentDeletionFixtures.php';

uses(WithDocumentDeletionFixture::class);

beforeEach(function (): void {
    $this->initializeDocumentDeletionFixture();
});

it('is a paused no-op including due claims and expired active intents', function (): void {
    deletion_provider($this, ['removed' => 'body']);
    $record = ProjectDocumentCleanup::query()->create(['storage_key' => 'removed', 'next_attempt_at' => now()]);
    $upload = ProjectDocumentUpload::query()->create(['project_id' => 999, 'storage_key' => 'active', 'state' => 'active', 'created_at' => now()->subHours(2)]);
    $before = $record->fresh()->getAttributes();
    expect(deletion_work())->toMatchArray(['claimed_count' => 0, 'deleted_count' => 0, 'failed_count' => 0]);
    expect($record->fresh()->getAttributes())->toBe($before);
    expect($upload->fresh()->state)->toBe('active');
    expect(deletion_requests())->toBe([]);
});

it('requires one real successful HTTP DELETE and retries failures with bounded backoff', function (string $mode): void {
    deletion_provider($this, ['removed' => 'body']);
    $record = ProjectDocumentCleanup::query()->create(['storage_key' => 'removed', 'next_attempt_at' => now()]);
    deletion_resume();
    deletion_bucket(['removed' => 'body'], $mode);
    expect(deletion_work())->toMatchArray(['claimed_count' => 1, 'deleted_count' => 0, 'failed_count' => 1]);
    expect(deletion_requests())->toHaveCount(1);
    expect($record->fresh())->pending->toBeTrue()->attempts->toBe(1)->last_error_code->toBe('project_documents.storage_unavailable')->claim_token->toBeNull();
    expect(deletion_work()['claimed_count'])->toBe(0);
    $record->refresh()->update(['attempts' => 100, 'next_attempt_at' => now()]);
    deletion_work();
    expect($record->fresh()->next_attempt_at->diffInSeconds(now(), absolute: true))->toBeBetween(3598, 3601);
    $record->refresh()->update(['next_attempt_at' => now()]);
    deletion_bucket(['removed' => 'body']);
    expect(deletion_work()['deleted_count'])->toBe(1);
    expect($record->fresh())->toBeNull();
    expect(deletion_requests())->toHaveCount(3);
})->with(['delete-500', 'delete-redirect']);

it('retains absent abandoned fences and deletes a late PUT using rotated credentials', function (): void {
    deletion_provider($this);
    $upload = ProjectDocumentUpload::query()->create(['project_id' => 999, 'storage_key' => 'late', 'state' => 'abandoned']);
    $record = ProjectDocumentCleanup::query()->create(['storage_key' => 'late', 'retained_fence' => true, 'next_attempt_at' => now()]);
    deletion_resume();
    expect(deletion_work()['deleted_count'])->toBe(1);
    expect($record->fresh())->pending->toBeFalse()->retained_fence->toBeTrue();
    foreach (['endpoint', 'region', 'bucket'] as $field) {
        $changes = array_fill_keys(['endpoint', 'region', 'bucket'], null);
        $changes[$field] = $field === 'endpoint' ? 'https://changed.example.test' : 'changed';
        expect(fn () => app(UpdateDocumentStorageAction::class)->handle(
            new UpdateDocumentStorageData($changes['endpoint'], $changes['region'], $changes['bucket'], null, null),
        ))->toThrow(ResourceOperationException::class, 'in use');
    }
    expect($upload->fresh()->state)->toBe('abandoned');
    expect(deletion_work()['claimed_count'])->toBe(0);
    deletion_bucket(['late' => 'late PUT']);
    ProjectDocumentStorage::query()->findOrFail(1)->update(['access_key_id' => 'rotated-fixture-access', 'secret_access_key' => 'rotated-fixture-secret']);
    $record->refresh()->update(['next_attempt_at' => now()]);
    expect(deletion_work()['deleted_count'])->toBe(1);
    expect($record->fresh()->pending)->toBeFalse();
    expect(deletion_requests())->toHaveCount(2);
    expect(json_decode(file_get_contents($this->deletionHome.'/bucket.json'), true)['objects'])->toBe([]);
});

it('recovers expired process claims without consuming unexpired claims and bounds a batch to 100', function (): void {
    deletion_empty_transport();
    DB::transaction(function (): void {
        for ($i = 0; $i < 102; $i++) {
            ProjectDocumentCleanup::query()->create(['storage_key' => 'removed-'.$i, 'next_attempt_at' => now(),
                'claim_token' => str_repeat('a', 64), 'claim_expires_at' => $i === 0 ? now()->addMinutes(10) : now()->subSecond()]);
        }
    });
    deletion_resume();
    expect(deletion_work())->toMatchArray(['claimed_count' => 100, 'deleted_count' => 100, 'failed_count' => 0]);
    expect(ProjectDocumentCleanup::query()->count())->toBe(2);
    expect(deletion_work()['deleted_count'])->toBe(1);
    expect(ProjectDocumentCleanup::query()->first()->storage_key)->toBe('removed-0');
});

it('claims pending tombstones and pending fences before more than 100 older idle fences', function (): void {
    $this->freezeTime();
    deletion_empty_transport();
    DB::transaction(function (): void {
        for ($i = 0; $i < 101; $i++) {
            $key = 'idle-'.$i;
            ProjectDocumentUpload::query()->create(['project_id' => 999, 'storage_key' => $key, 'state' => 'abandoned']);
            ProjectDocumentCleanup::query()->create(['storage_key' => $key, 'retained_fence' => true,
                'pending' => false, 'next_attempt_at' => now()->subMinutes(10)]);
        }
    });
    $tombstone = ProjectDocumentCleanup::query()->create(['storage_key' => 'pending-removal', 'next_attempt_at' => now()]);
    ProjectDocumentUpload::query()->create(['project_id' => 999, 'storage_key' => 'pending-fence', 'state' => 'abandoned']);
    $fence = ProjectDocumentCleanup::query()->create(['storage_key' => 'pending-fence', 'retained_fence' => true, 'next_attempt_at' => now()]);
    deletion_resume();

    expect(deletion_work())->toMatchArray(['claimed_count' => 100, 'deleted_count' => 100, 'failed_count' => 0]);

    expect($tombstone->fresh())->toBeNull();
    expect($fence->fresh())->pending->toBeFalse()->retained_fence->toBeTrue();
    expect(array_slice(array_column(deletion_requests(), 'path'), 0, 2))
        ->toBe(['/fixture-bucket/pending-removal', '/fixture-bucket/pending-fence']);
    expect(ProjectDocumentCleanup::query()->where('retained_fence', true)->count())->toBe(102);
    expect(deletion_work())->toMatchArray(['claimed_count' => 3, 'deleted_count' => 3, 'failed_count' => 0]);
    expect(ProjectDocumentCleanup::query()->where('next_attempt_at', '<=', now())->count())->toBe(0);
});

it('commits the retained published-row handoff but never deletes conflicting committed or active keys', function (): void {
    deletion_provider($this, ['removed' => 'body']);
    $published = ProjectDocumentUpload::query()->create(['project_id' => 999, 'storage_key' => 'removed', 'state' => 'published']);
    ProjectDocumentCleanup::query()->create(['storage_key' => 'removed', 'next_attempt_at' => now()]);
    deletion_resume();
    expect(deletion_work()['deleted_count'])->toBe(1);
    expect($published->fresh())->toBeNull();
    $active = ProjectDocumentUpload::query()->create(['project_id' => 999, 'storage_key' => 'active', 'state' => 'active']);
    $record = ProjectDocumentCleanup::query()->create(['storage_key' => 'active', 'next_attempt_at' => now()]);
    expect(deletion_work()['failed_count'])->toBe(1);
    expect($record->fresh()->last_error_code)->toBe('project_documents.cleanup_reference_conflict');
    expect($active->fresh()->state)->toBe('active');
    expect(deletion_requests())->toHaveCount(1);
});

it('deletes Project removal bodies without touching unknown objects or tracked probes', function (): void {
    deletion_provider($this, ['published' => 'body']);
    $name = 'deletion-'.Str::uuid();
    $project = Project::query()->create(['name' => $name, 'slug' => $name, 'repository_url' => 'https://github.com/example/'.$name.'.git']);
    $entry = ProjectDocumentEntry::query()->create(['project_id' => $project->id, 'kind' => 'file', 'name' => 'file.txt', 'sibling_scope' => 0]);
    $upload = ProjectDocumentUpload::query()->create(['project_id' => $project->id, 'entry_id' => $entry->id, 'storage_key' => 'published', 'state' => 'published']);
    $version = ProjectDocumentVersion::query()->create(['entry_id' => $entry->id, 'upload_id' => $upload->id, 'number' => 1,
        'media_type' => 'text/plain', 'size_bytes' => 4, 'sha256' => hash('sha256', 'body'), 'storage_key' => 'published', 'created_at' => now()]);
    $entry->update(['current_version_id' => $version->id]);
    deletion_resume();
    // A conflicting tombstone cannot delete committed bytes even with a running permit.
    $conflict = ProjectDocumentCleanup::query()->create(['storage_key' => 'published', 'next_attempt_at' => now()]);
    expect(deletion_work()['failed_count'])->toBe(1);
    expect(deletion_requests())->toBe([]);
    DB::transaction(function () use ($project): void {
        app(DocumentTreeAction::class)->removeProject($project->id);
        $project->delete();
    });
    expect($upload->fresh()->state)->toBe('published');
    expect($version->fresh())->toBeNull();
    expect($conflict->fresh())->not->toBeNull();
    $conflict->refresh()->update(['next_attempt_at' => now()]);
    $journal = app(ProbeJournal::class);
    $probe = $journal->create(ProjectDocumentStorage::query()->findOrFail(1));
    deletion_bucket(['published' => 'body', 'unknown' => 'preserve', $probe->key() => 'synthetic']);
    expect(deletion_work()['deleted_count'])->toBe(1);
    expect($upload->fresh())->toBeNull();
    expect(array_column(deletion_requests(), 'path'))->toBe(['/fixture-bucket/published']);
    expect(array_keys(json_decode(file_get_contents($this->deletionHome.'/bucket.json'), true)['objects']))->toBe(['unknown', $probe->key()]);
    expect($journal->find($probe->id)->failureCount)->toBe(0);
});

it('recovers process death before or after real DELETE without retiring durable authority early', function (string $mode): void {
    deletion_provider($this, ['removed' => 'body']);
    $upload = ProjectDocumentUpload::query()->create(['project_id' => 999, 'storage_key' => 'removed', 'state' => 'published']);
    $record = ProjectDocumentCleanup::query()->create(['storage_key' => 'removed', 'next_attempt_at' => now()]);
    deletion_resume();
    $child = deletion_child($mode);
    $child->start();
    try {
        $child->wait();
    } catch (ProcessSignaledException) {
        // SIGKILL deliberately prevents any result transaction or claim release.
    }
    expect($child->getTermSignal())->toBe(9);
    if ($mode === 'before-handoff') {
        expect($upload->fresh()->state)->toBe('published');
    } else {
        expect($upload->fresh())->toBeNull();
    }
    expect($record->fresh())->pending->toBeTrue()->attempts->toBe(0);
    expect($record->fresh()->claim_token)->not->toBeNull();
    expect(deletion_requests())->toHaveCount($mode === 'after-delete' ? 1 : 0);
    expect(deletion_work()['claimed_count'])->toBe(0);
    $record->refresh()->update(['claim_expires_at' => now()->subSecond()]);
    expect(deletion_work()['deleted_count'])->toBe(1);
    expect($record->fresh())->toBeNull();
    expect(deletion_requests())->toHaveCount($mode === 'after-delete' ? 2 : 1);
})->with(['before-handoff', 'before-delete', 'after-delete']);

it('serializes publication and abandonment and catches a PUT finishing after an absent-key DELETE', function (): void {
    deletion_provider($this);
    $project = deletion_project();
    deletion_resume();
    $http = new GuzzleHandler(new Client);
    $interleave = true;
    config(['filesystems.disks.documents.http_handler' => function (RequestInterface $request, array $options) use ($http, &$interleave) {
        if ($request->getMethod() === 'PUT' && $interleave) {
            $interleave = false;
            ProjectDocumentUpload::query()->where('state', 'active')->update(['created_at' => now()->subHours(2)]);
            expect(deletion_work()['deleted_count'])->toBe(1);
            expect(ProjectDocumentCleanup::query()->first()->pending)->toBeFalse();
        }

        return $http($request, $options);
    }]);
    $writer = app(WriteDocumentAction::class);
    expect(fn () => $writer->create($project->id, 'late.txt', null, DocumentBody::text('late PUT'), null))
        ->toThrow(ResourceOperationException::class, 'abandoned');
    expect(ProjectDocumentVersion::query()->count())->toBe(0);
    expect(ProjectDocumentUpload::query()->first()->state)->toBe('abandoned');
    expect(ProjectDocumentCleanup::query()->first()->retained_fence)->toBeTrue();
    expect(deletion_work()['deleted_count'])->toBe(1);
    // If publication wins first, age alone never authorizes cleanup.
    $writer->create($project->id, 'winner.txt', null, DocumentBody::text('published'), null);
    ProjectDocumentUpload::query()->where('state', 'published')->update(['created_at' => now()->subHours(2)]);
    expect(deletion_work()['deleted_count'])->toBe(0);
    expect(ProjectDocumentVersion::query()->count())->toBe(1);
    expect(deletion_requests())->toHaveCount(2);
});

it('holds the execution lock through real HTTP completion and pause defeats the next batch', function (): void {
    deletion_provider($this, ['removed' => 'body']);
    ProjectDocumentCleanup::query()->create(['storage_key' => 'removed', 'next_attempt_at' => now()]);
    deletion_resume();
    $worker = deletion_child('hold');
    // Include startup and waiting for the worker's execution lock to be released.
    $pause = new Process([PHP_BINARY, base_path('tests/Fixtures/ProjectDocuments/cleanup_gate_execution.php'), $this->deletionHome.'/runtime', 'pause'], timeout: 60);
    try {
        $worker->start();
        SubprocessFixture::waitUntil($worker, function (): bool {
            clearstatcache(true, $this->deletionHome.'/authorized');

            return is_file($this->deletionHome.'/authorized');
        });
        expect(is_file($this->deletionHome.'/authorized'))->toBeTrue($worker->getErrorOutput());
        $pause->start();
        expect(SubprocessFixture::line($pause))->toBe('pause-starting');
        expect($pause->isRunning())->toBeTrue();
        expect(deletion_requests())->toBe([]);
        touch($this->deletionHome.'/release');
        $worker->wait();
        $pause->wait();
        expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());
        expect($pause->isSuccessful())->toBeTrue($pause->getErrorOutput());
        expect(deletion_requests())->toHaveCount(1);
        ProjectDocumentCleanup::query()->create(['storage_key' => 'next', 'next_attempt_at' => now()]);
        expect(deletion_work()['claimed_count'])->toBe(0);
        expect(deletion_requests())->toHaveCount(1);
    } finally {
        touch($this->deletionHome.'/release');
        $worker->stop();
        $pause->stop();
    }
});
