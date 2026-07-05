<?php

declare(strict_types=1);

namespace App\Services\Secrets;

final readonly class LocalSecretFileAction
{
    private const string FilePrefix = 'orbit-secret.';

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function run(mixed $action, array $payload): array
    {
        if ($this->action($action) === 'stage') {
            return $this->stage($payload['content_base64'] ?? null);
        }

        return $this->remove($payload['path'] ?? null);
    }

    /**
     * @return array{path: string}
     */
    private function stage(mixed $value): array
    {
        if (! is_string($value) || $value === '') {
            throw $this->invalidContent();
        }

        $content = base64_decode($value, strict: true);

        if (! is_string($content)) {
            throw $this->invalidContent();
        }

        $path = tempnam(sys_get_temp_dir(), self::FilePrefix);

        if (! is_string($path)) {
            throw new LocalSecretFileFailure(
                errorCode: 'secret_file.stage_failed',
                message: 'Secret file could not be staged.',
            );
        }

        if (file_put_contents($path, $content) === false || ! chmod($path, 0600)) {
            @unlink($path);

            throw new LocalSecretFileFailure(
                errorCode: 'secret_file.stage_failed',
                message: 'Secret file could not be staged.',
            );
        }

        return ['path' => $path];
    }

    /**
     * @return array{path: string, removed: bool}
     */
    private function remove(mixed $value): array
    {
        $path = $this->path($value);
        $removed = ! is_file($path) || @unlink($path);

        if (! $removed) {
            throw new LocalSecretFileFailure(
                errorCode: 'secret_file.remove_failed',
                message: 'Secret file could not be removed.',
                meta: ['path' => $path],
            );
        }

        return [
            'path' => $path,
            'removed' => true,
        ];
    }

    private function action(mixed $value): string
    {
        if (is_string($value) && in_array($value, ['stage', 'remove'], true)) {
            return $value;
        }

        throw new LocalSecretFileFailure(
            errorCode: 'validation_failed',
            message: 'Secret file action is invalid.',
            meta: ['field' => 'action'],
        );
    }

    private function path(mixed $value): string
    {
        if (
            is_string($value)
            && ! str_contains($value, "\0")
            && str_starts_with(basename($value), self::FilePrefix)
            && realpath(dirname($value)) === realpath(sys_get_temp_dir())
        ) {
            return $value;
        }

        throw new LocalSecretFileFailure(
            errorCode: 'validation_failed',
            message: 'Secret file path is invalid.',
            meta: ['field' => 'path'],
        );
    }

    private function invalidContent(): LocalSecretFileFailure
    {
        return new LocalSecretFileFailure(
            errorCode: 'validation_failed',
            message: 'Secret file content is invalid.',
            meta: ['field' => 'content_base64'],
        );
    }
}
