<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\E2E\Support\DockerTopologyBuilder;
use App\E2E\Support\DockerTopologyProvider;
use App\Services\Runtime\OrbitCaddyContainer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

#[Signature('e2e:prepare-docker-runtime {--force : Build or pull the Docker runtime images}')]
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
                'dockerfile' => base_path('docker/e2e/topology/Dockerfile'),
                'context' => base_path(),
            ],
            [
                'image' => DockerTopologyProvider::runtimeSiblingImage(),
                'action' => 'build',
                'dockerfile' => base_path('docker/orbit-runtime/Dockerfile'),
                'context' => base_path(),
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

        foreach ($images as $image) {
            $verb = $image['action'] === 'pull' ? 'pull' : 'build';
            $this->line("Docker runtime image ({$verb}): {$image['image']}");
        }

        if (! (bool) $this->option('force')) {
            $this->line('Dry run. Pass --force to build/pull the Docker runtime images.');

            return self::SUCCESS;
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
                $this->info("{$verb} {$image['image']}.");

                continue;
            }

            $this->error($result->output().$result->errorOutput());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
