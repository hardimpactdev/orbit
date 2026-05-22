<?php

declare(strict_types=1);

namespace App\Services\Runtime;

use App\Services\Apps\AppRuntimeContainer;
use App\Services\Workspaces\WorkspaceRuntimeContainer;

class DockerCommandBuilder
{
    public function networkInspect(string $network): string
    {
        return 'docker network inspect '.$this->quote($network);
    }

    public function networkCreate(string $network): string
    {
        return implode(' ', [
            'docker network create',
            '--label',
            $this->quote('orbit.managed=true'),
            '--label',
            $this->quote('orbit.network.kind=runtime'),
            $this->quote($network),
        ]);
    }

    public function containerInspect(string $name): string
    {
        return 'docker container inspect --format '.$this->quote('{{json .}}').' '.$this->quote($name);
    }

    public function containerRemove(string $name): string
    {
        return 'docker rm -f '.$this->quote($name);
    }

    public function containerStart(string $name): string
    {
        return 'docker start '.$this->quote($name);
    }

    public function runDetached(OrbitRuntimeContainer|OrbitCaddyContainer|AppRuntimeContainer|WorkspaceRuntimeContainer $container): string
    {
        $parts = [
            'docker run -d',
            '--pull',
            $this->quote('never'),
            '--name',
            $this->quote($container->name()),
            '--restart',
            $this->quote($container->restartPolicy()),
            '--network',
            $this->quote($container->network()),
        ];

        if ($container instanceof OrbitCaddyContainer) {
            foreach ($container->publishedPorts() as $port) {
                $parts[] = '--publish';
                $parts[] = $this->quote($port);
            }

            foreach ($container->extraHosts() as $host => $address) {
                $parts[] = '--add-host';
                $parts[] = $this->quote("{$host}:{$address}");
            }
        }

        foreach ($container->networkAliases() as $alias) {
            $parts[] = '--network-alias';
            $parts[] = $this->quote($alias);
        }

        foreach ($container->labels() as $key => $value) {
            $parts[] = '--label';
            $parts[] = $this->quote("{$key}={$value}");
        }

        foreach ($container->environment() as $key => $value) {
            $parts[] = '--env';
            $parts[] = $this->quote("{$key}={$value}");
        }

        foreach ($container->mounts() as $mount) {
            $parts[] = '--mount';
            $parts[] = $this->quote($this->mountSpec($mount));
        }

        $parts[] = $this->quote($container->image());

        return implode(' ', $parts);
    }

    /**
     * @param  array{source: string, target: string, read_only: bool}  $mount
     */
    private function mountSpec(array $mount): string
    {
        $fields = [
            'type=bind',
            $this->mountField('source', $mount['source']),
            $this->mountField('target', $mount['target']),
        ];

        if ($mount['read_only']) {
            $fields[] = 'readonly';
        }

        return implode(',', $fields);
    }

    private function mountField(string $key, string $value): string
    {
        $field = "{$key}={$value}";

        if (str_contains($field, ',') || str_contains($field, '"')) {
            return '"'.str_replace('"', '""', $field).'"';
        }

        return $field;
    }

    private function quote(string $value): string
    {
        return escapeshellarg($value);
    }
}
