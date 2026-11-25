<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Instances\PhpSymbolIndexRefresh;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use Illuminate\Console\Command;

/**
 * Admits the PHP symbol index of running Laravel defaults and their seeds, with no checkout or clean
 * (docs/reference/development-containers.md#prepare-the-index-of-a-running-default). Each refused Instance is named with its code.
 */
final class InstancePhpIndexCommand extends Command
{
    #[\Override]
    protected $signature = 'instances:php-index {instance* : Numeric IDs of Laravel default Instances}';

    #[\Override]
    protected $description = 'Build the PHP symbol index of running Laravel defaults and retake their seeds, leaving their working trees untouched.';

    public function handle(PhpSymbolIndexRefresh $refresh): int
    {
        $failed = false;
        foreach ((array) $this->argument('instance') as $id) {
            $instance = ctype_digit($id) ? Instance::query()->find((int) $id) : null;
            if (! $instance instanceof Instance) {
                $this->line(json_encode(['instance' => $id, 'skipped' => 'instance.not_found'], JSON_THROW_ON_ERROR));
                $failed = true;

                continue;
            }
            try {
                $operation = $refresh->start($instance, null, null);
                $this->line(json_encode(['instance' => $instance->id, 'operation' => $operation->id], JSON_THROW_ON_ERROR));
            } catch (ResourceOperationException $refusal) {
                $this->line(json_encode(['instance' => $instance->id, 'skipped' => $refusal->errorCode, 'reason' => $refusal->getMessage()], JSON_THROW_ON_ERROR));
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
