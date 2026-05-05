<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\E2E\Support\E2EConfig;
use App\E2E\Support\IncusHostPool;
use App\Services\E2E\IncusE2EImagePreparationOptions;
use App\Services\E2E\IncusE2EImagePreparer;
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('e2e:prepare-incus-images
    {--role=blank : Image role to build (currently only "blank")}
    {--force : Build the image (otherwise prints the planned alias)}
    {--json : Output as JSON}')]
#[Description('Prepare the reusable Incus blank image used by the provisioning E2E lane')]
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
        $validRoles = ['blank'];

        if (! in_array($role, $validRoles, true)) {
            return $this->failValidation('--role must be one of: '.implode(', ', $validRoles).'.');
        }

        $config = E2EConfig::fromEnvironment();
        $roles = [
            ['role' => 'blank', 'image_alias' => $config->blankImage, 'source' => $config->sourceImage],
        ];

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

        $options = new IncusE2EImagePreparationOptions(
            roles: array_column($roles, 'role'),
            force: true,
            sourceImage: $config->sourceImage,
            blankImageAlias: $config->blankImage,
            bootstrapUser: $config->bootstrapUser,
            controlUser: $config->controlUser,
            installScriptPath: base_path('bin/install-orbit'),
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
