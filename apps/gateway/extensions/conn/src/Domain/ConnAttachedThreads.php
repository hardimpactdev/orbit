<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Domain\Shared\StoredInteger;
use App\Models\Node;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Orbit\Extensions\Conn\Actions\ApplyConnProfileSectionsAction;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnProfile;
use RuntimeException;
use Throwable;

/**
 * The `attachedThreads` section of a profile ([Attached threads](/reference/conn#attached-threads)): the threads on the
 * profile's list, as environment and thread id. A device connects to the environments of this set that it may see.
 *
 * An entry that is already in the stored set (the same environment and thread) may stay, so a device can carry an entry
 * it cannot see itself through a merge. A new entry must name an environment that exists and that the writing Node may
 * see. An entry whose environment is gone is dropped: when its Instance is destroyed, with a new section version, and
 * from every read and write before that.
 */
final readonly class ConnAttachedThreads
{
    public function __construct(private ConnServerAccess $access) {}

    /**
     * The entries of a section value, or none for a value that is not a section.
     *
     * @return list<array{environmentId: string, threadId: string}>
     */
    public static function entries(mixed $section): array
    {
        $threads = is_array($section) && is_array($section['threads'] ?? null) ? $section['threads'] : [];
        $entries = [];

        foreach ($threads as $thread) {
            if (is_array($thread) && is_string($thread['environmentId'] ?? null) && is_string($thread['threadId'] ?? null)) {
                $entries[] = ['environmentId' => $thread['environmentId'], 'threadId' => $thread['threadId']];
            }
        }

        return $entries;
    }

    /**
     * The section value to store. Runs on the locked profile after the version check, so `$stored` is the value the
     * client merged from.
     *
     * @param  array<mixed>  $value
     * @return array<mixed>
     *
     * @throws ValidationException when a new entry names an environment that is gone or that the caller may not see
     */
    public function admit(array $value, mixed $stored, ?Node $caller): array
    {
        $entries = self::entries($value);
        $existing = $this->existing(array_column($entries, 'environmentId'));
        $carried = array_map(self::key(...), self::entries($stored));
        $new = array_values(array_intersect($existing, array_column(array_filter($entries, static fn (array $entry): bool => ! in_array(self::key($entry), $carried, true)), 'environmentId')));
        $visible = $new === [] || ! $caller instanceof Node ? [] : $this->access
            ->visible($caller, ConnEnvironment::query()->whereIn('environment_id', $new))
            ->get(['environment_id'])->map(static fn (ConnEnvironment $environment): string => $environment->environment_id)->all();
        $kept = [];
        $errors = [];

        foreach ($entries as $index => $entry) {
            $environment = $entry['environmentId'];

            if (in_array(self::key($entry), $carried, true)) {
                if (in_array($environment, $existing, true)) {
                    $kept[] = $entry;
                }
            } elseif (in_array($environment, $visible, true)) {
                $kept[] = $entry;
            } else {
                $errors["sections.attachedThreads.value.threads.{$index}.environmentId"] = ["The environment {$environment} is not a Conn environment you may see."];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [...$value, 'threads' => $kept];
    }

    /** @param array{environmentId: string, threadId: string} $entry */
    private static function key(array $entry): string
    {
        // An environment id holds no colon, so the key names one entry.
        return $entry['environmentId'].':'.$entry['threadId'];
    }

    /**
     * The document as a read shows it: without the entries whose environment is gone. The stored document and the
     * section version stay as they are.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function withoutGone(array $settings): array
    {
        $section = $settings[ConnProfileSections::AttachedThreads] ?? null;

        if (! is_array($section)) {
            return $settings;
        }

        $entries = self::entries($section);
        $existing = $this->existing(array_column($entries, 'environmentId'));
        $section['threads'] = array_values(array_filter($entries, static fn (array $entry): bool => in_array($entry['environmentId'], $existing, true)));
        $settings[ConnProfileSections::AttachedThreads] = $section;

        return $settings;
    }

    /**
     * Removes the entries of environments that are gone from every profile that holds one. Only those profiles are read.
     * Each changed profile gets a new section and document version, written by Orbit, so a device rereads and merges the
     * removal. The write only shrinks the document, so no limit refuses it ({@see ConnProfileSections::assertWithinLimits()}).
     *
     * The drop never fails, rolls back or delays the destroy that calls it. It runs after the destroy commits, so no
     * statement of it shares the destroy's transaction, and each profile is written in its own. A profile that cannot be
     * read or written is logged as an error and skipped; the Gateway's log collector records it as a Problem. Its entries
     * stay stored, and every read leaves them out ({@see self::withoutGone()}).
     *
     * @param  list<string>  $environmentIds
     */
    public function drop(array $environmentIds): void
    {
        if ($environmentIds !== []) {
            // Laravel skips the after-commit callbacks registered later when one throws, such as the destroy's broadcasts.
            DB::afterCommit(function () use ($environmentIds): void {
                try {
                    $this->dropNow($environmentIds);
                } catch (Throwable) {
                    // dropNow() logs each failure itself; nothing of it may reach the destroy.
                }
            });
        }
    }

    /** @param  list<string>  $environmentIds */
    private function dropNow(array $environmentIds): void
    {
        try {
            // A document that is not JSON cannot be read; log only one whose text names a dropped environment.
            $unreadable = DB::table('conn_profiles')->whereRaw('NOT json_valid(settings)')->where(static function (Builder $query) use ($environmentIds): void {
                foreach ($environmentIds as $environmentId) {
                    $query->orWhereRaw('instr(settings, ?) > 0', [$environmentId]);
                }
            });

            foreach ($unreadable->orderBy('id')->pluck('id') as $id) {
                $this->dropFailed(StoredInteger::from($id), $environmentIds, new RuntimeException('The settings are not valid JSON.'));
            }

            $holders = ConnProfile::query()->whereExists(static fn (Builder $query) => $query->selectRaw('1')
                // json_tree walks every element without parsing it as an object, so a malformed element cannot fail the
                // query, and a document that is not JSON is read as an empty one; the loop below confirms each match.
                ->fromRaw("json_tree(CASE WHEN json_valid(conn_profiles.settings) THEN conn_profiles.settings ELSE '{}' END, '$.attachedThreads.threads')")
                ->where('key', 'environmentId')->whereIn('atom', $environmentIds))->orderBy('id')->toBase()->pluck('id')->map(StoredInteger::from(...));
        } catch (Throwable $error) {
            $this->dropFailed(null, $environmentIds, $error);

            return;
        }

        foreach ($holders as $id) {
            try {
                DB::transaction(function () use ($id, $environmentIds): void {
                    $profile = ConnProfile::query()->lockForUpdate()->find($id);

                    if (! $profile instanceof ConnProfile) {
                        return;
                    }

                    $section = $profile->settings[ConnProfileSections::AttachedThreads] ?? null;
                    $entries = self::entries($section);
                    $kept = array_values(array_filter($entries, static fn (array $entry): bool => ! in_array($entry['environmentId'], $environmentIds, true)));

                    if (is_array($section) && count($kept) !== count($entries)) {
                        app(ApplyConnProfileSectionsAction::class)->execute($profile, null, [ConnProfileSections::AttachedThreads => [...$section, 'threads' => $kept]]);
                    }
                });
            } catch (Throwable $error) {
                $this->dropFailed($id, $environmentIds, $error);
            }
        }
    }

    /**
     * Logs a profile the drop skipped. The wrapping exception has an integer code, so the log collector keeps it as a
     * signal; a database error's own code is an SQLSTATE string. A log channel that fails is ignored.
     *
     * @param  list<string>  $environmentIds
     */
    private function dropFailed(?int $profileId, array $environmentIds, Throwable $error): void
    {
        $profile = $profileId !== null ? "profile {$profileId}" : 'the profiles';

        try {
            Log::error('conn.attached_threads_drop_failed', [
                'profile_id' => $profileId,
                'environment_ids' => $environmentIds,
                'exception' => new RuntimeException('Conn could not drop the attached threads of environments '.implode(', ', $environmentIds)." from {$profile}.", 0, $error),
            ]);
        } catch (Throwable) {
            // The log is the only report; a failing channel must not reach the destroy.
        }
    }

    /**
     * @param  list<string>  $environmentIds
     * @return list<string>
     */
    private function existing(array $environmentIds): array
    {
        if ($environmentIds === []) {
            return [];
        }

        return array_values(ConnEnvironment::query()->whereIn('environment_id', array_values(array_unique($environmentIds)))
            ->get(['environment_id'])->map(static fn (ConnEnvironment $environment): string => $environment->environment_id)->all());
    }
}
