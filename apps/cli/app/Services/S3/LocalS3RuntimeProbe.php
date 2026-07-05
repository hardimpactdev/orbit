<?php

declare(strict_types=1);

namespace App\Services\S3;

use Symfony\Component\Process\Process;

final readonly class LocalS3RuntimeProbe
{
    private const string ContainerName = 'orbit-seaweedfs';

    private const string ApiPort = '8333/tcp';

    /**
     * @return array{exists: string, running: string, published_address: string, stdout: string}
     */
    public function probe(): array
    {
        $inspect = $this->run(['docker', 'container', 'inspect', self::ContainerName]);

        if (! $inspect->isSuccessful()) {
            return $this->payload('0', 'false', '');
        }

        $running = $this->inspectValue('{{.State.Running}}');
        $publishedAddress = $this->publishedAddress();

        return $this->payload('1', $running !== '' ? $running : 'false', $publishedAddress);
    }

    private function inspectValue(string $format): string
    {
        $result = $this->run(['docker', 'container', 'inspect', '--format', $format, self::ContainerName]);

        if (! $result->isSuccessful()) {
            return '';
        }

        return trim($result->getOutput());
    }

    private function publishedAddress(): string
    {
        $format =
            '{{range $p, $bindings := .NetworkSettings.Ports}}{{if eq $p "'
            .self::ApiPort
            .'"}}{{range $bindings}}{{printf "%s:%s\n" .HostIp .HostPort}}{{end}}{{end}}{{end}}';
        $result = $this->run(['docker', 'container', 'inspect', '--format', $format, self::ContainerName]);

        if (! $result->isSuccessful()) {
            return '';
        }

        $lines = preg_split('/\R/', trim($result->getOutput())) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line !== '') {
                return $line;
            }
        }

        return '';
    }

    /**
     * @return array{exists: string, running: string, published_address: string, stdout: string}
     */
    private function payload(string $exists, string $running, string $publishedAddress): array
    {
        return [
            'exists' => $exists,
            'running' => $running,
            'published_address' => $publishedAddress,
            'stdout' => "exists={$exists}\nrunning={$running}\npublished_address={$publishedAddress}\n",
        ];
    }

    /**
     * @param  list<string>  $command
     */
    private function run(array $command): Process
    {
        $process = new Process($command);
        $process->setTimeout(15);
        $process->run();

        return $process;
    }
}
