<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\E2E\Support\DockerTopologyBuilder;
use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EPreparedTopology;
use App\E2E\Support\E2ETopologyKind;
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('e2e:prepare-docker-topology
    {kind=operator_gateway_app-dev_app-prod_agent : Topology kind to prepare (operator|operator_gateway|operator_gateway_app-dev|operator_gateway_app-dev_app-prod|operator_gateway_agent|operator_gateway_app-dev_app-prod_agent|operator_gateway_app-prod_ingress)}
    {--force : Build the Docker prepared per-role images}
    {--json : Output as JSON}')]
#[Description('Prepare per-role Docker images used by the Docker prepared topology provider')]
class E2EPrepareDockerTopologyCommand extends Command
{
    #[\Override]
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
        $kind = E2ETopologyKind::tryFromInput($kindValue);

        if ($kind === null) {
            return $this->failValidation("Invalid topology kind [{$kindValue}]. Supported: ".E2EPreparedTopology::supportedKindsForHelp().'.');
        }

        if (! E2EPreparedTopology::supportsKind($kind)) {
            return $this->failValidation(E2EPreparedTopology::unsupportedKindMessage($kind));
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

            $manifest = [];

            foreach ($this->buildKindsFor($kind) as $buildKind) {
                array_push($manifest, ...$builder->build($buildKind));
            }
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
        $images = [];

        foreach ($this->buildKindsFor($kind) as $buildKind) {
            array_push($images, ...array_map(
                fn (string $role): array => [
                    'role' => $role,
                    'image' => DockerTopologyBuilder::imageNameFor($buildKind, $role),
                ],
                DockerTopologyBuilder::rolesFor($buildKind),
            ));
        }

        return $images;
    }

    /**
     * @return list<E2ETopologyKind>
     */
    private function buildKindsFor(E2ETopologyKind $kind): array
    {
        return E2EPreparedTopology::dockerArtifactSourceKindsFor($kind);
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
