<?php

declare(strict_types=1);

namespace App\Domain\Processes\Artifacts;

use App\Domain\Processes\ProcessRuntime;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Process;

/**
 * The release folder of a systemd Process: its executable must be `<folder>/current/<binary>`. Only the executable counts, after
 * `/usr/bin/env` and its NAME=VALUE arguments; a later argument never names the folder. No other Process on the Node may name the folder.
 */
final class ProcessArtifactFolder
{
    public static function fromProcess(Process $process, string $binary): string
    {
        if ($process->runtime !== ProcessRuntime::Systemd) {
            throw new ResourceOperationException('process.artifact_invalid', 'Only a systemd Process carries a release artifact.', 422);
        }
        $suffix = '/current/'.$binary;
        $executable = self::executable($process);
        if ($executable === null || ! str_ends_with($executable, $suffix)) {
            throw new ResourceOperationException('process.artifact_invalid', "The Process command must run <folder>/current/{$binary}.", 422);
        }
        $folder = substr($executable, 0, -strlen($suffix));
        if ($folder === '' || preg_match('#\A(?:/[A-Za-z0-9._+-]+)+\z#D', $folder) !== 1 || preg_match('#/\.\.?(?:/|\z)#', $folder) === 1) {
            throw new ResourceOperationException('process.artifact_invalid', "The Process command must run <folder>/current/{$binary}.", 422);
        }
        self::assertExclusive($process, $folder);

        return $folder;
    }

    /** The program a systemd command runs: its first argument, or the first one after `env` and its NAME=VALUE arguments. */
    public static function executable(Process $process): ?string
    {
        $command = $process->runtime_config['command'] ?? null;
        $arguments = is_array($command) ? array_values($command) : [];
        $index = 0;
        if (in_array($arguments[0] ?? null, ['/usr/bin/env', '/bin/env'], true)) {
            $index = 1;
            while (is_string($arguments[$index] ?? null) && preg_match('/\A[A-Za-z_][A-Za-z0-9_]*=/', $arguments[$index]) === 1) {
                $index++;
            }
        }
        $executable = $arguments[$index] ?? null;

        return is_string($executable) && str_starts_with($executable, '/') ? $executable : null;
    }

    /** Pruning assumes the folder's releases belong to one Process: refuse a folder that another Process on the Node names in its command or works in. */
    private static function assertExclusive(Process $process, string $folder): void
    {
        $nodeId = self::nodeId($process);
        $others = Process::query()->whereKeyNot($process->id)->with('owner')->get()
            ->filter(static fn (Process $other): bool => self::nodeId($other) === $nodeId);
        foreach ($others as $other) {
            $command = $other->runtime_config['command'] ?? null;
            $names = array_any(is_array($command) ? $command : [], static fn (mixed $argument): bool => is_string($argument)
                && ($argument === $folder || str_contains($argument, $folder.'/')));
            $directory = $other->working_directory;
            if ($names || (is_string($directory) && ($directory === $folder || str_starts_with($directory, $folder.'/')))) {
                throw new ResourceOperationException('process.artifact_invalid', "Process [{$other->name}] on the same Node also uses {$folder}.", 422);
            }
        }
    }

    private static function nodeId(Process $process): int
    {
        $owner = $process->owner;

        return $owner instanceof Node ? $owner->id : $owner->node_id;
    }
}
