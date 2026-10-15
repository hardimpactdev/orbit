<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Actions;

use App\Models\Node;
use Orbit\Extensions\Conn\Data\ReplaceConnProfileSettingsData;
use Orbit\Extensions\Conn\Models\ConnProfile;

/**
 * Replaces the sections of a profile's settings document that the client sends, when the client read the
 * current document version. Two devices that edit from the same version cannot overwrite each other: the
 * second one gets a conflict and rereads. Sections the client leaves out stay as they are.
 */
final readonly class ReplaceConnProfileSettingsAction
{
    public function __construct(private ApplyConnProfileSectionsAction $apply) {}

    public function execute(ConnProfile $profile, Node $caller, ReplaceConnProfileSettingsData $data): ConnProfile
    {
        return $this->apply->execute($profile, $caller, $data->settings, version: $data->version);
    }
}
