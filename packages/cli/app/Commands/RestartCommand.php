<?php

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use LaravelZero\Framework\Commands\Command;

class RestartCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'restart {--json : Output as JSON}';

    protected $description = 'Restart all Orbit services';

    public function handle(): int
    {
        if ($this->wantsJson()) {
            // Run stop and start with JSON output, combine results
            $stopCode = $this->call('stop', ['--json' => true]);
            $startCode = $this->call('start', ['--json' => true]);

            // The individual commands already output JSON, so just return the exit code
            return max($stopCode, $startCode);
        }

        $this->call('stop');
        $this->call('start');

        return self::SUCCESS;
    }
}
