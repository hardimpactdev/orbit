<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use InvalidArgumentException;
use SensitiveParameter;

final readonly class DevelopmentDeployStep
{
    public const int DefaultTimeoutSeconds = 300;

    public const int MaxTimeoutSeconds = 900;

    public const int MaxTotalTimeoutSeconds = 3600;

    public const int MaxInputs = 16;

    /**
     * @param  list<string>  $inputs  repository-relative paths whose Git object IDs let a container deployment skip the step
     */
    public function __construct(
        public string $name,
        #[SensitiveParameter]
        public string $command,
        public int $timeoutSeconds = self::DefaultTimeoutSeconds,
        public bool $required = true,
        public array $inputs = [],
    ) {
        if (preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $name) !== 1) {
            throw new InvalidArgumentException('The development deploy step name is invalid.');
        }

        if ($command === '' || strlen($command) > 16 * 1024 || str_contains($command, "\0")) {
            throw new InvalidArgumentException('The development deploy step command is invalid.');
        }

        if (preg_match('//u', $command) !== 1) {
            throw new InvalidArgumentException('The development deploy step command is invalid.');
        }

        if ($timeoutSeconds < 1 || $timeoutSeconds > self::MaxTimeoutSeconds) {
            throw new InvalidArgumentException('The development deploy step timeout is invalid.');
        }

        self::validInputs($inputs);
    }

    /**
     * At most 16 unique repository-relative paths of 1-255 characters, without a leading `/`, a `..` segment, NUL or a glob character.
     *
     * @param  array<array-key, mixed>  $inputs
     * @return list<string>
     */
    public static function validInputs(array $inputs): array
    {
        if (! array_is_list($inputs) || count($inputs) > self::MaxInputs) {
            throw new InvalidArgumentException('The development deploy step inputs are invalid.');
        }

        $valid = [];
        foreach ($inputs as $path) {
            if (! is_string($path) || $path === '' || strlen($path) > 255 || str_starts_with($path, '/')
                || strpbrk($path, '*?[') !== false || preg_match('/[\x00-\x1F\x7F\x{2028}\x{2029}]/u', $path) !== 0
                || in_array('..', explode('/', $path), true)) {
                throw new InvalidArgumentException('The development deploy step inputs are invalid.');
            }
            $valid[] = $path;
        }

        if (count(array_unique($valid)) !== count($valid)) {
            throw new InvalidArgumentException('The development deploy step inputs must be unique.');
        }

        return $valid;
    }

    /**
     * Why a request's `inputs` breaks the rule of validInputs(), or null when it holds; a request reports it under `inputs`.
     *
     * @param  array<array-key, mixed>  $inputs
     */
    public static function inputsError(array $inputs): ?string
    {
        try {
            self::validInputs($inputs);
        } catch (InvalidArgumentException $exception) {
            return $exception->getMessage();
        }

        return null;
    }

    /** @return array{name: string, command: string, timeout_seconds: int, required: bool, inputs: list<string>} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'command' => $this->command,
            'timeout_seconds' => $this->timeoutSeconds,
            'required' => $this->required,
            'inputs' => $this->inputs,
        ];
    }
}
