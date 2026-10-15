<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Actions;

use App\Models\Node;
use Orbit\Extensions\Conn\Data\PatchConnProfileSettingsData;
use Orbit\Extensions\Conn\Models\ConnProfile;

/**
 * Changes the sections that the client sends, each with the version the client read. One section at
 * another version refuses the whole request, so a phone font change never conflicts with a Mac theme change
 * but two edits of the same section do.
 */
final readonly class PatchConnProfileSettingsAction
{
    public function __construct(private ApplyConnProfileSectionsAction $apply) {}

    public function execute(ConnProfile $profile, Node $caller, PatchConnProfileSettingsData $data): ConnProfile
    {
        return $this->apply->execute($profile, $caller, $data->values, sectionVersions: $data->versions);
    }
}
