<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Scripted adoption answers for the Gateway's two-phase workflow. The fixture starts as an in-place
 * checkout of a new commit; the first run creates the shared state, and later runs find it.
 */
final class GatewayAdoptionFixture
{
    public readonly FakeAgentCommandRunner $agents;

    public readonly string $sha;

    /** @var array<string, bool> the shared state the adoption created: repository, environment, and storage */
    private array $created = [];

    public function __construct(GatewayReleaseFixture $fixture)
    {
        $this->sha = $fixture->commit('In-place checkout');
        $fixture->inPlace();
        $layout = $fixture->layout;
        $kept = $layout->basePath().'/orbit.pre-adopt-20261007T120000Z';
        $this->agents = new FakeAgentCommandRunner()
            ->answer('gateway.check-release-adoption', ['sha' => $this->sha])
            ->answer('gateway.adopt-release-repository', fn (): array => ['repository' => $this->create('repository')])
            ->answer('gateway.resolve-adoption-target', static fn (array $input): array => ['sha' => $fixture->resolve($input['target'])])
            ->answer('gateway.inspect-adoption-origin', ['sha' => $this->sha])
            ->answer('gateway.adopt-release-environment', fn (): array => [
                'env' => $this->create('environment'), 'env_link' => 'linked', 'app_version_removed' => true, 'env_backups' => [],
            ])
            ->answer('gateway.adopt-release-storage', fn (): array => $this->create('storage') === 'existing'
                ? ['storage' => 'existing']
                : ['storage' => 'moved', 'storage_method' => 'exchange', 'storage_gap_us' => 0])
            ->answer('gateway.switch-release-adoption', static function (array $input) use ($fixture, $layout): array {
                $fixture->select($input['release']);

                return ['method' => 'exchange', 'gap_us' => 0, 'from' => $layout->currentPath(), 'to' => $layout->releasePath($input['release'])];
            })
            ->answer('gateway.restore-release-adoption', static function () use ($fixture): array {
                $fixture->inPlace();

                return ['changed' => true];
            })
            ->answer('gateway.restore-adoption-environment', ['environment' => 'restored'])
            ->answer('gateway.finish-release-adoption', ['path' => $kept]);
    }

    private function create(string $state): string
    {
        $existing = $this->created[$state] ?? false;
        $this->created[$state] = true;

        return $existing ? 'existing' : 'created';
    }
}
