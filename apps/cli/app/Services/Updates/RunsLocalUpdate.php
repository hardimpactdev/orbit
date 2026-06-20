<?php

declare(strict_types=1);

namespace App\Services\Updates;

interface RunsLocalUpdate
{
    /**
     * Download, verify, and install the prebuilt CLI binary in one call, then
     * relink and verify the host launcher. Composes {@see self::downloadBinary()}
     * and {@see self::replaceBinary()}.
     *
     * Retained as the single-call entry point for the durable `update:all`
     * runner, which treats the local CLI update as one preflight step.
     *
     * @return array{successful: bool, exit_code: int, output: string}
     */
    public function pullSource(): array;

    /**
     * Download the prebuilt CLI binary for this host OS/arch to a staged path
     * away from the running binary, make it executable, and verify it responds
     * to `--version`. The resolved version is read back from the verify output.
     *
     * On success, `staged_path` is the verified, staged binary and `version` is
     * the version it reported. On failure, `staged_path`/`version` are `null`.
     *
     * @return array{successful: bool, exit_code: int, output: string, staged_path: string|null, version: string|null}
     */
    public function downloadBinary(): array;

    /**
     * Move the verified staged binary to
     * `<install-root>/bin/orbit-binary-<version>`, relink the host launcher to
     * it, verify the relinked launcher responds to `--version`, and write the
     * install metadata. When the versioned binary is already present locally the
     * move is skipped (`skipped` is `true`) and the staged copy is discarded; the
     * relink and verify still run so the launcher always points at the version.
     * The `skipped` flag is a low-level move optimization only — the public
     * `orbit update` `Replacing binary` step always settles as `Done` on success.
     *
     * @return array{successful: bool, exit_code: int, output: string, skipped: bool}
     */
    public function replaceBinary(string $stagedPath, string $version): array;

    /**
     * Run `orbit doctor` in verify mode for the local node through the relinked
     * launcher and return the reported issue count. The doctor step is
     * non-fatal: any error resolving the count yields `null` (unknown) and never
     * fails the update.
     *
     * @return array{issues: int|null}
     */
    public function runDoctor(): array;

    /**
     * Install Composer dependencies inside orbit-gateway.
     *
     * Vestigial: `orbit update` no longer installs gateway dependencies. Retained
     * for the durable `update:all` runner contract and its tests.
     *
     * @return array{successful: bool, exit_code: int, output: string}
     */
    public function installDependencies(): array;

    /**
     * Run Orbit database migrations inside orbit-gateway.
     *
     * Vestigial: `orbit update` no longer runs gateway migrations. Retained for
     * the durable `update:all` runner contract and its tests.
     *
     * @return array{successful: bool, exit_code: int, output: string}
     */
    public function runMigrations(): array;
}
