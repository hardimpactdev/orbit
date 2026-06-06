<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\E2E\Support\DockerTopologyBuilder;
use App\E2E\Support\DockerTopologyProvider;
use App\E2E\Support\E2EArtifactProdManifest;
use App\E2E\Support\OrbitCliBinaryBundle;
use App\Services\Runtime\OrbitCaddyContainer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

#[Signature('e2e:prepare-docker-runtime
    {--force : Build or pull the Docker runtime images}
    {--json : Output as JSON}')]
#[Description('Prepare the bare Docker runtime images used by the Docker prepared topology pipeline')]
class E2EPrepareDockerRuntimeCommand extends Command
{
    #[\Override]
    protected $hidden = true;

    public function handle(): int
    {
        $images = [
            [
                'image' => DockerTopologyBuilder::runtimeImage(),
                'action' => 'build',
                'dockerfile' => repo_path('docker/e2e/topology/Dockerfile'),
                'context' => repo_path(),
            ],
            [
                'image' => DockerTopologyProvider::gatewayImage(),
                'action' => 'build',
                'dockerfile' => repo_path('docker/orbit-gateway/Dockerfile'),
                'context' => repo_path(),
            ],
            [
                'image' => DockerTopologyProvider::webSocketRuntimeImage(),
                'action' => 'build',
                'dockerfile' => repo_path('docker/orbit-reverb/Dockerfile'),
                'context' => repo_path(),
            ],
            [
                'image' => OrbitCaddyContainer::Image,
                'action' => 'pull',
            ],
            ...array_map(
                fn (string $image): array => [
                    'image' => $image,
                    'action' => 'pull',
                ],
                DockerTopologyProvider::phpRuntimeImages(),
            ),
            [
                'image' => DockerTopologyBuilder::composerHelperImage(),
                'action' => 'pull',
            ],
        ];

        $cliBinary = E2EArtifactProdManifest::binaryArtifactPath();

        if (! (bool) $this->option('json')) {
            foreach ($images as $image) {
                $verb = $image['action'] === 'pull' ? 'pull' : 'build';
                $this->line("Docker runtime image ({$verb}): {$image['image']}");
            }

            $this->line("Orbit CLI binary artifact: {$cliBinary}");
        }

        if (! (bool) $this->option('force')) {
            return $this->renderResult(dryRun: true, artifacts: null);
        }

        $this->prepareCliBinaryArtifact($cliBinary);

        if (! (bool) $this->option('json')) {
            $this->info("Prepared Orbit CLI binary artifact {$cliBinary}.");
        }

        foreach ($images as $image) {
            $command = $image['action'] === 'pull'
                ? sprintf('docker pull %s', escapeshellarg($image['image']))
                : sprintf(
                    'docker build -f %s -t %s %s',
                    escapeshellarg($image['dockerfile']),
                    escapeshellarg($image['image']),
                    escapeshellarg($image['context']),
                );

            $result = Process::timeout(1800)->run($command);

            if ($result->successful()) {
                $verb = $image['action'] === 'pull' ? 'Pulled' : 'Built';

                if (! (bool) $this->option('json')) {
                    $this->info("{$verb} {$image['image']}.");
                }

                continue;
            }

            if (! (bool) $this->option('json')) {
                $this->error($result->output().$result->errorOutput());
            } else {
                $this->line(json_encode([
                    'error' => [
                        'code' => 'docker_runtime_prepare_failed',
                        'message' => trim($result->output().$result->errorOutput()),
                    ],
                ], JSON_THROW_ON_ERROR));
            }

            return self::FAILURE;
        }

        $artifacts = E2EArtifactProdManifest::fromPreparedDockerRuntime(
            gatewayImage: DockerTopologyProvider::gatewayImage(),
            gatewayImageId: $this->imageIdFor(DockerTopologyProvider::gatewayImage()),
            cliBinary: $cliBinary,
        );

        return $this->renderResult(dryRun: false, artifacts: $artifacts);
    }

    private function prepareCliBinaryArtifact(string $cliBinary): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        $directory = dirname($cliBinary);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true)) {
            throw new \RuntimeException("Could not create CLI binary artifact directory: {$directory}");
        }

        (new OrbitCliBinaryBundle)->buildLinuxBinaryInto($directory);
    }

    private function imageIdFor(string $image): ?string
    {
        $result = Process::timeout(120)->run(sprintf(
            'docker image inspect --format %s %s',
            escapeshellarg('{{.Id}}'),
            escapeshellarg($image),
        ));

        if (! $result->successful()) {
            return null;
        }

        $imageId = trim($result->output());

        return $imageId !== '' ? $imageId : null;
    }

    private function renderResult(bool $dryRun, ?E2EArtifactProdManifest $artifacts): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'success' => [
                    'data' => [
                        'provider' => 'docker',
                        'dry_run' => $dryRun,
                        'artifacts' => $artifacts?->toArray(),
                    ],
                ],
            ], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->line('Dry run. Pass --force to build/pull the Docker runtime images.');
        }

        return self::SUCCESS;
    }
}
