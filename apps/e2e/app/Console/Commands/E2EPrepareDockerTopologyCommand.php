<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\E2E\Support\DockerTopologyBuilder;
use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EPreparedTopology;
use App\E2E\Support\E2ETopologyArtifactNamespace;
use App\E2E\Support\E2ETopologyKind;
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;

#[Signature('e2e:prepare-docker-topology
    {kind : Topology kind to prepare (operator|operator_gateway|operator_gateway_app-dev|operator_gateway_app-dev_app-prod|operator_gateway_app-dev_app-prod_ingress|operator_gateway_agent|operator_gateway_app-dev_app-prod_agent|operator_gateway_app-prod_ingress|operator_gateway_app-dev_websocket|operator_gateway_app-dev_app-prod_websocket|operator_gateway_app-dev_app-prod_agent_websocket)}
    {--force : Build the Docker prepared per-role images}
    {--roles= : Comma-separated prepared artifact roles to build (operator,gateway,app-dev,app-prod,ingress,agent,websocket)}
    {--all-roles : Explicitly build every prepared artifact role when a custom namespace is set}
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
            return $this->failValidation(
                "Invalid topology kind [{$kindValue}]. Supported: ".E2EPreparedTopology::supportedKindsForHelp().'.',
            );
        }

        if (! E2EPreparedTopology::supportsKind($kind)) {
            return $this->failValidation(E2EPreparedTopology::unsupportedKindMessage($kind));
        }

        try {
            $artifactRoles = $this->selectedArtifactRoles();
        } catch (InvalidArgumentException $exception) {
            return $this->failValidation($exception->getMessage());
        }

        $images = $this->imagesFor($kind, $artifactRoles);

        if ($images === []) {
            return $this->failValidation("Selected roles are not prepared by Docker topology source [{$kind->value}].");
        }

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

            foreach ($this->buildKindsFor($kind, $artifactRoles) as $buildKind) {
                $entries = $artifactRoles === null
                    ? $builder->build($buildKind)
                    : $builder->build($buildKind, 'dns-alias', $artifactRoles);

                array_push($manifest, ...$entries);
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
     * @param  list<string>|null  $artifactRoles
     * @return list<array{role: string, image: string}>
     */
    private function imagesFor(E2ETopologyKind $kind, ?array $artifactRoles = null): array
    {
        $images = [];

        foreach ($this->buildKindsFor($kind, $artifactRoles) as $buildKind) {
            $roles = $this->ownedRolesFor($buildKind, $artifactRoles);

            array_push($images, ...array_map(
                fn (string $role): array => [
                    'role' => $role,
                    'image' => DockerTopologyBuilder::imageNameFor($buildKind, $role),
                ],
                $roles,
            ));
        }

        return $images;
    }

    /**
     * @param  list<string>|null  $artifactRoles
     * @return list<E2ETopologyKind>
     */
    private function buildKindsFor(E2ETopologyKind $kind, ?array $artifactRoles = null): array
    {
        $buildKinds = E2EPreparedTopology::dockerArtifactSourceKindsFor($kind);

        if ($artifactRoles === null) {
            return $buildKinds;
        }

        return array_values(array_filter(
            $buildKinds,
            fn (E2ETopologyKind $buildKind): bool => $this->ownedRolesFor($buildKind, $artifactRoles) !== [],
        ));
    }

    /**
     * @param  list<string>|null  $artifactRoles
     * @return list<string>
     */
    private function ownedRolesFor(E2ETopologyKind $kind, ?array $artifactRoles): array
    {
        $roles = array_values(array_filter(
            DockerTopologyBuilder::rolesFor($kind),
            fn (string $role): bool => DockerTopologyBuilder::ownsImage($kind, $role),
        ));

        if ($artifactRoles === null) {
            return $roles;
        }

        return array_values(array_filter(
            $roles,
            fn (string $role): bool => in_array(
                E2EPreparedTopology::artifactRoleForDockerRole($role),
                $artifactRoles,
                true,
            ),
        ));
    }

    /**
     * @return list<string>|null
     */
    private function selectedArtifactRoles(): ?array
    {
        $roles = $this->stringOption('roles');
        $allRoles = (bool) $this->option('all-roles');

        if ($roles !== null && $allRoles) {
            throw new InvalidArgumentException('Choose either --roles or --all-roles, not both.');
        }

        if (E2ETopologyArtifactNamespace::hasCustomArtifactSet() && $roles === null && ! $allRoles) {
            throw new InvalidArgumentException(
                'Set --roles or --all-roles when ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE is set.',
            );
        }

        if ($roles === null) {
            return null;
        }

        return E2EPreparedTopology::parseArtifactRoles($roles);
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
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
