<?php

declare(strict_types=1);

namespace App\Actions\Conn;

use App\Domain\Conn\ConnProfileSections;
use App\Domain\Shared\ResourceOperationException;
use App\Models\ConnProfile;
use App\Models\Node;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Writes sections of a profile's settings document in one transaction, all or none. The profile row is
 * locked while the versions are checked, so two devices that write from the same version cannot both win.
 * Each written section moves to its next version, and so does the document.
 */
final readonly class ApplyConnProfileSectionsAction
{
    /**
     * @param  array<string, mixed>  $writes  The new value of each section by name; null removes a section. The name `devices`
     *                                        stands for every device type: it writes the types it holds and removes the others.
     * @param  int|null  $version  The document version the client read. Null skips the check.
     * @param  array<string, int>  $sectionVersions  The version of each section the client read; a section at another version is a conflict.
     */
    public function execute(ConnProfile $profile, Node $caller, array $writes, ?int $version = null, array $sectionVersions = []): ConnProfile
    {
        DB::transaction(function () use ($profile, $caller, $writes, $version, $sectionVersions): void {
            $locked = ConnProfile::query()->lockForUpdate()->findOrFail($profile->id);
            $meta = $locked->section_meta ?? [];

            if ($version !== null && $locked->settings_version !== $version) {
                throw new ResourceOperationException(
                    'conn.settings_version_conflict',
                    "The profile settings changed since version {$version}. Read them again and retry.",
                    409,
                    details: ['current_version' => $locked->settings_version],
                );
            }

            $stale = array_keys(array_filter($sectionVersions, static fn (int $sent, string $section): bool => ($meta[$section]['version'] ?? 0) !== $sent, ARRAY_FILTER_USE_BOTH));

            if ($stale !== []) {
                $current = [];

                foreach (array_keys($sectionVersions) as $section) {
                    $current[$section] = $meta[$section]['version'] ?? 0;
                }

                throw new ResourceOperationException(
                    'conn.settings_version_conflict',
                    'These sections changed since the versions you sent: '.implode(', ', $stale).'. Read them again and retry.',
                    409,
                    details: ['current_version' => $locked->settings_version, 'sections' => $current],
                );
            }

            $settings = $locked->settings;
            $now = Carbon::now()->toIso8601String();

            foreach ($this->expandDevices($writes, $settings) as $section => $value) {
                $settings = ConnProfileSections::with($settings, $section, $value);
                $meta[$section] = [
                    'version' => ($meta[$section]['version'] ?? 0) + 1,
                    'updated_at' => $now,
                    'updated_by_node_id' => $caller->id,
                ];
            }

            ConnProfileSections::assertWithinLimits($settings);

            ConnProfile::query()->whereKey($locked->id)->update([
                'settings' => json_encode($settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'section_meta' => json_encode($meta, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'settings_version' => $locked->settings_version + 1,
                'updated_at' => Carbon::now(),
            ]);
        });

        return $profile->refresh();
    }

    /**
     * @param  array<string, mixed>  $writes
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function expandDevices(array $writes, array $settings): array
    {
        if (! array_key_exists('devices', $writes)) {
            return $writes;
        }

        $devices = is_array($writes['devices']) ? $writes['devices'] : [];
        unset($writes['devices']);
        $current = is_array($settings['devices'] ?? null) ? $settings['devices'] : [];

        foreach (array_keys($current) as $type) {
            $writes[ConnProfileSections::device((string) $type)] = $devices[$type] ?? null;
        }

        foreach ($devices as $type => $section) {
            $writes[ConnProfileSections::device((string) $type)] = $section;
        }

        return $writes;
    }
}
