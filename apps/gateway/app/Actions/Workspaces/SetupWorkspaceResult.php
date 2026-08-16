<?php

declare(strict_types=1);

namespace App\Actions\Workspaces;

final readonly class SetupWorkspaceResult
{
    /**
     * @param  array{
     *     app: string,
     *     instance: string,
     *     workspace: string,
     *     node: string,
     *     path: string,
     *     url: string,
     *     action: 'set_up'|'adopted'|'converged',
     *     warnings: list<array<string, string>|array{code: string, family: string, message: string, next_command: string}>,
     *     setup_steps: array{status: string, count: int, message: string},
     *     processes: array{status: string, count: int, names: list<string>, message: string},
     *     http_probe: array{reachable: bool, status: string},
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
     *     app: string,
     *     instance: string,
     *     workspace: string,
     *     node: string,
     *     path: string,
     *     url: string,
     *     action: 'set_up'|'adopted'|'converged',
     *     warnings: list<array<string, string>|array{code: string, family: string, message: string, next_command: string}>,
     *     setup_steps: array{status: string, count: int, message: string},
     *     processes: array{status: string, count: int, names: list<string>, message: string},
     *     http_probe: array{reachable: bool, status: string},
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
     *     app: string,
     *     instance: string,
     *     workspace: string,
     *     node: string,
     *     path: string,
     *     url: string,
     *     action: 'set_up'|'adopted'|'converged',
     *     warnings: list<array<string, string>|array{code: string, family: string, message: string, next_command: string}>,
     *     setup_steps: array{status: string, count: int, message: string},
     *     processes: array{status: string, count: int, names: list<string>, message: string},
     *     http_probe: array{reachable: bool, status: string},
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
