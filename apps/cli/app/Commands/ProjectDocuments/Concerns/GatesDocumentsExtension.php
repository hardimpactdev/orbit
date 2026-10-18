<?php

declare(strict_types=1);

namespace App\Commands\ProjectDocuments\Concerns;

/** The `project:document*` commands belong to the documents extension and follow its Gateway switch. */
trait GatesDocumentsExtension
{
    public function extensionSlug(): ?string
    {
        return 'documents';
    }
}
