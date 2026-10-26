<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\GatewayReleases\GatewayReleaseCommands;
use App\Infrastructure\GatewayReleases\GitHubArtifactWebBuild;
use App\Infrastructure\GitHub\GitHubActionsReader;
use App\Models\Node;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Feature\GitHub\GitHubTestSupport;

/** GitHub provider responses and explicit native command answers. */
final class WebArtifactFixture
{
    public const string Sha = '3c83e74ce6f241197b06b7b8e506e9496455b277';

    public const string Token = 'web-token-secret';

    public const string Storage = 'https://productionresultssa9.blob.core.windows.net/actions-results/web.zip?sig=signed-secret';

    public readonly string $web;

    public readonly FakeAgentCommandRunner $agents;

    public function __construct(public readonly GatewayReleaseLayout $layout, ?string $web = null)
    {
        $this->web = $web ?? '/srv/orbit/web-'.Str::lower(Str::random(10));
        $node = Node::query()->whereHas('roles', static fn ($query) => $query->where('role', RoleName::Gateway)->where('status', LifecycleStatus::Active))->first();
        if (! $node instanceof Node) {
            $node = Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.2', 'user' => 'orbit', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64']);
            $node->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
        }
        $this->agents = (new FakeAgentCommandRunner)
            ->answer('gateway.inspect-release-source', ['origin' => 'https://github.com/hardimpactdev/orbit.git'])
            ->answer('gateway.inspect-web-build', ['installed' => false, 'archive_limit' => 4096])
            ->answer('gateway.install-web-build', ['reused' => false])
            ->answer('gateway.publish-web-build', ['previous' => null])
            ->answer('gateway.restore-web-build', ['changed' => true])
            ->answer('gateway.remove-web-build', ['changed' => true])
            ->answer('gateway.prune-web-builds', ['changed' => true]);
    }

    public function build(): GitHubArtifactWebBuild
    {
        return new GitHubArtifactWebBuild(
            layout: $this->layout,
            commands: new GatewayReleaseCommands($this->agents),
            actions: app(GitHubActionsReader::class),
            webRoot: $this->web,
        );
    }

    /**
     * Fakes GitHub for the artifact of `$sha`. `$artifact` and `$run` change single fields of the
     * recorded shapes. `$archive` is the ZIP served from storage.
     *
     * @param  array<string, mixed>  $artifact
     * @param  array<string, mixed>  $run
     */
    public function github(string $sha = self::Sha, ?string $archive = null, array $artifact = [], array $run = [], bool $missing = false, bool $denied = false): string
    {
        $archive ??= 'downloaded artifact bytes; native tests own ZIP validation';
        $listing = self::recorded($missing ? 'artifacts-none.json' : 'artifacts.json');

        if (! $missing) {
            $listing['artifacts'][0] = [
                ...$listing['artifacts'][0],
                'name' => 'web-dist-'.$sha,
                'size_in_bytes' => strlen($archive),
                'digest' => 'sha256:'.hash('sha256', $archive),
                'workflow_run' => [...$listing['artifacts'][0]['workflow_run'], 'head_sha' => $sha],
                ...$artifact,
            ];
        }

        $recordedRun = self::recorded('run.json');

        GitHubTestSupport::storeApp();
        Http::preventStrayRequests();
        $runBody = [...$recordedRun, 'head_sha' => $sha, ...$run];
        $token = $denied
            ? ['body' => ['message' => self::Token], 'status' => 403]
            : ['body' => ['token' => self::Token, 'permissions' => ['actions' => 'read']], 'status' => 200];

        // Closures answer each request with a fresh body, so a second install reads GitHub again.
        Http::fake([
            'https://api.github.com/repos/hardimpactdev/orbit/installation' => fn () => Http::response(['id' => 7]),
            'https://api.github.com/app/installations/7/access_tokens' => fn () => Http::response($token['body'], $token['status']),
            'https://api.github.com/repos/hardimpactdev/orbit/actions/artifacts?name=web-dist-'.$sha.'&per_page=100' => fn () => Http::response($listing),
            'https://api.github.com/repos/hardimpactdev/orbit/actions/runs/37647307459' => fn () => Http::response($runBody),
            'https://api.github.com/repos/hardimpactdev/orbit/actions/artifacts/11494149862/zip' => fn () => Http::response('', 302, ['Location' => self::Storage]),
            self::Storage => fn () => Http::response($archive),
        ]);

        return $archive;
    }

    /** @return array<string, mixed> */
    public static function recorded(string $file): array
    {
        $decoded = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/GitHub/WebBuild/'.$file), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException("Fixture [{$file}] is not an object.");
        }

        return $decoded;
    }
}
