<?php

declare(strict_types=1);
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Settings\SettingValueProtection;
use App\Infrastructure\AgentView\StreamWebSocketClient;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Models\DatabaseConnection;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Extensions\Vault\Actions\SetupVaultAction;
use Orbit\Extensions\Vault\Actions\SyncVaultAction;
use Orbit\Extensions\Vault\Data\SetupVaultData;
use Orbit\Extensions\Vault\Domain\VaultConfiguration;
use Orbit\Extensions\Vault\Domain\VaultSettings;
use Orbit\Extensions\Vault\Models\VaultItem;

// Isolated real-Gateway fixture. Private values originate and remain in PHP memory.
ini_set('zend.exception_ignore_args', '1');
ini_set('display_errors', '0');
ini_set('log_errors', '0');
umask(0077);
$phase = 'bootstrap';
register_shutdown_function(static function () use (&$phase): void {
    if (! empty($GLOBALS['vault_fixture_finished'])) {
        return;
    }
    $error = error_get_last();
    $category = 'early_exit';
    foreach (['Cannot redeclare', 'must be compatible', 'not found', 'undefined', 'Allowed memory'] as $candidate) {
        if (str_contains($error['message'] ?? '', $candidate)) {
            $category = $candidate;
            break;
        }
    }
    echo json_encode(['refused' => true, 'phase' => $phase, 'category' => $category,
        'file' => basename($error['file'] ?? ''), 'line' => $error['line'] ?? null])."\n";
});
try {
    $root = getenv('VAULT_TEST_GATEWAY_ROOT');
    $scratch = getenv('VAULT_TEST_SCRATCH');
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->useStoragePath($scratch.'/storage');
    $app->make(Kernel::class)->bootstrap();
    if (config('database.default') !== 'sqlite'
        || realpath(config('database.connections.sqlite.database')) !== realpath($scratch.'/gateway.sqlite')) {
        throw new RuntimeException('fixture_database_not_isolated');
    }
    config(['cache.default' => 'array', 'session.driver' => 'array', 'queue.default' => 'sync',
        'responsecache.enabled' => false, 'logging.default' => 'single',
        'logging.channels.single.path' => $scratch.'/gateway.log',
        'swamp.address' => '127.0.0.1', 'swamp.ca_path' => $scratch.'/cert.pem',
        'swamp.run_token' => 'isolated-auth-none', 'operation_executor.worker_node_ids' => [9]]);

    /** Substitutes only the dev endpoint; TLS framing and StartSwampRun are real. */
    final class VaultFixtureSocket implements WebSocketClient
    {
        private StreamWebSocketClient $socket;

        public function __construct()
        {
            $this->socket = new StreamWebSocketClient;
        }

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void
        {
            $this->socket->connect(new WebSocketEndpoint(
                '127.0.0.1', (int) getenv('VAULT_TEST_SWAMP_PORT'), 'swamp.orbit', '/',
                $endpoint->caPath, $endpoint->bearerToken), $timeoutSeconds);
        }

        public function send(array $message): void
        {
            $this->socket->send($message);
        }

        public function receive(float $timeoutSeconds): array
        {
            return $this->socket->receive($timeoutSeconds);
        }

        public function close(): void
        {
            $this->socket->close();
        }

        public function isConnected(): bool
        {
            return $this->socket->isConnected();
        }
    }
    $app->instance(WebSocketClient::class, new VaultFixtureSocket);

    if (PHP_SAPI === 'cli-server') {
        $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
        $request = Request::capture();
        $response = $kernel->handle($request);
        $bound = false;
        if (preg_match('#direct-operations/([0-9]+)#', $request->path(), $match)) {
            $bound = isset(Operation::find($match[1])?->state['swamp_run_id']);
        }
        file_put_contents($scratch.'/http.jsonl', json_encode(['path' => $request->path(),
            'status' => $response->getStatusCode(), 'bound' => $bound,
            'cache_control' => $response->headers->get('Cache-Control')])."\n", FILE_APPEND);
        $response->send();
        $kernel->terminate($request, $response);
        $GLOBALS['vault_fixture_finished'] = true;

        return;
    }

    function insist(bool $condition, string $code): void
    {
        if (! $condition) {
            throw new RuntimeException($code);
        }
    }
    function item(array $body, string $name): mixed
    {
        return $body[$name] ?? $body[ucfirst($name)] ?? null;
    }
    function seal(string $plain, string $key): string
    {
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($plain, 'aes-256-cbc', substr($key, 0, 32), OPENSSL_RAW_DATA, $iv);
        insist(is_string($cipher), 'fixture_crypto_failed');

        return '2.'.implode('|', array_map(base64_encode(...), [$iv, $cipher, hash_hmac('sha256', $iv.$cipher, substr($key, 32), true)]));
    }
    function api(string $method, string $path, ?array $body = null, ?string $token = null, bool $form = false): array
    {
        $base = getenv('VAULT_TEST_BACKEND_SEED_URL');
        insist(preg_match('#\Ahttp://127\.0\.0\.1:[0-9]+\z#', $base) === 1, 'fixture_backend_not_isolated');
        $headers = ['Accept: application/json', 'Content-Type: '.($form ? 'application/x-www-form-urlencoded' : 'application/json')];
        if ($token !== null) {
            $headers[] = 'Authorization: Bearer '.$token;
        }
        $curl = curl_init($base.$path);
        curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_FOLLOWLOCATION => false]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $form ? http_build_query($body) : json_encode($body, JSON_THROW_ON_ERROR));
        }
        $raw = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        insist(is_string($raw) && $status >= 200 && $status < 300, 'fixture_backend_http_'.$status);

        return $raw === '' ? [] : json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    }
    function fixtureScope(): SettingScope
    {
        return new SettingScope(SettingScopeType::Gateway);
    }
    function vaultFixtureData(): array
    {
        return json_decode(app(SettingRepository::class)->get(fixtureScope(), 'vault.fixture'), true, flags: JSON_THROW_ON_ERROR);
    }

    function vaultFixtureNeedles(): array
    {
        $private = vaultFixtureData();
        $needles = [$private['configuration']['master_password'], $private['configuration']['client_secret'], $private['configuration']['client_id'], $private['hidden']];
        foreach ($needles as $value) {
            $needles[] = urlencode($value);
            $needles[] = base64_encode($value);
            $needles[] = substr(json_encode($value, JSON_THROW_ON_ERROR), 1, -1);
        }

        return array_unique($needles);
    }
    function vaultFixtureScan(string $bytes): void
    {
        foreach (vaultFixtureNeedles() as $needle) {
            insist(! str_contains($bytes, $needle), 'fixture_private_bytes_found');
        }
    }
    $mode = $argv[1];
    $phase = $mode;
    if ($mode === 'migrate') {
        Artisan::call('migrate', ['--force' => true]);
        echo json_encode(['migrated' => true]);
    } elseif ($mode === 'init') {
        $node = new Node(['name' => 'vault-dev', 'status' => 'active', 'platform' => 'linux',
            'architecture' => 'x86_64', 'user' => posix_getpwuid(posix_getuid())['name'],
            'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '127.0.0.1']);
        $node->forceFill(['id' => 9])->save();
        NodeRole::create(['node_id' => 9, 'role' => 'gateway', 'status' => 'active']);
        $email = 'lane23-'.bin2hex(random_bytes(8)).'@example.test';
        $master = bin2hex(random_bytes(24));
        $hidden = bin2hex(random_bytes(24));
        $derived = hash_pbkdf2('sha256', $master, strtolower($email), 600000, 32, true);
        $stretched = hash_hmac('sha256', "enc\1", $derived, true).hash_hmac('sha256', "mac\1", $derived, true);
        $passwordHash = base64_encode(hash_pbkdf2('sha256', $derived, $master, 1, 32, true));
        $userKey = random_bytes(64);
        $orgKey = random_bytes(64);
        $rsa = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        insist($rsa !== false && openssl_pkey_export($rsa, $private), 'fixture_crypto_failed');
        $public = openssl_pkey_get_details($rsa)['key'];
        $der = static fn (string $pem): string => base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $pem), true);
        $phase = 'seed_register';
        api('POST', '/api/accounts/register', ['email' => $email, 'name' => 'Isolated Vault canary',
            'masterPasswordHash' => $passwordHash, 'key' => seal($userKey, $stretched),
            'keys' => ['publicKey' => base64_encode($der($public)), 'encryptedPrivateKey' => seal($der($private), $userKey)],
            'kdf' => 0, 'kdfIterations' => 600000]);
        $phase = 'seed_login';
        $login = api('POST', '/identity/connect/token', ['grant_type' => 'password', 'scope' => 'api offline_access',
            'client_id' => 'web', 'username' => $email, 'password' => $passwordHash,
            'deviceType' => 14, 'deviceIdentifier' => (string) Str::uuid(), 'deviceName' => 'Isolated fixture'], form: true);
        $token = $login['access_token'];
        $profile = api('GET', '/api/accounts/profile', token: $token);
        $phase = 'seed_api_key';
        $apiKey = api('POST', '/api/accounts/api-key', ['masterPasswordHash' => $passwordHash], $token);
        insist(openssl_public_encrypt($orgKey, $encryptedOrg, $public, OPENSSL_PKCS1_OAEP_PADDING), 'fixture_crypto_failed');
        $phase = 'seed_organization';
        $organization = api('POST', '/api/organizations', ['name' => 'Orbit dev fixture', 'billingEmail' => $email,
            'key' => '4.'.base64_encode($encryptedOrg), 'collectionName' => seal('Isolated test collection', $orgKey), 'planType' => 0], $token);
        $orgId = item($organization, 'id');
        $sync = api('GET', '/api/sync?excludeDomains=true', token: $token);
        $collection = array_values(array_filter(item($sync, 'collections'), static fn ($row): bool => item($row, 'organizationId') === $orgId))[0];
        $config = ['url' => getenv('VAULT_TEST_BACKEND_URL'), 'client_id' => 'user.'.item($profile, 'id'),
            'client_secret' => item($apiKey, 'apiKey'), 'master_password' => $master,
            'device_identifier' => (string) Str::uuid(), 'email' => $email,
            'organization_id' => $orgId, 'organization_name' => 'Orbit dev fixture',
            'collection_id' => item($collection, 'id'), 'collection_name' => 'Isolated test collection'];
        app(SettingRepository::class)->put(fixtureScope(), 'vault.fixture',
            json_encode(['configuration' => $config, 'hidden' => $hidden], JSON_THROW_ON_ERROR), SettingValueProtection::Secret);
        if (getenv('VAULT_TEST_ACTION') !== 'setup') {
            app(VaultSettings::class)->put(VaultConfiguration::fromStoredArray($config));
        }
        DatabaseConnection::create(['slug' => 'vault-dev', 'driver' => 'pgsql', 'node_id' => 9,
            'host' => '127.0.0.1', 'port' => 5432, 'database' => 'isolated', 'username' => 'fixture', 'password' => $hidden]);
        echo json_encode(['initialized' => true, 'node_id' => 9, 'isolated' => true]);
    } elseif ($mode === 'admit') {
        if (getenv('VAULT_TEST_ACTION') === 'setup') {
            $config = vaultFixtureData()['configuration'];
            insist(app(VaultSettings::class)->configuration() === null, 'fixture_setup_not_fresh');
            $operation = app(SetupVaultAction::class)->execute(
                new SetupVaultData($config['url'], $config['client_id'],
                    $config['client_secret'], $config['master_password'], $config['organization_id'], $config['collection_id']));
        } else {
            $operation = app(SyncVaultAction::class)->execute('isolated-real-http-canary');
        }
        $operation->refresh();
        echo json_encode(['id' => $operation->id, 'run_id' => $operation->state['swamp_run_id'] ?? null,
            'writer' => $operation->state['vault_writer'] ?? null]);
    } elseif ($mode === 'inspect') {
        $operation = Operation::where('kind', 'vault.'.getenv('VAULT_TEST_ACTION'))->sole();
        $stored = app(VaultSettings::class)->configuration()?->toStoredArray();
        $expected = vaultFixtureData()['configuration'];
        $matches = $stored !== null;
        foreach (['url', 'client_id', 'client_secret', 'master_password', 'organization_id', 'collection_id'] as $key) {
            $matches = $matches && ($stored[$key] ?? null) === $expected[$key];
        }
        $bytes = json_encode(['id' => $operation->id, 'status' => $operation->status->value,
            'result' => $operation->result, 'error_code' => $operation->error_code,
            'run_id' => $operation->state['swamp_run_id'] ?? null,
            'items' => VaultItem::count(), 'configured' => $stored !== null, 'configuration_matches_fixture' => $matches]);
        vaultFixtureScan($bytes);
        echo $bytes;
    } elseif ($mode === 'diagnose') {
        $operation = Operation::where('kind', 'vault.'.getenv('VAULT_TEST_ACTION'))->sole();
        $socket = app(WebSocketClient::class);
        $socket->connect(new WebSocketEndpoint('127.0.0.1', 443, 'swamp.orbit', '/', $scratch.'/cert.pem', 'isolated-auth-none'), 5);
        $id = bin2hex(random_bytes(8));
        $socket->send(['id' => $id, 'type' => 'workflow.history.get', 'payload' => ['workflowIdOrName' => $operation->state['swamp_run_id']]]);
        $until = microtime(true) + 5;
        $history = null;
        while ($socket->isConnected() && microtime(true) < $until && $history === null) {
            foreach ($socket->receive(.2) as $message) {
                if (($message['id'] ?? null) === $id) {
                    $history = $message['payload']['data'] ?? $message['payload'];
                }
            }
        }
        $socket->close();
        $bytes = json_encode(['operation_status' => $operation->status->value, 'history' => $history,
            'requests' => is_file($scratch.'/http.jsonl') ? file_get_contents($scratch.'/http.jsonl') : '']);
        vaultFixtureScan($bytes);
        echo $bytes;
    } elseif ($mode === 'scan') {
        $needles = vaultFixtureNeedles();
        $files = 0;
        $bytes = 0;
        $overlap = max(array_map(strlen(...), $needles)) - 1;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scratch, FilesystemIterator::SKIP_DOTS)) as $file) {
            insist(! $file->isLink() && $file->isFile(), 'fixture_scan_entry_invalid');
            $stream = fopen($file->getPathname(), 'rb');
            $tail = '';
            while (! feof($stream)) {
                $block = fread($stream, 1048576);
                insist(is_string($block), 'fixture_scan_read_failed');
                $raw = $tail.$block;
                foreach ($needles as $needle) {
                    insist(! str_contains($raw, $needle), 'fixture_private_bytes_found');
                }
                $bytes += strlen($block);
                $tail = $overlap > 0 ? substr($raw, -$overlap) : '';
            }
            fclose($stream);
            $files++;
        }
        echo json_encode(['whole_scratch_private_free' => true, 'files' => $files, 'bytes' => $bytes,
            'needles_in_php_memory' => true, 'production_settings_used' => false]);
    } else {
        throw new RuntimeException('fixture_mode_invalid');
    }
    echo "\n";
    $GLOBALS['vault_fixture_finished'] = true;
} catch (Throwable $error) {
    $safe = $error instanceof RuntimeException && preg_match('/\Afixture_[a-z_0-9]+\z/', $error->getMessage()) ? $error->getMessage() : 'fixture_failed';
    echo json_encode(['refused' => true, 'phase' => $phase, 'code' => $safe, 'type' => get_class($error)])."\n";
    $GLOBALS['vault_fixture_finished'] = true;
    exit(1);
}
