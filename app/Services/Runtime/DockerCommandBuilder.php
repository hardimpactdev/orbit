<?php

declare(strict_types=1);

namespace App\Services\Runtime;

use App\Services\Apps\AppRuntimeContainer;
use App\Services\Processes\ProcessDockerContainer;
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

    public function containerStop(string $name): string
    {
        return 'docker stop '.$this->quote($name);
    }

    public function containerRestart(string $name): string
    {
        return 'docker restart '.$this->quote($name);
    }

    public function runDetached(OrbitRuntimeContainer|OrbitCaddyContainer|AppRuntimeContainer|WorkspaceRuntimeContainer|ProcessDockerContainer $container): string
    {
        return $this->buildRunOrCreate('docker run -d', $container);
    }

    public function createIdle(ProcessDockerContainer $container): string
    {
        // `docker create` produces a container in the Created state without
        // starting it. Process runtime units honor the --start contract from
        // process:add by deferring the actual start to a separate lifecycle
        // call. App/Workspace/Caddy runtime containers stay on `docker run -d`
        // because the gateway/proxy must be running once rendered.
        return $this->buildRunOrCreate('docker create', $container);
    }

    private function buildRunOrCreate(string $prefix, OrbitRuntimeContainer|OrbitCaddyContainer|AppRuntimeContainer|WorkspaceRuntimeContainer|ProcessDockerContainer $container): string
    {
        $parts = [
            $prefix,
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

        if ($container instanceof ProcessDockerContainer) {
            $parts[] = '--workdir';
            $parts[] = $this->quote($container->workingDirectory());
            $parts[] = '--entrypoint';
            $parts[] = $this->quote('sh');
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

        if ($container instanceof ProcessDockerContainer) {
            // Process command is stored as a single shell string (e.g. "php
            // artisan queue:work --tries=3"). Run it through `sh -lc <cmd>`
            // so the in-container shell parses tokens, redirections, and
            // shell operators instead of Docker exec-ing a literal binary
            // named after the whole string.
            $parts[] = $this->quote('-lc');
            $parts[] = $this->quote($container->command());
        }

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
