<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\E2E\Support\E2EConfig;
use App\E2E\Support\IncusHostPool;
use App\Services\E2E\IncusBaseImagePreparationOptions;
use App\Services\E2E\IncusBaseImagePreparer;
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('e2e:prepare-base-image
    {--force : Build the base image (otherwise prints the planned alias)}
    {--json : Output as JSON}')]
#[Description('Prepare the reusable Incus base image used by ephemeral E2E topologies')]
class E2EPrepareBaseImageCommand extends Command
{
    protected $hidden = true;

    /**
     * @var (Closure(): IncusBaseImagePreparer)|null
     */
    private ?Closure $preparerFactory = null;

    /**
     * @param  Closure(): IncusBaseImagePreparer  $factory
     */
    public function setPreparerFactory(Closure $factory): void
    {
        $this->preparerFactory = $factory;
    }

    public function handle(): int
    {
        $config = E2EConfig::fromEnvironment();

        $planned = [
            'role' => 'base',
            'alias' => $config->baseImage,
            'source' => $config->sourceImage,
        ];

        if (! (bool) $this->option('force')) {
            $result = [
                'provider' => 'incus',
                'dry_run' => true,
                'image' => $planned,
            ];

            if ((bool) $this->option('json')) {
                $this->line(json_encode(['success' => ['data' => $result]], JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }

            $this->line('Dry run. Pass --force to build the Incus base image.');
            $this->line("planned: {$planned['role']} -> {$planned['alias']} (source: {$planned['source']})");

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

        $options = new IncusBaseImagePreparationOptions(
            force: true,
            sourceImage: $config->sourceImage,
            baseImageAlias: $config->baseImage,
            bootstrapUser: $config->bootstrapUser,
            cpus: (int) $config->cpus,
            memory: $config->memory,
            timeoutSeconds: $config->timeoutSeconds,
            depsScriptPath: base_path('bin/_e2e-deps.sh'),
        );

        try {
            $preparer = $this->preparerFactory !== null
                ? ($this->preparerFactory)()
                : new IncusBaseImagePreparer($host);

            $built = $preparer->build($options);
        } catch (RuntimeException $exception) {
            return $this->failCommand($exception->getMessage());
        }

        $result = [
            'provider' => 'incus',
            'dry_run' => false,
            'image' => $built,
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode(['success' => ['data' => $result]], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info('Built Incus base image.');
        $this->line("{$built['action']}: {$built['role']} -> {$built['alias']}");

        return self::SUCCESS;
    }

    private function failCommand(string $message): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'error' => [
                    'code' => 'incus_base_image_prepare_failed',
                    'message' => $message,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }
}
