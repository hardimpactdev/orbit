<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Infrastructure\Fleet\Footprint\TmpfilesFootprintArtifact;

/**
 * The runtime folders and files of the app-dev role that a served development site needs: Caddy writes the hibernation log of
 * each site and reads its awake marker. The role add of a Node applies them, and so does the runtime phase of a task VM, which
 * records the role at enrolment.
 */
final readonly class AppDevRuntimeFootprint
{
    /** @return list<array{path:string,owner:string,group:string,mode:string}> */
    public static function directories(): array
    {
        return [
            ['path' => '/dev/shm/orbit/hibernation', 'owner' => 'root', 'group' => 'caddy', 'mode' => '0755'],
            ['path' => '/data/caddy/orbit/hibernation', 'owner' => 'root', 'group' => 'caddy', 'mode' => '2775'],
        ];
    }

    /** @return list<array{path:string,content:string,owner:string,group:string,mode:string}> */
    public static function files(): array
    {
        return [['path' => TmpfilesFootprintArtifact::Path, 'content' => TmpfilesFootprintArtifact::Rule, 'owner' => 'root', 'group' => 'root', 'mode' => '0644']];
    }
}
