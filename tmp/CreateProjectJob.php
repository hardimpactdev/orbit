<?php

namespace App\Jobs;

use App\Events\ProjectProvisionStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Process;

class CreateProjectJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 1;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 900; // 15 minutes

    public function __construct(
        public string $slug,
        public ?string $template = null,
        public ?string $cloneUrl = null,
        public bool $fork = false,
        public string $visibility = 'private',
        public ?string $name = null,
        public ?string $phpVersion = null,
        public ?string $dbDriver = null,
        public ?string $sessionDriver = null,
        public ?string $cacheDriver = null,
        public ?string $queueDriver = null,
        public ?string $path = null,
        public bool $minimal = false,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $launchpad = $this->findLaunchpadBinary();

        try {
            $this->broadcast('provisioning');

            // Build the provision command
            $command = "{$launchpad} provision ".escapeshellarg($this->slug);

            if ($this->template) {
                $command .= ' --template='.escapeshellarg($this->template);
            }

            if ($this->cloneUrl) {
                $command .= ' --clone-url='.escapeshellarg($this->cloneUrl);
            }

            if ($this->fork) {
                $command .= ' --fork';
            }

            $command .= ' --visibility='.escapeshellarg($this->visibility);

            if ($this->name && $this->name !== $this->slug) {
                $command .= ' --name='.escapeshellarg($this->name);
            }

            if ($this->phpVersion) {
                $command .= ' --php='.escapeshellarg($this->phpVersion);
            }

            if ($this->dbDriver) {
                $command .= ' --db-driver='.escapeshellarg($this->dbDriver);
            }

            if ($this->sessionDriver) {
                $command .= ' --session-driver='.escapeshellarg($this->sessionDriver);
            }

            if ($this->cacheDriver) {
                $command .= ' --cache-driver='.escapeshellarg($this->cacheDriver);
            }

            if ($this->queueDriver) {
                $command .= ' --queue-driver='.escapeshellarg($this->queueDriver);
            }

            if ($this->minimal) {
                $command .= ' --minimal';
            }

            // Run the provision command
            // The provision command handles all the steps and broadcasts status updates
            $result = Process::timeout($this->timeout - 60)
                ->env([
                    'HOME' => $_SERVER['HOME'] ?? '/home/launchpad',
                    'PATH' => ($_SERVER['HOME'] ?? '/home/launchpad').'/.local/bin:'.
                              ($_SERVER['HOME'] ?? '/home/launchpad').'/.config/herd-lite/bin:'.
                              '/usr/local/bin:/usr/bin:/bin',
                ])
                ->run($command);

            if (! $result->successful()) {
                throw new \RuntimeException(
                    "Provision command failed:\n".$result->errorOutput()
                );
            }

            // The provision command broadcasts 'ready' when done, but we ensure it here too
            $this->broadcast('ready');

        } catch (\Exception $e) {
            $this->broadcast('failed', $e->getMessage());
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(?\Throwable $exception): void
    {
        $this->broadcast('failed', $exception?->getMessage() ?? 'Unknown error');
    }

    /**
     * Find the launchpad binary.
     */
    private function findLaunchpadBinary(): string
    {
        $home = $_SERVER['HOME'] ?? '/home/launchpad';
        $paths = [
            "{$home}/.local/bin/launchpad",
            '/usr/local/bin/launchpad',
            "{$home}/projects/launchpad-cli/launchpad",
        ];

        foreach ($paths as $path) {
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        return "{$home}/.local/bin/launchpad"; // Default
    }

    /**
     * Broadcast a status update.
     */
    private function broadcast(string $status, ?string $error = null): void
    {
        event(new ProjectProvisionStatus(
            slug: $this->slug,
            status: $status,
            error: $error,
        ));
    }
}
