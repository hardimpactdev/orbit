<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\E2E\Support\E2EConfig;
use App\E2E\Support\IncusHost;
use App\E2E\Support\IncusHostPool;
use App\Services\E2E\IncusBaseImagePreparationOptions;
use App\Services\E2E\IncusBaseImagePreparer;
use App\Services\E2E\IncusImageDistributor;
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
     * @var (Closure(IncusHost): IncusBaseImagePreparer)|null
     */
    private ?Closure $preparerFactory = null;

    /**
     * @var (Closure(IncusHost): IncusImageDistributor)|null
     */
    private ?Closure $imageDistributorFactory = null;

    /**
     * @param  Closure(IncusHost): IncusBaseImagePreparer  $factory
     */
    public function setPreparerFactory(Closure $factory): void
    {
        $this->preparerFactory = $factory;
    }

    /**
     * @param  Closure(IncusHost): IncusImageDistributor  $factory
     */
    public function setImageDistributorFactory(Closure $factory): void
    {
        $this->imageDistributorFactory = $factory;
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
        $hosts = $hostPool->hosts();

        if ($hosts === []) {
            return $this->failCommand('No Incus hosts configured. Set ORBIT_E2E_INCUS_HOSTS or ORBIT_E2E_HOST.');
        }

        $builtImages = [];

        try {
            $buildHost = new IncusHost($config->forHost($config->incusImageBuildHost));
            $buildHostConfig = $buildHost->config;
            $options = new IncusBaseImagePreparationOptions(
                force: true,
                sourceImage: $buildHostConfig->sourceImage,
                baseImageAlias: $buildHostConfig->baseImage,
                bootstrapUser: $buildHostConfig->bootstrapUser,
                cpus: (int) $buildHostConfig->cpus,
                memory: $buildHostConfig->memory,
                timeoutSeconds: $buildHostConfig->timeoutSeconds,
                depsScriptPath: base_path('bin/_e2e-deps.sh'),
            );

            $preparer = $this->preparerFactory !== null
                ? ($this->preparerFactory)($buildHost)
                : new IncusBaseImagePreparer($buildHost);

            $built = $preparer->build($options);
            $builtImages[] = ['host' => $buildHostConfig->host, ...$built];

            $distributor = $this->imageDistributorFactory !== null
                ? ($this->imageDistributorFactory)($buildHost)
                : new IncusImageDistributor($buildHost);

            array_push($builtImages, ...$distributor->distribute($buildHostConfig->baseImage, $hosts));
        } catch (RuntimeException $exception) {
            return $this->failCommand($exception->getMessage());
        }

        $result = [
            'provider' => 'incus',
            'dry_run' => false,
            'image' => $builtImages[0],
            'images' => $builtImages,
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode(['success' => ['data' => $result]], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info('Built Incus base image.');

        foreach ($builtImages as $built) {
            $this->line("{$built['host']}: {$built['action']}: {$built['role']} -> {$built['alias']}");
        }

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
