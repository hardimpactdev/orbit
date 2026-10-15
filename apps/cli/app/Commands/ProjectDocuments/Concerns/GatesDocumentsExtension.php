<?php

declare(strict_types=1);

namespace App\Commands\ProjectDocuments\Concerns;

use App\Services\Extensions\GatewayExtensionState;
use App\Support\ExtensionCommandVisibility;

/** The `project:document*` commands belong to the documents extension and follow its Gateway switch. */
trait GatesDocumentsExtension
{
    public function isHidden(): bool
    {
        return ExtensionCommandVisibility::listing()
            && app(GatewayExtensionState::class)->isEnabled('documents') !== true;
    }

    public function extensionSlug(): ?string
    {
        return 'documents';
    }
}
