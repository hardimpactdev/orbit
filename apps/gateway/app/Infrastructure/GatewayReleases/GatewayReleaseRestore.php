<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Models\GatewayRelease;
use Illuminate\Support\Facades\Config;
use PDO;
use PDOStatement;

/** Durable staged identity and complete SQLite restore verification; never restores a database. */
final readonly class GatewayReleaseRestore
{
    public function __construct(private GatewayReleaseRecovery $recovery) {}

    public function remember(GatewayRelease $record): void
    {
        $path = $this->path((string) $record->sha, $record->id);
        $mask = umask(0077);
        try {
            $file = fopen($path, 'xb');
        } finally {
            umask($mask);
        }
        if ($file === false) {
            $this->refuse('The staged recovery receipt could not be created.');
        }
        try {
            $body = json_encode($record->getAttributes(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (fwrite($file, $body) !== strlen($body) || ! fflush($file) || ! fsync($file)) {
                $this->refuse('The staged recovery receipt could not be persisted.');
            }
        } finally {
            fclose($file);
        }
    }

    public function read(string $sha, int $id): GatewayRelease
    {
        $path = $this->path($sha, $id);
        clearstatcache(true, $path);
        if (is_link($path) || ! is_file($path) || fileowner($path) !== posix_geteuid() || (fileperms($path) & 0777) !== 0600) {
            $this->refuse('The original private staged receipt is missing or unsafe.');
        }
        $values = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($values)) {
            $this->refuse('The staged receipt is unreadable.');
        }
        $record = new GatewayRelease;
        $record->setRawAttributes($values);
        if ($record->id !== $id || $record->sha !== $sha || data_get($record->phases, 'hold.record') !== $id
            || data_get($record->phases, 'hold.source') !== $record->previous_release_id
            || data_get($record->phases, 'hold.outcome') !== 'held') {
            $this->refuse('The receipt does not identify the requested staged record and source.');
        }
        $this->recovery->verify($record);

        return $record;
    }

    /** @return array<string, mixed> */
    public function abandoned(GatewayRelease $record): array
    {
        $hold = $record->phases['hold'] ?? null;
        if (! is_array($hold)) {
            $this->refuse('The staged source identity is missing.');
        }

        return [...$record->getAttributes(), 'outcome' => 'abandoned', 'retryable' => 0,
            'snapshot_path' => data_get($record->phases, 'recovery.path'),
            'error_code' => null, 'message' => 'Original snapshot restored; consumers remain stopped and automatic releases remain disabled.',
            'phases' => json_encode([...$record->phases, 'hold' => [...$hold, 'outcome' => 'abandoned'],
                'abandon' => ['outcome' => 'restored']], JSON_THROW_ON_ERROR)];
    }

    /** Must run inside the same write transaction as the record update. */
    public function assertRestored(PDO $live, GatewayRelease $receipt): void
    {
        $path = data_get($receipt->phases, 'recovery.path');
        if (! is_string($path)) {
            $this->refuse('The protected snapshot path is missing.');
        }
        $snapshot = new PDO('sqlite:file:'.rawurlencode($path).'?mode=ro&immutable=1', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $snapshot->exec('PRAGMA query_only=ON');
        if ($this->query($live, 'PRAGMA integrity_check')->fetchAll(PDO::FETCH_COLUMN) !== ['ok']
            || $this->query($live, 'PRAGMA foreign_key_check')->fetchAll() !== []) {
            $this->refuse('The restored database failed integrity or foreign-key checks. Keep the hold.');
        }
        $expected = $this->abandoned($receipt);
        $baseline = $this->query($snapshot, 'SELECT * FROM gateway_releases WHERE id = '.(int) $receipt->id)->fetch(PDO::FETCH_ASSOC);
        if (! is_array($baseline) || ($baseline['sha'] ?? null) !== $receipt->sha) {
            $this->refuse('The snapshot does not contain the original release attempt.');
        }
        if ($this->fingerprint($live, $expected, $baseline) !== $this->fingerprint($snapshot)) {
            $this->refuse('The complete database differs from the original snapshot. Keep the hold and restore that snapshot before abandon.');
        }
    }

    /**
     * Hash schema and typed rows, including rowids and duplicate rows, independent of page layout and WAL mode.
     * On a retry, only the exact completed abandonment row may replace its original snapshot row.
     *
     * @param  array<string, mixed>|null  $completed
     * @param  array<string, mixed>|null  $baseline
     */
    private function fingerprint(PDO $database, ?array $completed = null, ?array $baseline = null): string
    {
        $hash = hash_init('sha256');
        foreach (['user_version', 'application_id'] as $pragma) {
            hash_update($hash, serialize($this->query($database, 'PRAGMA '.$pragma)->fetchColumn()));
        }
        $schema = $this->query($database, 'SELECT type, name, tbl_name, sql FROM sqlite_schema ORDER BY type, name')->fetchAll(PDO::FETCH_ASSOC);
        hash_update($hash, serialize($schema));
        foreach ($schema as $object) {
            if (! is_array($object) || ! is_string($object['type'] ?? null) || ! is_string($object['name'] ?? null)
                || (! is_string($object['sql'] ?? null) && ($object['sql'] ?? null) !== null)) {
                $this->refuse('The database schema cannot be read.');
            }
            if ($object['type'] !== 'table') {
                continue;
            }
            $name = '"'.str_replace('"', '""', $object['name']).'"';
            $sql = $object['sql'] ?? null;
            if (! is_string($sql)) {
                $this->refuse('A table schema cannot be read.');
            }
            $rowid = preg_match('/\bWITHOUT\s+ROWID\b/i', $sql) !== 1;
            $query = $database->query('SELECT '.($rowid ? '_rowid_ AS "__restore_rowid", ' : '').'* FROM '.$name);
            if ($query === false) {
                $this->refuse('A database table cannot be read.');
            }
            $rows = [];
            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                $key = $row['__restore_rowid'] ?? null;
                if ($rowid) {
                    unset($row['__restore_rowid']);
                }
                if ($object['name'] === 'gateway_releases' && $completed !== null && $baseline !== null) {
                    $sorted = $row;
                    ksort($sorted);
                    $expected = $completed;
                    ksort($expected);
                    if ($sorted === $expected) {
                        $row = $baseline;
                    }
                }
                $rows[] = hash('sha256', serialize([$key, $row]));
            }
            sort($rows, SORT_STRING);
            hash_update($hash, serialize($rows));
        }

        return hash_final($hash);
    }

    private function query(PDO $database, string $sql): PDOStatement
    {
        $statement = $database->query($sql);
        if ($statement === false) {
            $this->refuse('The database cannot be read.');
        }

        return $statement;
    }

    private function path(string $sha, int $id): string
    {
        return rtrim(Config::string('orbit.home'), '/').'/recovery/stage-'.$id.'-'.$sha.'.json';
    }

    private function refuse(string $message): never
    {
        throw new GatewayReleaseException('abandon', 'gateway.release_restore_required', $message);
    }
}
