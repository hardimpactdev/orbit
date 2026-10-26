<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\Instances\Deployment\DeploymentRelease;
use App\Domain\Instances\ProductionRepositoryBinding;
use App\Domain\Instances\ProductionRepositoryRecord;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Models\Instance;

final readonly class RemoteProductionRepositoryBinding implements ProductionRepositoryBinding
{
    public function __construct(
        private AgentCommandRunner $agents,
    ) {}

    public function inspect(Instance $instance): ProductionRepositoryRecord
    {
        [$user, $home] = $this->identity($instance);
        $result = $this->run($instance, 'instance.inspect-repository-binding', ['user' => $user, 'home' => $home, 'instance' => $instance->id]);
        $markers = is_array($result['markers'] ?? null) ? $result['markers'] : throw $this->failure();
        $listed = is_array($result['releases'] ?? null) ? $result['releases'] : throw $this->failure();
        $layout = isset($markers['release-layout']) ? $this->markerRepository($markers['release-layout'], [$user, $home, 'initial']) : null;
        $initial = isset($markers['initial-clone']) ? $this->markerRepository($markers['initial-clone'], [$user, $home]) : null;
        $releases = [];

        foreach ($listed as $name => $origin) {
            if (! is_string($name) || ! DeploymentRelease::isValidName($name)) {
                throw $this->failure();
            }
            // A release without a readable origin is partial. Deployment skips it, so a rebind does too.
            if ($origin === null) {
                continue;
            }
            if (! is_string($origin) || $origin === '') {
                throw $this->failure();
            }
            $releases[$name] = $origin;
        }

        ksort($releases, SORT_STRING);

        return new ProductionRepositoryRecord($layout, $initial, $releases);
    }

    public function rebind(Instance $instance, ProductionRepositoryRecord $expected, ProductionRepositoryRecord $target): void
    {
        [$user, $home] = $this->identity($instance);
        $changes = [];

        foreach ($expected->releases as $name => $url) {
            $changes[] = ['kind' => 'release', 'name' => $name, 'expected' => $url, 'target' => $target->releases[$name] ?? throw $this->failure()];
        }

        if ($expected->initial !== null) {
            $changes[] = ['kind' => 'initial-clone', 'expected' => $expected->initial, 'target' => $target->initial ?? throw $this->failure()];
        }

        // The release layout marker is the binding that deployment checks first, so it changes last.
        if ($expected->layout !== null) {
            $changes[] = ['kind' => 'release-layout', 'expected' => $expected->layout, 'target' => $target->layout ?? throw $this->failure()];
        }

        if ($changes === []) {
            return;
        }

        $this->run($instance, 'instance.rebind-repository', ['user' => $user, 'home' => $home, 'instance' => $instance->id, 'changes' => $changes]);
    }

    /** @return array{string, string} */
    private function identity(Instance $instance): array
    {
        $user = $instance->production_user;
        $home = $instance->production_home;

        if (! $instance->usesProductionReleaseLayout() || ! is_string($user) || ! is_string($home)) {
            throw $this->failure();
        }

        return [$user, $home];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function run(Instance $instance, string $command, array $input): array
    {
        try {
            return $this->agents->run($instance->loadMissing('node')->node, $command, $input);
        } catch (AgentCommandRefused|AgentRunnerFailure $exception) {
            throw $this->failure($exception);
        }
    }

    /** @param list<string> $fields */
    private function markerRepository(mixed $encoded, array $fields): string
    {
        $value = is_string($encoded) ? base64_decode($encoded, true) : false;
        $parts = is_string($value) ? explode("\0", $value) : [];

        if (
            count($parts) !== count($fields) + 2
            || $parts[0] === ''
            || array_pop($parts) !== ''
            || array_slice($parts, 1) !== $fields
        ) {
            throw $this->failure();
        }

        return $parts[0];
    }

    private function failure(?\Throwable $previous = null): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'project.production_rebind_failed',
            message: 'Orbit could not read or re-bind the repository of a production Instance.',
            status: 409,
            previous: $previous,
        );
    }
}
