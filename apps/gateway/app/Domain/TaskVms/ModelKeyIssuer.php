<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

/**
 * The model route of task VMs ([Compute drivers](/reference/compute-drivers#configure-task-vms)). The extension that runs
 * the model router binds it. Router credentials cross only the declared private-material boundary.
 */
interface ModelKeyIssuer
{
    /** Existing router teardown boundary; native key changes belong to Swamp. */
    public const string Lock = 'orbit:compute:model-keys';

    /** Refuses while the router cannot issue or revoke a key, for example before its setup. */
    public function assertReady(): void;

    /** The origin that Pi on a task VM calls, such as `http://10.44.0.3:8317`. */
    public function origin(): string;

    /** The Node that owns the existing model-router credentials. */
    public function ownerNodeId(): int;

    /** @return array{management_key:string,anchor_key:string} Private material only; Swamp changes the router. */
    public function privateMaterial(string $origin, int $ownerNodeId): array;
}
