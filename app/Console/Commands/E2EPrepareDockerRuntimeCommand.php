<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

#[Signature('e2e:prepare-docker-runtime {--force : Build the Docker runtime image}')]
#[Description('Prepare the bare Docker runtime image used by the Docker prepared topology pipeline')]
class E2EPrepareDockerRuntimeCommand extends Command
{
    protected $hidden = true;

    public function handle(): int
    {
        $image = 'orbit-e2e-topology-runtime:current';

        $this->line("Docker runtime image: {$image}");

        if (! (bool) $this->option('force')) {
            $this->line('Dry run. Pass --force to build the Docker runtime image.');

            return self::SUCCESS;
        }

        $result = Process::timeout(1800)->run(sprintf(
            'docker build -f %s -t %s %s',
            escapeshellarg(base_path('docker/e2e/topology/Dockerfile')),
            escapeshellarg($image),
            escapeshellarg(base_path()),
        ));

        if ($result->successful()) {
            $this->info("Built {$image}.");

            return self::SUCCESS;
        }

        $this->error($result->output().$result->errorOutput());

        return self::FAILURE;
    }
}
