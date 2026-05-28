<?php

declare(strict_types=1);

use App\Services\OrbitConfigStore;
use App\Services\OrbitConfigStoreImporter;

beforeEach(function (): void {
    $this->configPath = tempnam(sys_get_temp_dir(), 'orbit-config-importer-').'.json';
    @unlink($this->configPath);

    $this->sqlitePath = tempnam(sys_get_temp_dir(), 'orbit-sqlite-').'.sqlite';
    @unlink($this->sqlitePath);
});

afterEach(function (): void {
    foreach ([$this->configPath, $this->sqlitePath] as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
});

function seedSqlite(string $path): PDO
{
    $pdo = new PDO("sqlite:{$path}");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE local_gateway_settings (id INTEGER PRIMARY KEY AUTOINCREMENT, gateway_url TEXT, gateway_wg_ip TEXT, ca_sha256 TEXT, ca_pem_path TEXT, created_at TEXT, updated_at TEXT);');
    $pdo->exec('CREATE TABLE local_node_defaults (id INTEGER PRIMARY KEY AUTOINCREMENT, default_node_name TEXT, created_at TEXT, updated_at TEXT);');

    return $pdo;
}

describe(OrbitConfigStoreImporter::class, function (): void {
    it('writes the empty skeleton and reports import when the JSON config does not exist and SQLite is also missing', function (): void {
        $store = new OrbitConfigStore(overridePath: $this->configPath);
        $importer = new OrbitConfigStoreImporter(store: $store, sqlitePath: $this->sqlitePath);

        expect($importer->importIfNeeded())->toBeTrue();
        expect(is_file($this->configPath))->toBeTrue();

        $config = $store->read();
        expect($config['active_gateway'])->toBeNull()
            ->and($config['gateways'])->toBe([])
            ->and($config['meta'])->toBe(['imported_from' => null, 'imported_at' => null]);
    });

    it('imports the latest local_gateway_settings row into gateways.default', function (): void {
        $pdo = seedSqlite($this->sqlitePath);
        $pdo->exec("INSERT INTO local_gateway_settings (gateway_url, gateway_wg_ip, ca_sha256, ca_pem_path) VALUES ('https://old.example', '10.6.0.99', 'old-sha', '/tmp/old.pem');");
        $pdo->exec("INSERT INTO local_gateway_settings (gateway_url, gateway_wg_ip, ca_sha256, ca_pem_path) VALUES ('https://10.6.0.1', '10.6.0.1', 'deadbeef', '/tmp/ca.pem');");

        $store = new OrbitConfigStore(overridePath: $this->configPath);
        $importer = new OrbitConfigStoreImporter(store: $store, sqlitePath: $this->sqlitePath);

        expect($importer->importIfNeeded())->toBeTrue();

        $config = $store->read();
        $entry = $config['gateways']['default'] ?? null;

        expect($config['active_gateway'])->toBe('default')
            ->and($entry)->not->toBeNull()
            ->and($entry['url'])->toBe('https://10.6.0.1')
            ->and($entry['wireguard_ip'])->toBe('10.6.0.1')
            ->and($entry['ca_sha256'])->toBe('deadbeef')
            ->and($entry['ca_pem_path'])->toBe('/tmp/ca.pem')
            ->and($entry['self_mode'])->toBe(OrbitConfigStore::DEFAULT_SELF_MODE)
            ->and($config['meta']['imported_from'])->toBe($this->sqlitePath);
    });

    it('imports the latest local_node_defaults row into defaults.node', function (): void {
        $pdo = seedSqlite($this->sqlitePath);
        $pdo->exec("INSERT INTO local_node_defaults (default_node_name) VALUES ('app-old');");
        $pdo->exec("INSERT INTO local_node_defaults (default_node_name) VALUES ('agent-1');");

        $store = new OrbitConfigStore(overridePath: $this->configPath);
        $importer = new OrbitConfigStoreImporter(store: $store, sqlitePath: $this->sqlitePath);

        $importer->importIfNeeded();

        $config = $store->read();
        expect($config['defaults']['node'])->toBe('agent-1');
    });

    it('is idempotent: re-running does not overwrite an existing JSON config', function (): void {
        $pdo = seedSqlite($this->sqlitePath);
        $pdo->exec("INSERT INTO local_node_defaults (default_node_name) VALUES ('agent-1');");

        $store = new OrbitConfigStore(overridePath: $this->configPath);
        $store->save([
            'active_gateway' => 'manual',
            'gateways' => ['manual' => ['url' => 'https://manual.example']],
            'defaults' => ['node' => 'manual-node', 'profile' => null],
        ]);

        $importer = new OrbitConfigStoreImporter(store: $store, sqlitePath: $this->sqlitePath);

        expect($importer->importIfNeeded())->toBeFalse();

        $config = $store->read();
        expect($config['active_gateway'])->toBe('manual')
            ->and($config['defaults']['node'])->toBe('manual-node');
    });

    it('records meta.imported_at as an ISO 8601 timestamp', function (): void {
        $pdo = seedSqlite($this->sqlitePath);
        $pdo->exec("INSERT INTO local_node_defaults (default_node_name) VALUES ('agent-1');");

        $store = new OrbitConfigStore(overridePath: $this->configPath);
        $importer = new OrbitConfigStoreImporter(store: $store, sqlitePath: $this->sqlitePath);
        $importer->importIfNeeded();

        $importedAt = $store->read()['meta']['imported_at'] ?? null;

        expect($importedAt)->toBeString();
        expect((bool) DateTimeImmutable::createFromFormat(DATE_ATOM, $importedAt))->toBeTrue();
    });
});
