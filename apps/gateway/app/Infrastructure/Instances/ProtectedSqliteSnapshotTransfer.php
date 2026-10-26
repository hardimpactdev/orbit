<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\Instances\Sqlite\SqliteSeedPlacement;
use App\Domain\Instances\Sqlite\SqliteSnapshotTransfer;
use App\Infrastructure\AgentCommands\AgentBinaryInput;
use App\Infrastructure\AgentCommands\AgentBinaryOutput;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use RuntimeException;
use Throwable;

final readonly class ProtectedSqliteSnapshotTransfer implements SqliteSnapshotTransfer
{
    public function __construct(private AgentCommandRunner $agents) {}

    public function transfer(
        SqliteSeedPlacement $source,
        string $sourcePath,
        SqliteSeedPlacement $target,
        string $targetPath,
        int $expectedBytes,
        string $expectedDigest,
    ): void {
        $temporary = tmpfile();
        if ($temporary === false) {
            $this->fail();
        }

        try {
            $path = stream_get_meta_data($temporary)['uri'] ?? null;
            if (! is_string($path) || ! chmod($path, 0600)) {
                $this->fail();
            }
            $output = new AgentBinaryOutput($temporary, $expectedBytes);
            $this->agents->run($source->node, 'instance.read-sqlite-snapshot', [
                'path' => $sourcePath, 'bytes' => $expectedBytes, 'sha256' => $expectedDigest,
            ], output: $output);
            if ($output->bytes() !== $expectedBytes || hash_file('sha256', $path) !== $expectedDigest) {
                $this->fail();
            }
            $this->agents->run($target->node, 'instance.receive-sqlite-snapshot', [
                'path' => $targetPath, 'base' => $target->basePath, 'bytes' => $expectedBytes, 'sha256' => $expectedDigest,
            ], binaryInput: AgentBinaryInput::fromFile($path, $expectedBytes));
        } catch (Throwable) {
            $this->fail();
        } finally {
            fclose($temporary);
        }
    }

    private function fail(): never
    {
        throw new RuntimeException('The protected SQLite snapshot transfer failed safely.');
    }
}
