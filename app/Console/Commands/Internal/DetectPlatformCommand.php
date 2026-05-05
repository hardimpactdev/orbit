<?php

declare(strict_types=1);

namespace App\Console\Commands\Internal;

use App\Models\Node;
use App\Services\Platform\PlatformDetector;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('orbit:internal:detect-platform
    {--update-local-node : Persist the detected platform on the local node row}')]
#[Description('Detect the current host platform for internal provisioning flows')]
class DetectPlatformCommand extends Command
{
    protected $hidden = true;

    public function handle(PlatformDetector $platformDetector): int
    {
        try {
            $platform = $platformDetector->detectLocal();
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('update-local-node') === true) {
            Node::query()
                ->where('is_local', true)
                ->where('status', 'active')
                ->update(['platform' => $platform]);
        }

        $this->line($platform);

        return self::SUCCESS;
    }
}
