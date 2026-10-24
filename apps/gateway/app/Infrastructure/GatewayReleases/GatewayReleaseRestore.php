<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Models\GatewayRelease;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Config;
use PDO;
use PDOStatement;

/** Durable staged identity and complete SQLite restore verification; never restores a database. */
final readonly class GatewayReleaseRestore
{
    public function __construct(private GatewayReleaseRecovery $recovery, private GatewayReleaseCommands $commands) {}

    public function remember(GatewayRelease $record): void
    {
        if (! is_string($record->sha)) {
            $this->refuse('The staged target SHA is missing.');
        }
        $this->commands->writeReceipt(rtrim(Config::string('orbit.home'), '/'), $record->id, $record->sha, $record->getAttributes());
    }

    public function read(string $sha, int $id): GatewayRelease
    {
        $values = $this->commands->readReceipt(rtrim(Config::string('orbit.home'), '/'), $id, $sha);
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
        $baseline = $this->query($snapshot, 'SELECT * FROM gateway_releases WHERE id = '.(int) $receipt->id)->fetch(PDO::FETCH_ASSOC);
        if (! is_array($baseline) || ($baseline['sha'] ?? null) !== $receipt->sha) {
            $this->refuse('The snapshot does not contain the original release attempt.');
        }
        $projection = $this->projection($snapshot, 'gateway_releases');
        $baseline = $this->row($this->query($snapshot, 'SELECT '.$projection.' FROM gateway_releases WHERE id = '.(int) $receipt->id));
        $completed = $this->completedRow($snapshot, $receipt, $projection);
        if ($this->fingerprint($live, $completed, $baseline) !== $this->fingerprint($snapshot)) {
            $this->refuse('The complete database differs from the original snapshot. Keep the hold and restore that snapshot before abandon.');
        }
    }

    /**
     * Apply the same Laravel bindings and SQLite column affinities as the real completion write,
     * in an isolated memory table. Never normalize a row by its lossy PHP attribute values.
     *
     * @return list<mixed>
     */
    private function completedRow(PDO $snapshot, GatewayRelease $receipt, string $projection): array
    {
        $schema = $this->query($snapshot, "SELECT sql FROM sqlite_schema WHERE type = 'table' AND name = 'gateway_releases'")->fetchColumn();
        $encoding = $this->query($snapshot, 'PRAGMA encoding')->fetchColumn();
        if (! is_string($schema) || ! is_string($encoding)) {
            $this->refuse('The original release schema cannot be read.');
        }
        $expected = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $expected->exec('PRAGMA encoding='.$expected->quote($encoding));
        $expected->exec($schema);
        new SQLiteConnection($expected)->table('gateway_releases')->insert($this->abandoned($receipt));

        return $this->row($this->query($expected, 'SELECT '.$projection.' FROM gateway_releases'))
            ?? $this->refuse('The completed release identity cannot be verified.');
    }

    /**
     * Hash schema and typed rows, including rowids and duplicate rows, independent of page layout and WAL mode.
     * On a retry, only the exact typed completed abandonment row may replace its original snapshot row.
     *
     * @param  list<mixed>|null  $completed
     * @param  list<mixed>|null  $baseline
     */
    private function fingerprint(PDO $database, ?array $completed = null, ?array $baseline = null): string
    {
        $hash = hash_init('sha256');
        foreach (['user_version', 'application_id', 'encoding'] as $pragma) {
            hash_update($hash, serialize($this->query($database, 'PRAGMA '.$pragma)->fetchColumn()));
        }
        $schema = $this->query($database, 'SELECT type, name, tbl_name, sql FROM sqlite_schema ORDER BY type, name')->fetchAll(PDO::FETCH_ASSOC);
        hash_update($hash, serialize($schema));
        foreach ($schema as $object) {
            if (! is_array($object) || ! is_string($object['type'] ?? null) || ! is_string($object['name'] ?? null)) {
                $this->refuse('The database schema cannot be read.');
            }
            if ($object['type'] !== 'table') {
                continue;
            }
            $projection = $this->projection($database, $object['name']);
            $query = $this->query($database, 'SELECT '.$projection.' FROM main.'.$this->identifier($object['name']));
            $rows = [];
            while (($row = $this->row($query)) !== null) {
                if ($object['name'] === 'gateway_releases' && $completed !== null && $baseline !== null && $row === $completed) {
                    $row = $baseline;
                }
                $rows[] = hash('sha256', serialize($row));
            }
            sort($rows, SORT_STRING);
            hash_update($hash, serialize($rows));
        }

        return hash_final($hash);
    }

    /** Positional projections cannot collide with real column names or generated aliases. */
    private function projection(PDO $database, string $table): string
    {
        $name = $this->identifier($table);
        $columns = $this->query($database, 'PRAGMA main.table_xinfo('.$name.')')->fetchAll(PDO::FETCH_ASSOC);
        $names = [];
        foreach ($columns as $column) {
            if (! is_array($column) || ! is_string($column['name'] ?? null)) {
                $this->refuse('A database column cannot be read.');
            }
            $names[] = $column['name'];
        }
        $metadata = $this->query($database, 'PRAGMA main.table_list('.$name.')')->fetch(PDO::FETCH_ASSOC);
        if ($names === [] || ! is_array($metadata) || ($metadata['type'] ?? null) !== 'table'
            || ! in_array($metadata['wr'] ?? null, [0, 1], true)) {
            $this->refuse('A table cannot be verified. Virtual tables require separately reviewed recovery; keep the hold.');
        }
        if ($metadata['wr'] === 0) {
            $rowids = array_values(array_diff(['_rowid_', 'rowid', 'oid'], array_map(strtolower(...), $names)));
            if ($rowids === []) {
                $this->refuse('All intrinsic rowid names are shadowed. Keep the hold and use separately reviewed recovery.');
            }
            array_unshift($names, $rowids[0]);
        }
        $expressions = [];
        foreach ($names as $column) {
            $value = $this->identifier($column);
            $expressions[] = 'typeof('.$value.')';
            // Hex preserves all text/blob bytes, including embedded NULs and invalid UTF-8.
            // Decimal integer text avoids a float conversion; real values remain binary64.
            $expressions[] = "CASE typeof($value) WHEN 'text' THEN hex(CAST($value AS BLOB)) WHEN 'blob' THEN hex($value) WHEN 'integer' THEN CAST($value AS TEXT) ELSE $value END";
        }

        return implode(', ', $expressions);
    }

    /** @return list<mixed>|null */
    private function row(PDOStatement $query): ?array
    {
        $row = $query->fetch(PDO::FETCH_NUM);
        if ($row === false) {
            return null;
        }
        for ($index = 0; $index < count($row); $index += 2) {
            $type = $row[$index];
            $value = $row[$index + 1];
            if ($type === 'real' && is_float($value)) {
                // Avoid serialize_precision/decimal formatting losing distinct SQLite doubles.
                $row[$index + 1] = bin2hex(pack('E', $value));
            } elseif (! (in_array($type, ['text', 'blob', 'integer'], true) && is_string($value))
                && ! ($type === 'null' && $value === null)) {
                $this->refuse('A SQLite storage class or exact value cannot be verified. Keep the hold.');
            }
        }

        return $row;
    }

    private function identifier(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }

    private function query(PDO $database, string $sql): PDOStatement
    {
        $statement = $database->query($sql);
        if ($statement === false) {
            $this->refuse('The database cannot be read.');
        }

        return $statement;
    }

    private function refuse(string $message): never
    {
        throw new GatewayReleaseException('abandon', 'gateway.release_restore_required', $message);
    }
}
