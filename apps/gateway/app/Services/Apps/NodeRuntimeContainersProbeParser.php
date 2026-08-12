<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Data\Doctor\ProbeSnapshot;
use App\Enums\Apps\NodeRuntimeContainersProbeStatus;

final readonly class NodeRuntimeContainersProbeParser
{
    public function parse(string $stdout): NodeRuntimeContainersProbe
    {
        $status = NodeRuntimeContainersProbeStatus::Error;
        $error = 'orbit-container-scan probe returned no status sentinel';
        $items = [];

        foreach (explode("\n", rtrim($stdout, characters: "\n\r")) as $rawLine) {
            $line = trim($rawLine);

            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, 'orbit-container-scan:')) {
                [$status, $error] = $this->status($line);

                continue;
            }

            $parts = explode("\t", $rawLine, limit: 2);

            if (count($parts) !== 2 || trim($parts[1]) === '') {
                continue;
            }

            $appSlug = trim($parts[1]);
            $items[$appSlug] = [
                'container_name' => trim($parts[0]),
                'app_slug' => $appSlug,
            ];
        }

        return new NodeRuntimeContainersProbe(
            status: $status,
            containers: new ProbeSnapshot(
                $status === NodeRuntimeContainersProbeStatus::Present ? $items : [],
            ),
            error: $error,
        );
    }

    /** @return array{NodeRuntimeContainersProbeStatus, string} */
    private function status(string $line): array
    {
        $marker = substr($line, strlen('orbit-container-scan:'));

        if ($marker === 'present') {
            return [NodeRuntimeContainersProbeStatus::Present, ''];
        }

        if ($marker === 'absent') {
            return [NodeRuntimeContainersProbeStatus::Absent, ''];
        }

        $error = trim(str_starts_with($marker, 'error') ? substr($marker, strlen('error')) : $marker);

        return [
            NodeRuntimeContainersProbeStatus::Error,
            $error !== '' ? $error : 'unknown Docker runtime inventory failure',
        ];
    }
}
