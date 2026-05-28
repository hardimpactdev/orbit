<?php

declare(strict_types=1);

namespace App\Commands\Operation;

use App\Commands\LocalOnlyCommand;
use App\Services\Updates\UpdateOutputRenderer;

final class UpdateCommand extends LocalOnlyCommand
{
    #[\Override]
    protected $signature = 'update {--json : Output as JSON}';

    #[\Override]
    protected $description = 'Update this Orbit checkout';

    public function handle(UpdateOutputRenderer $renderer): int
    {
        return $renderer->render($this->output, $this->wantsJson());
    }
}
