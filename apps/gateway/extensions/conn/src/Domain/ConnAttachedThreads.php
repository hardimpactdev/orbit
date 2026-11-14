<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Models\Node;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Orbit\Extensions\Conn\Actions\ApplyConnProfileSectionsAction;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnProfile;

/**
 * The `attachedThreads` section of a profile ([Attached threads](/reference/conn#attached-threads)): the threads on the
 * profile's list, as environment and thread id. A device connects to the environments of this set that it may see.
 *
 * An environment that is already in the stored set stays admitted, so a device can carry an entry it cannot see itself
 * through a merge. A new environment must exist and be one the writing Node may see. An entry whose environment is gone
 * is dropped: when its Instance is destroyed, with a new section version, and from every read and write before that.
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
     * @throws ValidationException when a new environment is gone or the caller may not see it
     */
    public function admit(array $value, mixed $stored, ?Node $caller): array
    {
        $entries = self::entries($value);
        $existing = $this->existing(array_column($entries, 'environmentId'));
        $carried = array_column(self::entries($stored), 'environmentId');
        $new = array_values(array_diff($existing, $carried));
        $visible = $new === [] || ! $caller instanceof Node ? [] : $this->access
            ->visible($caller, ConnEnvironment::query()->whereIn('environment_id', $new))
            ->get(['environment_id'])->map(static fn (ConnEnvironment $environment): string => $environment->environment_id)->all();
        $kept = [];
        $errors = [];

        foreach ($entries as $index => $entry) {
            $environment = $entry['environmentId'];

            if (in_array($environment, $carried, true)) {
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
     * Removes the entries of environments that are gone from every profile that holds one. Each changed profile gets a
     * new section and document version, written by Orbit, so a device rereads and merges the removal.
     *
     * @param  list<string>  $environmentIds
     */
    public function drop(array $environmentIds): void
    {
        if ($environmentIds === []) {
            return;
        }

        DB::transaction(function () use ($environmentIds): void {
            foreach (ConnProfile::query()->lockForUpdate()->get() as $profile) {
                $section = $profile->settings[ConnProfileSections::AttachedThreads] ?? null;
                $entries = self::entries($section);
                $kept = array_values(array_filter($entries, static fn (array $entry): bool => ! in_array($entry['environmentId'], $environmentIds, true)));

                if (is_array($section) && count($kept) !== count($entries)) {
                    app(ApplyConnProfileSectionsAction::class)->execute($profile, null, [ConnProfileSections::AttachedThreads => [...$section, 'threads' => $kept]]);
                }
            }
        });
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
