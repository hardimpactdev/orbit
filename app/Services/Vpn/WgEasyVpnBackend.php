<?php

declare(strict_types=1);

namespace App\Services\Vpn;

use App\Data\Vpn\VpnBackendClient;
use App\Data\Vpn\VpnPasswordRotationResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

final class WgEasyVpnBackend implements VpnBackend
{
    private string $baseUrl = 'http://127.0.0.1:51821';

    private ?string $sessionCookie = null;

    public function __construct(
        private readonly string $username = '',
        private readonly string $password = '',
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            username: (string) config('services.wg_easy.username', config('orbit.wg_easy.username', 'orbit')),
            password: (string) config('services.wg_easy.password', config('orbit.wg_easy.password', '')),
        );
    }

    public function clients(?string $totp = null): array
    {
        $response = Http::withHeaders(['Cookie' => $this->authenticate($totp)])
            ->timeout(10)
            ->get("{$this->baseUrl}/api/client");

        if (! $response->successful()) {
            throw new RuntimeException('VPN backend unavailable.');
        }

        return array_map(static fn (mixed $client): VpnBackendClient => new VpnBackendClient(
            id: (string) ($client['id'] ?? ''),
            name: (string) ($client['name'] ?? ''),
            address: (string) ($client['ipv4Address'] ?? $client['address'] ?? ''),
            enabled: (bool) ($client['enabled'] ?? true),
            latestHandshakeAt: is_string($client['latestHandshakeAt'] ?? null) ? $client['latestHandshakeAt'] : null,
        ), array_values((array) $response->json()));
    }

    public function createClient(string $name, bool $includeConfig = false, ?string $totp = null): VpnBackendClient
    {
        if ($this->findClient($name, $totp) instanceof VpnBackendClient) {
            throw new RuntimeException('VPN client name is already in use.');
        }

        $response = Http::withHeaders(['Cookie' => $this->authenticate($totp)])
            ->timeout(10)
            ->post("{$this->baseUrl}/api/client", [
                'name' => $name,
                'expiresAt' => null,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('VPN backend unavailable.');
        }

        $client = null;

        foreach ($this->clients($totp) as $candidate) {
            if ($candidate->name === $name) {
                $client = $candidate;

                break;
            }
        }

        if ($client === null) {
            throw new RuntimeException('VPN client creation could not be verified.');
        }

        return new VpnBackendClient(
            id: $client->id,
            name: $client->name,
            address: $client->address,
            enabled: $client->enabled,
            latestHandshakeAt: $client->latestHandshakeAt,
            config: $includeConfig ? $this->clientConfig($client->id, $totp) : null,
        );
    }

    public function enableClient(string $name, ?string $totp = null): VpnBackendClient
    {
        return $this->toggleClient($name, 'enable', true, $totp);
    }

    public function disableClient(string $name, ?string $totp = null): VpnBackendClient
    {
        return $this->toggleClient($name, 'disable', false, $totp);
    }

    public function removeClient(string $name, ?string $totp = null): void
    {
        $client = $this->findClient($name, $totp) ?? throw new RuntimeException('VPN client does not exist.');

        $response = Http::withHeaders(['Cookie' => $this->authenticate($totp)])
            ->timeout(10)
            ->delete("{$this->baseUrl}/api/client/{$client->id}");

        if (! $response->successful()) {
            throw new RuntimeException('VPN backend unavailable.');
        }
    }

    public function changeWebUiPassword(string $password, ?string $totp = null): VpnPasswordRotationResult
    {
        $this->clients($totp);
        $hash = $this->argon2Hash($password);

        $this->updatePasswordHash($hash);
        $this->rotateSessionSecret();
        $this->updateEnvironmentPassword($password);

        return new VpnPasswordRotationResult(passwordChanged: true, sessionsInvalidated: true);
    }

    private function toggleClient(string $name, string $endpoint, bool $enabled, ?string $totp): VpnBackendClient
    {
        $client = $this->findClient($name, $totp) ?? throw new RuntimeException('VPN client does not exist.');

        if ($client->enabled === $enabled) {
            return $client;
        }

        $response = Http::withHeaders(['Cookie' => $this->authenticate($totp)])
            ->timeout(10)
            ->post("{$this->baseUrl}/api/client/{$client->id}/{$endpoint}");

        if (! $response->successful()) {
            throw new RuntimeException('VPN backend unavailable.');
        }

        return $this->findClient($name, $totp);
    }

    private function findClient(string $name, ?string $totp): ?VpnBackendClient
    {
        foreach ($this->clients($totp) as $client) {
            if ($client->name === $name) {
                return $client;
            }
        }

        return null;
    }

    private function clientConfig(string $clientId, ?string $totp): string
    {
        $response = Http::withHeaders(['Cookie' => $this->authenticate($totp)])
            ->timeout(10)
            ->get("{$this->baseUrl}/api/client/{$clientId}/configuration");

        if (! $response->successful()) {
            throw new RuntimeException('VPN client config could not be generated.');
        }

        return $response->body();
    }

    private function authenticate(?string $totp = null): string
    {
        if ($this->sessionCookie !== null) {
            return $this->sessionCookie;
        }

        if ($this->password === '') {
            throw new RuntimeException('VPN backend credentials are not configured.');
        }

        $payload = [
            'username' => $this->username,
            'password' => $this->password,
            'remember' => true,
        ];

        if ($totp !== null && $totp !== '') {
            $payload['totpCode'] = $totp;
        }

        $response = Http::asJson()
            ->timeout(10)
            ->post("{$this->baseUrl}/api/session", $payload);

        if (! $response->successful()) {
            throw new RuntimeException('VPN backend authentication failed.');
        }

        $cookie = (string) $response->header('Set-Cookie');

        if (preg_match('/wg-easy=([^;]+)/', $cookie, $matches) !== 1) {
            throw new RuntimeException('VPN backend authentication failed.');
        }

        return $this->sessionCookie = "wg-easy={$matches[1]}";
    }

    private function argon2Hash(string $password): string
    {
        $script = <<<'JS'
const chunks = [];
process.stdin.on('data', chunk => chunks.push(chunk));
process.stdin.on('end', async () => {
  try {
    const argon2 = require('argon2');
    console.log(await argon2.hash(Buffer.concat(chunks).toString()));
  } catch (error) {
    console.error(error.message);
    process.exit(1);
  }
});
JS;

        $result = Process::timeout(15)
            ->input($password)
            ->run('docker exec -i -w /app/server wg-easy node -e '.escapeshellarg($script));

        if (! $result->successful()) {
            throw new RuntimeException('Could not hash VPN web UI password.');
        }

        $hash = trim($result->output());

        if ($hash === '') {
            throw new RuntimeException('Could not hash VPN web UI password.');
        }

        return $hash;
    }

    private function updatePasswordHash(string $hash): void
    {
        $sql = "UPDATE users_table SET password = '".str_replace("'", "''", $hash)."';";

        $this->runSql($sql, 'Could not update VPN web UI password.');
    }

    private function rotateSessionSecret(): void
    {
        $secret = Str::random(128);
        $sql = "UPDATE general_table SET session_password = '".str_replace("'", "''", $secret)."';";

        $this->runSql($sql, 'Could not rotate VPN web UI sessions.');
    }

    private function runSql(string $sql, string $failureMessage): void
    {
        $databasePath = (string) config(
            'services.wg_easy.database_path',
            ($_SERVER['HOME'] ?? '/home/orbit').'/.wg-easy/wg-easy.db',
        );

        $result = Process::timeout(5)
            ->input($sql)
            ->run('if command -v sudo >/dev/null 2>&1; then sudo sqlite3 '.escapeshellarg($databasePath).'; else sqlite3 '.escapeshellarg($databasePath).'; fi');

        if (! $result->successful()) {
            throw new RuntimeException($failureMessage);
        }
    }

    private function updateEnvironmentPassword(string $password): void
    {
        $envPath = base_path('.env');

        if (! file_exists($envPath)) {
            return;
        }

        $contents = (string) file_get_contents($envPath);
        $quoted = '"'.addcslashes($password, '"\\').'"';

        if (str_contains($contents, 'WG_EASY_PASSWORD=')) {
            $contents = (string) preg_replace('/^WG_EASY_PASSWORD=.*/m', "WG_EASY_PASSWORD={$quoted}", $contents);
        } else {
            $contents = rtrim($contents)."\nWG_EASY_PASSWORD={$quoted}\n";
        }

        file_put_contents($envPath, $contents);
    }
}
