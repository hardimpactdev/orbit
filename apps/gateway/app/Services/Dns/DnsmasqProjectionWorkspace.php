<?php

declare(strict_types=1);

namespace App\Services\Dns;

use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

class DnsmasqProjectionWorkspace
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    /** @var array<string, string|null> */
    private array $originals = [];

    public function __construct(
        private readonly string $rootPath,
    ) {}

    /**
     * @param  array<string, string>  $projections
     * @return array<string, string>
     */
    public function stage(array $projections): array
    {
        $changed = [];

        foreach ($projections as $relativePath => $content) {
            $target = $this->rootPath.'/'.$relativePath;
            $current = File::exists($target) ? File::get($target) : null;

            if ($current === $content) {
                continue;
            }

            $this->originals[$target] = $current;
            File::ensureDirectoryExists(dirname($target), 0o700);

            $temporary = tempnam(directory: dirname($target), prefix: '.orbit-dns-');

            if ($temporary === false) {
                throw new RuntimeException("Could not stage DNS projection [{$target}].");
            }

            $this->temporaryFiles[] = $temporary;

            if (file_put_contents($temporary, $content, LOCK_EX) === false) {
                throw new RuntimeException("Could not write staged DNS projection [{$temporary}].");
            }

            $changed[$target] = $temporary;
        }

        return $changed;
    }

    public function markPublished(string $temporary): void
    {
        $this->temporaryFiles = array_values(array_diff($this->temporaryFiles, [$temporary]));
    }

    /** @return array<string, string|null> */
    public function originals(): array
    {
        return $this->originals;
    }

    public function cleanup(): void
    {
        foreach ($this->temporaryFiles as $temporary) {
            try {
                File::delete($temporary);
            } catch (Throwable $throwable) {
                report($throwable);
            }
        }
    }
}
