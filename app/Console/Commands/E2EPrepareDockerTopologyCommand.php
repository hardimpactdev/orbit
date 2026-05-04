<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;
use Tests\E2E\Support\DockerTopologyBuilder;
use Tests\E2E\Support\E2EConfig;
use Tests\E2E\Support\E2ETopologyKind;

#[Signature('e2e:prepare-docker-topology
    {kind=control-gateway-dev-prod : Topology kind to prepare (control|control-gateway|control-gateway-dev|control-gateway-dev-prod)}
    {--force : Build the Docker prepared per-role images}
    {--json : Output as JSON}')]
#[Description('Prepare per-role Docker images used by the Docker prepared topology provider')]
class E2EPrepareDockerTopologyCommand extends Command
{
    protected $hidden = true;

    /**
     * @var (Closure(): object)|null
     */
    private ?Closure $builderFactory = null;

    /**
     * @param  Closure(): object  $factory
     */
    public function setBuilderFactory(Closure $factory): void
    {
        $this->builderFactory = $factory;
    }

    public function handle(): int
    {
        $kindValue = (string) $this->argument('kind');
        $kind = E2ETopologyKind::tryFrom($kindValue);

        if ($kind === null) {
            return $this->failValidation("Invalid topology kind [{$kindValue}]. Supported: control, control-gateway, control-gateway-dev, control-gateway-dev-prod.");
        }

        $images = $this->imagesFor($kind);

        if (! (bool) $this->option('force')) {
            $result = [
                'provider' => 'docker',
                'dry_run' => true,
                'kind' => $kind->value,
                'images' => $images,
            ];

            if ((bool) $this->option('json')) {
                $this->line(json_encode(['success' => ['data' => $result]], JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }

            $this->line('Dry run. Pass --force to build the Docker prepared images.');

            foreach ($images as $image) {
                $this->line("planned: {$image['image']}");
            }

            return self::SUCCESS;
        }

        try {
            $builder = $this->builderFactory !== null
                ? ($this->builderFactory)()
                : new DockerTopologyBuilder(E2EConfig::fromEnvironment());

            $manifest = $builder->build($kind);
        } catch (RuntimeException $exception) {
            return $this->failCommand($exception->getMessage());
        }

        $result = [
            'provider' => 'docker',
            'dry_run' => false,
            'kind' => $kind->value,
            'images' => $manifest,
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode(['success' => ['data' => $result]], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info("Built Docker topology [{$kind->value}].");

        foreach ($manifest as $entry) {
            $this->line("created: {$entry['image']}");
        }

        return self::SUCCESS;
    }

    /**
     * @return list<array{role: string, image: string}>
     */
    private function imagesFor(E2ETopologyKind $kind): array
    {
        return array_map(
            fn (string $role): array => [
                'role' => $role,
                'image' => DockerTopologyBuilder::imageNameFor($kind, $role),
            ],
            DockerTopologyBuilder::rolesFor($kind),
        );
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
                    'code' => 'topology_prepare_failed',
                    'message' => $message,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }
}
