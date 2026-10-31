<?php

declare(strict_types=1);

namespace App\Infrastructure\GitHub;

use App\Domain\GitHub\GitHubAppCredentials;
use App\Support\ValidatedData;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use SensitiveParameter;

/** App-key management talks only to GitHub, inside the Gateway. */
final readonly class HttpGitHubAppManagement
{
    public function register(#[SensitiveParameter] string $code): GitHubAppCredentials
    {
        $response = $this->request()->post('/app-manifests/'.rawurlencode($code).'/conversions');
        if (! $response->successful() || strlen($response->body()) > 2097152) {
            throw new RuntimeException('github.registration_failed');
        }
        $data = $response->json();
        if (! is_array($data) || Validator::make($data, [
            'id' => ['required', 'integer', 'min:1'],
            'slug' => ['required', 'string', 'max:100', 'regex:/\A[A-Za-z0-9_-]+\z/D'],
            'name' => ['required', 'string', 'max:255'],
            'owner.login' => ['required', 'string', 'max:100'],
            'owner.type' => ['required', 'in:User,Organization'],
            'html_url' => ['required', 'url', 'starts_with:https://github.com/apps/'],
            'pem' => ['required', 'string', 'min:64', 'max:32768'],
        ])->fails() || ! is_int($data['id'])) {
            throw new RuntimeException('github.registration_failed');
        }
        $pem = ValidatedData::string($data['pem']);
        $key = openssl_pkey_get_private($pem);
        if ($key === false || (openssl_pkey_get_details($key)['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
            throw new RuntimeException('github.registration_failed');
        }
        $owner = ValidatedData::object($data['owner']);

        return new GitHubAppCredentials($data['id'], ValidatedData::string($data['slug']), ValidatedData::string($data['name']),
            ValidatedData::string($owner['login']), $owner['type'] === 'Organization' ? 'organization' : 'user',
            ValidatedData::string($data['html_url']), $pem);
    }

    /** @return array{app_id:int,observed_at:string,installations:list<array{id:int,account:string,type:string,repositories:string,suspended:bool}>} */
    public function sync(#[SensitiveParameter] GitHubAppCredentials $credentials): array
    {
        $request = $this->request()->withToken(GitHubAppJwt::sign($credentials, now()->getTimestamp()));
        $installations = [];
        $seen = [];
        for ($page = 1; $page <= 10; $page++) {
            $response = $request->get('/app/installations', ['per_page' => 100, 'page' => $page]);
            $rows = $response->json();
            if (! $response->successful() || strlen($response->body()) > 2097152 || ! is_array($rows) || ! array_is_list($rows) || count($rows) > 100) {
                throw new RuntimeException('github.app_sync_failed');
            }
            foreach ($rows as $row) {
                if (! is_array($row) || Validator::make($row, [
                    'id' => ['required', 'integer', 'min:1'], 'app_id' => ['required', 'integer'],
                    'account.login' => ['required', 'string', 'max:100'], 'account.type' => ['required', 'in:User,Organization'],
                    'repository_selection' => ['required', 'in:all,selected'], 'suspended_at' => ['present', 'nullable', 'string'],
                ])->fails() || ! is_int($row['id']) || ($row['app_id'] ?? null) !== $credentials->appId || isset($seen[$row['id']])) {
                    throw new RuntimeException('github.app_sync_failed');
                }
                $seen[$row['id']] = true;
                $account = ValidatedData::object($row['account']);
                $installations[] = ['id' => $row['id'], 'account' => ValidatedData::string($account['login']),
                    'type' => $account['type'] === 'Organization' ? 'organization' : 'user',
                    'repositories' => ValidatedData::string($row['repository_selection']), 'suspended' => $row['suspended_at'] !== null];
            }
            if (count($rows) < 100) {
                return ['app_id' => $credentials->appId, 'observed_at' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'), 'installations' => $installations];
            }
        }
        throw new RuntimeException('github.app_sync_failed');
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl('https://api.github.com')->acceptJson()->withoutRedirecting()->timeout(15)->connectTimeout(5)
            ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28', 'User-Agent' => 'orbit-gateway']);
    }
}
