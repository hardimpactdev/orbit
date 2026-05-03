<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Tests\E2E\Support\E2EConfig;

#[Signature('e2e:prepare-incus-images
    {--role=all : Image role (all|blank|control|gateway|devapp|prodapp)}
    {--force : Build the images (currently unimplemented stub)}
    {--json : Output as JSON}')]
#[Description('Prepare Incus images used by ephemeral E2E tests')]
class E2EPrepareIncusImagesCommand extends Command
{
    protected $hidden = true;

    public function handle(): int
    {
        $role = (string) $this->option('role');
        $validRoles = ['all', 'blank', 'control', 'gateway', 'devapp', 'prodapp'];

        if (! in_array($role, $validRoles, true)) {
            return $this->failValidation('--role must be one of: '.implode(', ', $validRoles).'.');
        }

        if ((bool) $this->option('force')) {
            return $this->failCommand('Building Incus images via artisan is not yet implemented.');
        }

        $config = E2EConfig::fromEnvironment();
        $roles = $this->resolveRoles($role, $config);

        $result = [
            'provider' => 'incus',
            'dry_run' => true,
            'roles' => $roles,
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode(['success' => ['data' => $result]], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->renderHuman($result);

        return self::SUCCESS;
    }

    /**
     * @return list<array{role: string, image_alias: string, source: string}>
     */
    private function resolveRoles(string $role, E2EConfig $config): array
    {
        $allRoles = [
            ['role' => 'blank', 'image_alias' => $config->blankImage, 'source' => $config->sourceImage],
            ['role' => 'control', 'image_alias' => $config->controlImage, 'source' => $config->blankImage],
            ['role' => 'gateway', 'image_alias' => $config->gatewayImage, 'source' => $config->blankImage],
            ['role' => 'devapp', 'image_alias' => 'orbit-ready-devapp', 'source' => $config->blankImage],
            ['role' => 'prodapp', 'image_alias' => 'orbit-ready-prodapp', 'source' => $config->blankImage],
        ];

        if ($role === 'all') {
            return $allRoles;
        }

        foreach ($allRoles as $roleData) {
            if ($roleData['role'] === $role) {
                return [$roleData];
            }
        }

        return [];
    }

    /**
     * @param  array{
     *     provider: string,
     *     dry_run: bool,
     *     roles: list<array{role: string, image_alias: string, source: string}>
     * }  $result
     */
    private function renderHuman(array $result): void
    {
        $this->line('Dry run. Pass --force to build Incus images.');

        foreach ($result['roles'] as $role) {
            $this->line("planned: {$role['role']} -> {$role['image_alias']} (source: {$role['source']})");
        }
    }

    private function failValidation(string $message): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => $message,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }

    private function failCommand(string $message): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'error' => [
                    'code' => 'incus_e2e_image_prepare_failed',
                    'message' => $message,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }
}
