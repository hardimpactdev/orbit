<?php

declare(strict_types=1);

namespace App\Infrastructure\Ssh;

use App\Infrastructure\Processes\ProcessOutput;
use App\Infrastructure\Processes\ProtectedInput;
use Closure;
use InvalidArgumentException;

final readonly class RemoteCommand
{
    /** @var non-empty-list<string> */
    public array $arguments;

    /**
     * @param  list<string>  $arguments
     * @param  (Closure(ProcessOutput): void)|null  $output
     * @param  (Closure(): bool)|null  $cancelled
     */
    public function __construct(
        array $arguments,
        public ?string $input = null,
        public ?ProtectedInput $protectedInput = null,
        public ?int $maxOutputBytes = null,
        public ?Closure $output = null,
        public ?Closure $cancelled = null,
        public ?float $timeout = null,
        public float $terminateGraceSeconds = 0.1,
    ) {
        if ($arguments === []) {
            throw new InvalidArgumentException('A remote command needs at least one argument.');
        }

        $this->arguments = $arguments;
    }

    /** Stable program fingerprint for role image builders; protected input is never fingerprinted. */
    public function fingerprint(): ?string
    {
        if ($this->protectedInput !== null) {
            return null;
        }

        return hash('sha256', json_encode(
            ['arguments' => $this->arguments, 'input' => $this->input],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    public function shellCommand(): string
    {
        return implode(' ', array_map(escapeshellarg(...), $this->arguments));
    }
}
