<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\E2E\Support\E2EPestSupportTree;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('e2e:support-tree:sync')]
#[Description('Regenerate the Feature/Commands runner Pest support tree from tests/E2E/Support')]
class E2ESupportTreeSyncCommand extends Command
{
    #[\Override]
    protected $hidden = true;

    public function handle(): int
    {
        $destination = E2EPestSupportTree::generatedRunnerDirectory();
        E2EPestSupportTree::copyTo($destination);

        $files = glob($destination.'/*.php') ?: [];
        sort($files);

        foreach ($files as $file) {
            $this->line("Wrote {$file}");
        }

        return self::SUCCESS;
    }
}
