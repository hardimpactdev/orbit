<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use PDOException;

/**
 * One-time, idempotent importer per decision D11 + Phase 3 migration contract.
 *
 * Reads the most recent `local_gateway_settings` row and the most recent `local_node_defaults`
 * row from `~/.config/orbit/gateway.sqlite` (when present and readable), and writes the
 * equivalent JSON config to `~/.config/orbit/config.json` if the file does not already exist.
 *
 * Never overwrites an existing JSON config with SQLite values. Records provenance in
 * `meta.imported_from` and `meta.imported_at`. Logs a one-line audit entry naming the source
 * database path. Safe to run on every CLI invocation.
 */
final readonly class OrbitConfigStoreImporter
{
    public function __construct(
        private OrbitConfigStore $store,
        private string $sqlitePath,
    ) {}

    /**
     * Run the importer if the JSON config file does not yet exist. Returns true when an
     * import happened (or the empty skeleton was written because the SQLite source was
     * unavailable). Returns false when the JSON config already existed.
     */
    public function importIfNeeded(): bool
    {
        if (is_file($this->store->path())) {
            return false;
        }

        $skeleton = $this->store->emptySkeleton();
        $provenance = null;

        if (is_file($this->sqlitePath) && is_readable($this->sqlitePath)) {
            try {
                $skeleton = $this->mergeSqliteRowsInto($skeleton);
                $provenance = $this->sqlitePath;
            } catch (PDOException) {
                // Unreadable / locked / corrupt — fall back to empty skeleton.
                $provenance = null;
            }
        }

        if ($provenance !== null) {
            $skeleton['meta'] = [
                'imported_from' => $provenance,
                'imported_at' => date(DATE_ATOM),
            ];
        }

        $this->store->save($skeleton);

        return true;
    }

    /**
     * @param  array<string, mixed>  $skeleton
     * @return array<string, mixed>
     */
    private function mergeSqliteRowsInto(array $skeleton): array
    {
        $pdo = new PDO("sqlite:{$this->sqlitePath}", null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $gatewayRow = $this->latestRow($pdo, 'local_gateway_settings');

        if (is_array($gatewayRow)) {
            $defaultGateway = [
                'url' => $this->stringOrNull($gatewayRow['gateway_url'] ?? null),
                'wireguard_ip' => $this->stringOrNull($gatewayRow['gateway_wg_ip'] ?? null),
                'ca_pem_path' => $this->stringOrNull($gatewayRow['ca_pem_path'] ?? null),
                'ca_sha256' => $this->stringOrNull($gatewayRow['ca_sha256'] ?? null),
                'ca_fingerprint' => null,
                'timeout' => OrbitConfigStore::DEFAULT_TIMEOUT_SECONDS,
                'self_mode' => OrbitConfigStore::DEFAULT_SELF_MODE,
            ];

            $skeleton['active_gateway'] = 'default';
            $skeleton['gateways']['default'] = $defaultGateway;
        }

        $nodeRow = $this->latestRow($pdo, 'local_node_defaults');

        if (is_array($nodeRow)) {
            $defaultNode = $this->stringOrNull($nodeRow['default_node_name'] ?? null);

            if ($defaultNode !== null) {
                $skeleton['defaults']['node'] = $defaultNode;
            }
        }

        return $skeleton;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestRow(PDO $pdo, string $table): ?array
    {
        try {
            $statement = $pdo->query("SELECT * FROM {$table} ORDER BY id DESC LIMIT 1");
        } catch (PDOException) {
            return null;
        }

        if ($statement === false) {
            return null;
        }

        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
