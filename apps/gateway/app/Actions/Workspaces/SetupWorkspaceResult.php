<?php

declare(strict_types=1);

namespace App\Actions\Workspaces;

final readonly class SetupWorkspaceResult
{
    /**
     * @param  array{
     *     result: array{action: 'set_up'|'adopted'|'converged'},
     *     workspace: array<string, mixed>,
     *     meta: array<string, mixed>,
     * }|null  $data
     * @param  array{code: string, message: string, meta: array<string, mixed>}|null  $failure
     * @param  list<string>  $completedSteps
     */
    private function __construct(
        private bool $successful,
        private ?array $data,
        private ?array $failure,
        private array $completedSteps,
    ) {}

    /**
     * @param  array{
     *     result: array{action: 'set_up'|'adopted'|'converged'},
     *     workspace: array<string, mixed>,
     *     meta: array<string, mixed>,
     * }  $data
     * @param  list<string>  $completedSteps
     */
    public static function success(array $data, array $completedSteps): self
    {
        return new self(true, $data, null, $completedSteps);
    }

    /**
     * @param  array{code: string, message: string, meta: array<string, mixed>}  $failure
     * @param  list<string>  $completedSteps
     */
    public static function failed(array $failure, array $completedSteps): self
    {
        return new self(false, null, $failure, $completedSteps);
    }

    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    /**
     * @return array{
     *     result: array{action: 'set_up'|'adopted'|'converged'},
     *     workspace: array<string, mixed>,
     *     meta: array<string, mixed>,
     * }
     */
    public function data(): array
    {
        if ($this->data === null) {
            throw new \LogicException('A failed workspace setup result has no success data.');
        }

        return $this->data;
    }

    /** @return array{code: string, message: string, meta: array<string, mixed>}|null */
    public function failure(): ?array
    {
        return $this->failure;
    }

    /** @return list<string> */
    public function completedSteps(): array
    {
        return $this->completedSteps;
    }
}
