<?php

declare(strict_types=1);

namespace App\Services\SelfUpdate;

/** The facts about this machine that `orbit self-update` acts on. */
interface SelfUpdateHost
{
    public function runningBinary(): RunningBinary;

    /** The release platform of this machine: `linux-x86_64`, `macos-arm64`, or null. */
    public function platform(): ?string;

    public function isRoot(): bool;

    /**
     * Ends the process after `orbit self-update` replaced the binary that runs it. The binary reads its own code
     * by path, and that path now holds the new binary, so the process must not load more code.
     */
    public function exitAfterReplacingItself(int $status): void;

    /** The file `orbit self-update` locks while it runs. */
    public function lockPath(): string;
}
