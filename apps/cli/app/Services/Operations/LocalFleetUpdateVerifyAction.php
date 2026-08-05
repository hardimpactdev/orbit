<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Services\Version\VersionOutputParser;
use Symfony\Component\Process\Process;

final readonly class LocalFleetUpdateVerifyAction
{
    private const array CHECKS = ['cli', 'agent', 'role-images'];

    public function __construct(
        private VersionOutputParser $versionOutputParser = new VersionOutputParser,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function run(mixed $check, array $payload): array
    {
        $check = $this->check($check);

        if ($check === 'cli') {
            return $this->verifyCli($payload);
        }

        if ($check === 'agent') {
            return app(LocalFleetUpdateVerifyAgentAction::class)->run($payload);
        }

        return $this->verifyRoleImages($payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function verifyCli(array $payload): array
    {
        $binPath = LocalFleetUpdateVerifyBinPath::fromPayload($payload['bin_path'] ?? null);
        $result = $this->runProcess([$binPath, '--version', '--local', '--json'], timeout: 30);

        if (! $result->isSuccessful()) {
            throw new LocalFleetUpdateVerifyFailure(
                errorCode: 'fleet_update.cli_verification_failed',
                message: 'CLI verification failed.',
                meta: $this->processMeta($result),
            );
        }

        $version = $this->versionOutputParser->fromJsonOutput($result->getOutput());

        if ($version === null) {
            throw new LocalFleetUpdateVerifyFailure(
                errorCode: 'fleet_update.cli_verification_failed',
                message: 'CLI verification failed: version output was not structured JSON.',
                meta: $this->processMeta($result),
            );
        }

        return [
            'check' => 'cli',
            'verified' => true,
            'bin_path' => $binPath,
            'version' => $version,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function verifyRoleImages(array $payload): array
    {
        $images = $this->images($payload['images'] ?? null);

        foreach ($images as $image) {
            $result = $this->runProcess(['docker', 'image', 'inspect', $image], timeout: 60);

            if ($result->isSuccessful()) {
                continue;
            }

            throw new LocalFleetUpdateVerifyFailure(
                errorCode: 'fleet_update.required_image_missing',
                message: 'Required role image verification failed.',
                meta: [
                    'image' => $image,
                    ...$this->processMeta($result),
                ],
            );
        }

        return [
            'check' => 'role-images',
            'verified' => true,
            'images' => $images,
        ];
    }

    private function check(mixed $check): string
    {
        if (is_string($check) && in_array($check, self::CHECKS, strict: true)) {
            return $check;
        }

        throw new LocalFleetUpdateVerifyFailure(
            errorCode: 'validation_failed',
            message: 'Fleet update verification check is invalid.',
            meta: ['field' => 'check'],
        );
    }

    /**
     * @return list<string>
     */
    private function images(mixed $images): array
    {
        if (! is_array($images) || ! array_is_list($images)) {
            throw new LocalFleetUpdateVerifyFailure(
                errorCode: 'validation_failed',
                message: 'Fleet update verification images must be a list.',
                meta: ['field' => 'images'],
            );
        }

        $strings = array_values(array_filter($images, is_string(...)));

        if (count($strings) !== count($images)) {
            throw new LocalFleetUpdateVerifyFailure(
                errorCode: 'validation_failed',
                message: 'Fleet update verification images must be non-empty strings.',
                meta: ['field' => 'images'],
            );
        }

        $validated = array_map(
            trim(...),
            $strings,
        );

        if (in_array('', $validated, strict: true)) {
            throw new LocalFleetUpdateVerifyFailure(
                errorCode: 'validation_failed',
                message: 'Fleet update verification images must be non-empty strings.',
                meta: ['field' => 'images'],
            );
        }

        return array_values(array_unique($validated));
    }

    /**
     * @param  list<string>  $command
     */
    private function runProcess(array $command, int $timeout): Process
    {
        $process = new Process($command);
        $process->setTimeout($timeout);
        $process->run();

        return $process;
    }

    /**
     * @return array<string, mixed>
     */
    private function processMeta(Process $process): array
    {
        return [
            'exit_code' => $process->getExitCode(),
            'stdout' => trim($process->getOutput()),
            'stderr' => trim($process->getErrorOutput()),
        ];
    }
}
