<?php

declare(strict_types=1);

namespace Orbit\Extensions\Documents\Console\Commands;

use Orbit\Extensions\Documents\Actions\DocumentCleanupControlAction;

final class ShowDocumentCleanupStatus extends DocumentCleanupCommand
{
    #[\Override]
    protected $signature = 'project-documents:cleanup:status';

    #[\Override]
    protected $description = 'Show local document-body cleanup authorization and pending counts.';

    public function handle(DocumentCleanupControlAction $action): int
    {
        return $this->control($action, false);
    }
}
