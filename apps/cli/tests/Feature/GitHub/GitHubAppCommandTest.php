<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use App\Services\BrowserLauncher;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\GitHub\DestroyGitHubAppRequest;
use Orbit\Sdk\Requests\GitHub\InstallGitHubAppRequest;
use Orbit\Sdk\Requests\GitHub\ShowGitHubAppRequest;
use Orbit\Sdk\Requests\GitHub\SyncGitHubAppRequest;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-github-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);

    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
        caPath: '/home/orbit/.orbit/ca/root.pem',
    ));

    $this->browser = new class extends BrowserLauncher
    {
        /** @var list<string> */
        public array $opened = [];

        #[Override]
        public function open(string $url): bool
        {
            $this->opened[] = $url;

            return true;
        }
    };
    app()->instance(BrowserLauncher::class, $this->browser);
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

describe('github:app:show', function (): void {
    it('shows the App and its installations', function (): void {
        MockClient::global([
            ShowGitHubAppRequest::class => MockResponse::make([
                'data' => github_app_payload(),
                'meta' => ['request_id' => github_request_id()],
            ]),
        ]);

        $output = github_run('github:app:show');

        expect($output)
            ->toContain('orbit-acme')
            ->toContain('4242')
            ->toContain('acme')
            ->toContain('organization')
            ->toContain('selected')
            ->toContain(github_request_id());
    });

    it('returns the App as json', function (): void {
        MockClient::global([
            ShowGitHubAppRequest::class => MockResponse::make([
                'data' => github_app_payload(),
                'meta' => ['request_id' => github_request_id()],
            ]),
        ]);

        $expected = json_encode(
            github_app_payload() + ['request_id' => github_request_id()],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );

        $this->artisan('github:app:show', ['--json' => true])->expectsOutput($expected)->assertExitCode(0);
    });

    it('renders a missing App through the shared failure boundary', function (): void {
        MockClient::global([
            ShowGitHubAppRequest::class => MockResponse::make(
                ['error' => ['code' => 'github.app_missing', 'message' => 'The Gateway has no GitHub App.']],
                404,
                ['X-Orbit-Request-Id' => github_request_id()],
            ),
        ]);

        $this
            ->artisan('github:app:show', ['--json' => true])
            ->expectsOutputToContain('"code":"github.app_missing"')
            ->assertExitCode(1);
    });
});

describe('github:app:destroy', function (): void {
    it('deletes the stored App and names the GitHub page that removes the registration', function (): void {
        $mockClient = MockClient::global([
            DestroyGitHubAppRequest::class => MockResponse::make([
                'data' => github_app_payload(['installations' => []]),
                'meta' => ['request_id' => github_request_id()],
            ]),
        ]);

        $output = github_run('github:app:destroy --yes');

        expect($output)
            ->toContain('Deleted the stored credentials of GitHub App [orbit-acme].')
            ->toContain('Delete the App registration on GitHub:')
            ->and($mockClient->getLastRequest()?->getMethod())
            ->toBe(Method::DELETE);
    });

    it('refuses without --yes when it cannot prompt, before connector io', function (): void {
        $mockClient = MockClient::global();

        $this
            ->artisan('github:app:destroy', ['--json' => true])
            ->expectsOutputToContain('"code":"input.confirmation_required"')
            ->assertExitCode(1);

        expect($mockClient->getLastPendingRequest())->toBeNull();
    });
});

describe('github:app:install', function (): void {
    it('prints the registration url without waiting as json', function (): void {
        $mockClient = MockClient::global([
            ShowGitHubAppRequest::class => MockResponse::make(
                ['error' => ['code' => 'github.app_missing', 'message' => 'The Gateway has no GitHub App.']],
                404,
                ['X-Orbit-Request-Id' => github_request_id()],
            ),
            InstallGitHubAppRequest::class => MockResponse::make([
                'data' => [
                    'step' => 'register',
                    'url' => 'https://10.44.0.1/api/v1/github/app/register?state=abc',
                    'accounts' => [],
                ],
                'meta' => ['request_id' => github_request_id()],
            ]),
        ]);

        $expected = json_encode([
            'status' => 'pending',
            'step' => 'register',
            'url' => 'https://10.44.0.1/api/v1/github/app/register?state=abc',
            'request_id' => github_request_id(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this
            ->artisan('github:app:install', ['--name' => 'orbit-acme', '--json' => true])
            ->expectsOutput($expected)
            ->assertExitCode(0);

        expect($mockClient->getLastRequest())
            ->toBeInstanceOf(InstallGitHubAppRequest::class)
            ->and($mockClient->getLastRequest()?->body()?->all())
            ->toBe('{"name":"orbit-acme"}')
            ->and($this->browser->opened)
            ->toBe([]);
    });

    it('sends the owner for an organization registration', function (): void {
        $mockClient = MockClient::global([
            ShowGitHubAppRequest::class => MockResponse::make(
                ['error' => ['code' => 'github.app_missing', 'message' => 'The Gateway has no GitHub App.']],
                404,
            ),
            InstallGitHubAppRequest::class => MockResponse::make([
                'data' => ['step' => 'register', 'url' => 'https://10.44.0.1/x', 'accounts' => []],
                'meta' => ['request_id' => github_request_id()],
            ]),
        ]);

        $this
            ->artisan('github:app:install', ['--name' => 'orbit-acme', '--owner' => 'acme', '--json' => true])
            ->assertExitCode(0);

        expect($mockClient->getLastRequest()?->body()?->all())->toBe('{"name":"orbit-acme","owner":"acme"}');
    });

    it('refuses an owner that is not a GitHub account name, before connector io', function (): void {
        $mockClient = MockClient::global();

        $this
            ->artisan('github:app:install', ['--owner' => 'acme/../evil', '--json' => true])
            ->expectsOutputToContain('"code":"github.owner_invalid"')
            ->assertExitCode(1);

        expect($mockClient->getLastPendingRequest())->toBeNull();
    });

    it('opens the install page and returns pending without polling', function (): void {
        $reads = 0;
        MockClient::global([
            ShowGitHubAppRequest::class => function () use (&$reads): MockResponse {
                $reads++;

                return MockResponse::make(['data' => github_app_payload(), 'meta' => ['request_id' => github_request_id()]]);
            },
            InstallGitHubAppRequest::class => MockResponse::make(['data' => [
                'step' => 'install', 'url' => 'https://github.com/apps/orbit-acme/installations/new', 'accounts' => ['acme'],
            ], 'meta' => ['request_id' => github_request_id()]]),
        ]);
        $output = github_run('github:app:install');
        expect($output)->toContain('Registration and installation are pending.', 'github:app:sync')
            ->not->toContain('Installed the App on');
        expect($this->browser->opened)->toBe(['https://github.com/apps/orbit-acme/installations/new']);
        expect($reads)->toBe(1);
    });
});

describe('github:app:sync', function (): void {
    it('records one native refresh and returns its Operation without following it', function (bool $json): void {
        $mock = MockClient::global([
            SyncGitHubAppRequest::class => MockResponse::make(['data' => [
                'id' => 42, 'kind' => 'github.app_sync', 'target_type' => 'node', 'target_id' => 14,
                'input' => ['app_id' => 4242], 'status' => 'queued', 'sequence' => 1,
            ], 'meta' => ['request_id' => github_request_id()]], 202),
        ]);
        $output = github_run('github:app:sync --idempotency-key=refresh-one'.($json ? ' --json' : ''));
        if ($json) {
            $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
            expect($decoded['id'])->toBe(42)->and($decoded['status'])->toBe('queued');
        } else {
            expect($output)->toContain('Operation 42: queued.', 'orbit operation:show 42');
        }
        expect($mock->getLastRequest())->toBeInstanceOf(SyncGitHubAppRequest::class);
        expect($mock->getLastRequest()->headers()->get('Idempotency-Key'))->toBe('refresh-one');
    })->with([true, false]);
});

function github_run(string $command, int $expectedStatus = 0): string
{
    $output = new BufferedOutput;
    $status = app(Kernel::class)->handle(new StringInput($command), $output);

    expect($status)->toBe($expectedStatus);

    return $output->fetch();
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function github_app_payload(array $overrides = []): array
{
    return [
        'status' => 'registered',
        'installations_status' => 'current',
        'observed_at' => '2026-10-31T08:00:00.000Z',
        'name' => 'orbit-acme',
        'slug' => 'orbit-acme',
        'app_id' => 4242,
        'owner' => 'acme',
        'url' => 'https://github.com/apps/orbit-acme',
        'settings_url' => 'https://github.com/organizations/acme/settings/apps/orbit-acme',
        'installations' => [[
            'id' => 9,
            'account' => 'acme',
            'type' => 'organization',
            'repositories' => 'selected',
            'suspended' => false,
        ]],
        ...$overrides,
    ];
}

function github_request_id(): string
{
    return '3f2b1c44-5d6e-4f70-8a91-b2c3d4e5f607';
}
