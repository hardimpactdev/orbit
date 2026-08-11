<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Data\Doctor\DoctorRestoreProbe;

/**
 * Mutable-looking value object for one multi-pass restore loop position.
 */
final readonly class DoctorRestorePassState
{
    /**
     * @param  list<array<string, mixed>>  $actions
     */
    public function __construct(
        public DoctorRestoreProbe $probe,
        public array $actions,
        public int $passes,
        public ?string $previousSignature,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $passActions
     */
    public function afterApply(array $passActions, string $signature): self
    {
        return new self(
            probe: $this->probe,
            actions: [...$this->actions, ...$passActions],
            passes: $this->passes + 1,
            previousSignature: $signature,
        );
    }

    public function withProbe(DoctorRestoreProbe $probe): self
    {
        return new self(
            probe: $probe,
            actions: $this->actions,
            passes: $this->passes,
            previousSignature: $this->previousSignature,
        );
    }

    /**
     * @return 'no_progress'|'max_passes'|null
     */
    public function stopReasonBeforeApply(string $signature, int $maxPasses): ?string
    {
        if ($signature === $this->previousSignature) {
            return 'no_progress';
        }

        if ($this->passes >= $maxPasses) {
            return 'max_passes';
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $actions
     */
    public static function actionsMadeProgress(array $actions): bool
    {
        return array_any(
            $actions,
            static fn (array $action): bool => in_array(
                $action['status'] ?? null,
                ['completed', 'created', 'updated'],
                true,
            ),
        );
    }

    /**
     * @param  'converged'|'no_progress'|'max_passes'|'no_restorable'  $stopReason
     * @return array{
     *     probe: DoctorRestoreProbe,
     *     actions: list<array<string, mixed>>,
     *     passes: int,
     *     stop_reason: 'converged'|'no_progress'|'max_passes'|'no_restorable'
     * }
     */
    public function toResult(string $stopReason): array
    {
        return [
            'probe' => $this->probe,
            'actions' => $this->actions,
            'passes' => $this->passes,
            'stop_reason' => $stopReason,
        ];
    }
}
