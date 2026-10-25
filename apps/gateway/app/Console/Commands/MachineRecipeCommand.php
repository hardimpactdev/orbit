<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\MachineSoftware\RecipeValidator;
use Illuminate\Console\Command;
use Throwable;

final class MachineRecipeCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:machine-recipe
        {--path= : Candidate recipe JSON; defaults to the repository recipe}
        {--role= : Show gateway, app-dev, app-prod, operator, or base}
        {--architecture= : Require the displayed profile(s) to support amd64 or arm64}
        {--validate-ubuntu : Verify all Ubuntu pins against signed snapshot indexes without fetching artifacts}
        {--validate : Verify signed snapshot indexes and all artifact bytes}
        {--json : Print the complete recipe, identity, validation state, and selected effective profiles as JSON}
        {--artifact-directory= : Local internal artifacts; defaults to ORBIT_HOME/sandbox-artifacts}';

    #[\Override]
    protected $description = 'Show machine software intent and its identity; optionally verify availability and integrity without installing anything';

    public function handle(RecipeLoader $loader, RecipeValidator $validator): int
    {
        try {
            $path = $this->option('path');
            // Ubuntu pins do not depend on the release's agent, so that check needs no delivery manifest.
            $recipe = $this->option('validate-ubuntu') && ! $this->option('validate')
                ? $loader->source(is_string($path) ? $path : null)
                : $loader->load(is_string($path) ? $path : null);
            $role = $this->option('role');
            $architecture = $this->option('architecture');
            $roles = is_string($role) ? [$role] : array_keys($recipe->profiles);
            foreach ($roles as $name) {
                $recipe->profile($name, is_string($architecture) ? $architecture : null);
            }
            if ($this->option('validate')) {
                $directory = $this->option('artifact-directory');
                $validator->validate($recipe, is_string($directory) ? $directory : config()->string('orbit.home').'/sandbox-artifacts');
            } elseif ($this->option('validate-ubuntu')) {
                $validator->validateUbuntu($recipe);
            }
            $validation = $this->option('validate') ? 'structure_availability_integrity'
                : ($this->option('validate-ubuntu') ? 'structure_ubuntu_snapshot' : 'structure_only');
            if ($this->option('json')) {
                $profiles = [];
                foreach ($roles as $name) {
                    $profiles[$name] = ['tools' => $recipe->profile($name), 'architectures' => $recipe->supportedArchitectures($name)];
                }
                $this->line(json_encode([
                    'identity' => $recipe->identity,
                    'validation' => $validation,
                    'recipe' => json_decode($recipe->json, flags: JSON_THROW_ON_ERROR),
                    'profiles' => $profiles,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }
            $this->line('Identity: '.$recipe->identity);
            $this->line('Ubuntu: 26.04; snapshot: '.$recipe->snapshot);
            $this->line($this->option('validate')
                ? 'Validation: structure, availability, and integrity verified (whole recipe).'
                : ($this->option('validate-ubuntu')
                    ? 'Validation: structure and all Ubuntu snapshot pins verified; artifacts NOT checked.'
                    : 'Validation: structure only; availability and integrity NOT checked.'));
            foreach ($roles as $name) {
                $this->newLine();
                $this->line($name.' (architectures: '.implode(', ', $recipe->supportedArchitectures($name)).')');
                $this->table(['Tool', 'Exact version', 'Source'], array_map(fn (string $tool): array => [
                    $tool, $recipe->tools[$tool]->version, $recipe->tools[$tool]->source,
                ], $recipe->profile($name)));
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            if ($this->option('json')) {
                $this->line(json_encode(['error' => $exception->getMessage()], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            } else {
                $this->error($exception->getMessage());
            }

            return self::FAILURE;
        }
    }
}
