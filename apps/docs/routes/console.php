<?php

declare(strict_types=1);

use App\Librarian\CommandCatalogBuilder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command(
    'orbit:command-catalog {--check : Fail when the committed command catalog is stale} {--command= : Print one command entry as JSON without writing the catalog}',
    function (CommandCatalogBuilder $builder): int {
        $commandName = $this->option('command');

        if (is_string($commandName) && $commandName !== '') {
            $entry = $builder->command($commandName);

            if ($entry === null) {
                $this->error("Command `{$commandName}` was not found in the Orbit command catalog.");

                return self::FAILURE;
            }

            $this->getOutput()->write($builder->toJson($entry));

            return self::SUCCESS;
        }

        if ($this->option('check')) {
            if ($builder->isFresh()) {
                $this->info('Orbit command catalog is up to date.');

                return self::SUCCESS;
            }

            $this->error('Orbit command catalog is stale. Run `php artisan orbit:command-catalog` from apps/docs.');

            return self::FAILURE;
        }

        $this->info("Wrote {$builder->write()}.");

        return self::SUCCESS;
    },
)->purpose('Generate the agent-readable Orbit command contract catalog');
