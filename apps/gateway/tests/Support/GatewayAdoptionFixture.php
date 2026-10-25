<?php

declare(strict_types=1);

namespace Tests\Support;

/** Fixed adoption receipts and sample artifacts for the Gateway's two-phase workflow. */
final class GatewayAdoptionFixture
{
    public readonly FakeAgentCommandRunner $agents;

    public function __construct(GatewayReleaseFixture $fixture)
    {
        $layout = $fixture->layout;
        $current = $layout->currentPath();
        $sha = trim($fixture->git($current, 'rev-parse', 'HEAD'));
        $kept = $layout->basePath().'/orbit.pre-adopt-20261007T120000Z';
        $this->agents = new FakeAgentCommandRunner()
            ->answer('gateway.check-release-adoption', ['sha' => $sha])
            ->answer('gateway.adopt-release-repository', static function () use ($fixture, $layout): array {
                $exists = is_dir($layout->repositoryPath());
                if (! $exists) {
                    mkdir($layout->sharedPath(), 0700, true);
                    $fixture->git($fixture->base, 'clone', '--quiet', '--bare', $fixture->origin, $layout->repositoryPath());
                }

                return ['repository' => $exists ? 'existing' : 'created'];
            })
            ->answer('gateway.resolve-adoption-target', static fn (array $input): array => ['sha' => trim($fixture->git($fixture->origin, 'rev-parse', $input['target']))])
            ->answer('gateway.inspect-adoption-origin', ['sha' => $sha])
            ->answer('gateway.adopt-release-environment', static function () use ($layout): array {
                $exists = is_file($layout->environmentPath());
                file_put_contents($layout->environmentPath(), "APP_ENV=production\n");

                return ['env' => $exists ? 'existing' : 'created', 'env_link' => 'linked', 'app_version_removed' => true, 'env_backups' => []];
            })
            ->answer('gateway.adopt-release-storage', static function () use ($layout): array {
                if (is_dir($layout->storagePath())) {
                    return ['storage' => 'existing'];
                }
                mkdir($layout->storagePath(), 0750, true);

                return ['storage' => 'moved', 'storage_method' => 'exchange', 'storage_gap_us' => 0];
            })
            ->answer('gateway.switch-release-adoption', static function (array $input) use ($layout, $current, $kept): array {
                rename($current, $kept);
                symlink($layout->linkTarget($input['release']), $current);

                return ['method' => 'exchange', 'gap_us' => 0, 'from' => $current, 'to' => $layout->releasePath($input['release'])];
            })
            ->answer('gateway.restore-release-adoption', static function () use ($current, $kept): array {
                unlink($current);
                rename($kept, $current);

                return ['changed' => true];
            })
            ->answer('gateway.restore-adoption-environment', ['environment' => 'restored'])
            ->answer('gateway.finish-release-adoption', ['path' => $kept]);
    }
}
