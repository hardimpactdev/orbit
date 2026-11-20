<?php

declare(strict_types=1);

namespace App\Domain\Processes\Artifacts;

use App\Domain\Shared\ResourceOperationException;
use Illuminate\Container\Attributes\Tag;

/** The artifact presets that roles declare (docs/reference/processes-and-schedules.md#upgrade-a-process). */
final readonly class ProcessArtifactPresets
{
    /** @param iterable<ProcessArtifactPreset> $presets */
    public function __construct(#[Tag(ProcessArtifactPreset::class)] private iterable $presets = []) {}

    /** @return array{repository:string,asset:string,binary:string,checksums:?string,version_args:?list<string>}|null */
    public function get(string $name): ?array
    {
        foreach ($this->presets as $preset) {
            if ($preset->name() === $name) {
                return $preset->fields();
            }
        }

        return null;
    }

    /** @return array{repository:string,asset:string,binary:string,checksums:?string,version_args:?list<string>} */
    public function require(string $name): array
    {
        return $this->get($name) ?? throw new ResourceOperationException('process.artifact_invalid',
            "No artifact preset is named [{$name}]. Known presets: ".(implode(', ', $this->names()) ?: 'none').'.', 422);
    }

    /** @return list<string> */
    public function names(): array
    {
        $names = [];
        foreach ($this->presets as $preset) {
            $names[] = $preset->name();
        }
        sort($names);

        return $names;
    }
}
