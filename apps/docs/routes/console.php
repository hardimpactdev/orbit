<?php

declare(strict_types=1);

use App\Librarian\CommandCatalogBuilder;
use App\Librarian\MonorepoUnitMapBuilder;
use App\Librarian\TransitionalSshInventoryBuilder;
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

Artisan::command(
    'orbit:monorepo-unit-map {--check : Fail when the committed monorepo unit map is stale} {--unit= : Print one unit entry as JSON without writing the map}',
    function (MonorepoUnitMapBuilder $builder): int {
        $unitId = $this->option('unit');

        if (is_string($unitId) && $unitId !== '') {
            $map = $builder->build();
            $entry = $map['units'][$unitId] ?? null;

            if (! is_array($entry)) {
                $this->error("Unit `{$unitId}` was not found in the Orbit monorepo unit map.");

                return self::FAILURE;
            }

            $this->getOutput()->write($builder->toJson($entry));

            return self::SUCCESS;
        }

        if ($this->option('check')) {
            if ($builder->isFresh()) {
                $this->info('Orbit monorepo unit map is up to date.');

                return self::SUCCESS;
            }

            $this->error('Orbit monorepo unit map is stale. Run `php artisan orbit:monorepo-unit-map` from apps/docs.');

            return self::FAILURE;
        }

        try {
            $path = $builder->write();
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Wrote {$path}.");

        return self::SUCCESS;
    },
)->purpose('Generate the agent-readable Orbit monorepo unit map');

Artisan::command(
    'orbit:transitional-ssh-inventory {--check : Fail when the committed SSH inventory is stale or has unmarked consumers}',
    function (TransitionalSshInventoryBuilder $builder): int {
        $inventory = $builder->build();

        if ($inventory['unmarked_consumers'] !== []) {
            $this->error('Unmarked SSH consumers: '.implode(', ', $inventory['unmarked_consumers']));

            return self::FAILURE;
        }

        if ($this->option('check')) {
            if ($builder->isFresh()) {
                $this->info('Orbit transitional SSH inventory is up to date.');

                return self::SUCCESS;
            }

            $this->error(
                'Orbit transitional SSH inventory is stale. Run `php artisan orbit:transitional-ssh-inventory` from apps/docs.',
            );

            return self::FAILURE;
        }

        try {
            $path = $builder->write();
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Wrote {$path}.");

        return self::SUCCESS;
    },
)->purpose('Generate the machine-readable Orbit SSH migration inventory');
