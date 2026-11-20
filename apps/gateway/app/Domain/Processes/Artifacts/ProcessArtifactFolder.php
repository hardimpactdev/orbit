<?php

declare(strict_types=1);

namespace App\Domain\Processes\Artifacts;

use App\Domain\Processes\ProcessRuntime;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Process;

/** The release folder of a systemd Process: its command must run `<folder>/current/<binary>`. */
final class ProcessArtifactFolder
{
    public static function fromProcess(Process $process, string $binary): string
    {
        if ($process->runtime !== ProcessRuntime::Systemd) {
            throw new ResourceOperationException('process.artifact_invalid', 'Only a systemd Process carries a release artifact.', 422);
        }
        $command = $process->runtime_config['command'] ?? null;
        $suffix = '/current/'.$binary;
        foreach (is_array($command) ? $command : [] as $argument) {
            if (is_string($argument) && str_starts_with($argument, '/') && str_ends_with($argument, $suffix)) {
                $folder = substr($argument, 0, -strlen($suffix));
                if ($folder !== '' && preg_match('#\A(?:/[A-Za-z0-9._+-]+)+\z#D', $folder) === 1 && ! preg_match('#/\.\.?(?:/|\z)#', $folder)) {
                    return $folder;
                }
            }
        }

        throw new ResourceOperationException('process.artifact_invalid', "The Process command must run <folder>/current/{$binary}.", 422);
    }
}
