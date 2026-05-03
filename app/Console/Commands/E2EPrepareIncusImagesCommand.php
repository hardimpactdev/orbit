<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\E2E\IncusE2EImagePreparationOptions;
use App\Services\E2E\IncusE2EImagePreparer;
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;
use Tests\E2E\Support\E2EConfig;
use Tests\E2E\Support\IncusHostPool;

#[Signature('e2e:prepare-incus-images
    {--role=all : Image role (all|blank|control|gateway|devapp|prodapp)}
    {--force : Build the images (currently unimplemented stub)}
    {--json : Output as JSON}')]
#[Description('Prepare Incus images used by ephemeral E2E tests')]
class E2EPrepareIncusImagesCommand extends Command
{
    protected $hidden = true;

    /**
     * @var (Closure(): IncusE2EImagePreparer)|null
     */
    private ?Closure $preparerFactory = null;

    /**
     * @param  Closure(): IncusE2EImagePreparer  $factory
     */
    public function setPreparerFactory(Closure $factory): void
    {
        $this->preparerFactory = $factory;
    }

    public function handle(): int
    {
        $role = (string) $this->option('role');
        $validRoles = ['all', 'blank', 'control', 'gateway', 'devapp', 'prodapp'];

        if (! in_array($role, $validRoles, true)) {
            return $this->failValidation('--role must be one of: '.implode(', ', $validRoles).'.');
        }

        $config = E2EConfig::fromEnvironment();
        $roles = $this->resolveRoles($role, $config);

        if (! (bool) $this->option('force')) {
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

        if (! in_array('incus', $config->providerNames, true)) {
            return $this->failCommand('No Incus provider configured. Set ORBIT_E2E_PROVIDER or ORBIT_E2E_PROVIDERS to include incus.');
        }

        $hostPool = IncusHostPool::fromEnvironment($config);
        $host = $hostPool->first();

        if ($host === null) {
            return $this->failCommand('No Incus hosts configured. Set ORBIT_E2E_INCUS_HOSTS or ORBIT_E2E_HOST.');
        }

        $roleNames = array_column($roles, 'role');

        $options = new IncusE2EImagePreparationOptions(
            roles: $roleNames,
            force: true,
            sourceImage: $config->sourceImage,
            blankImageAlias: $config->blankImage,
            bootstrapUser: $config->bootstrapUser,
            serverType: "{$config->cpus}cpu/{$config->memory}",
            cpus: (int) $config->cpus,
            memory: $config->memory,
            timeoutSeconds: $config->timeoutSeconds,
        );

        try {
            $preparer = $this->preparerFactory !== null
                ? ($this->preparerFactory)()
                : new IncusE2EImagePreparer($host);

            $preparerResult = $preparer->prepare($options);
        } catch (RuntimeException $exception) {
            return $this->failCommand($exception->getMessage());
        }

        $result = [
            'provider' => 'incus',
            'dry_run' => false,
            'images' => $preparerResult->images,
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode(['success' => ['data' => $result]], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info('Built Incus images.');

        foreach ($preparerResult->images as $image) {
            $this->line("{$image['action']}: {$image['role']} -> {$image['alias']}");
        }

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
