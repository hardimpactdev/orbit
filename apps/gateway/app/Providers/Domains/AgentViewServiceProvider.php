<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Actions\Broadcasting\PresenceChannelSigner;
use App\Domain\AgentView\AgentStateView;
use App\Domain\AgentView\AgentViewConverger;
use App\Domain\WebSocket\WebSocketCredentialManager;
use App\Infrastructure\AgentView\AgentViewSubscriber;
use App\Infrastructure\AgentView\CacheAgentStateView;
use App\Infrastructure\AgentView\NativeAgentViewConverger;
use App\Infrastructure\AgentView\ProcessAgentViewPublisher;
use App\Infrastructure\AgentView\StreamWebSocketClient;
use App\Infrastructure\Gateway\GatewayApplicationPath;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use Illuminate\Cache\CacheManager;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

final class AgentViewServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        AgentStateView::class => CacheAgentStateView::class,
        AgentViewConverger::class => NativeAgentViewConverger::class,
    ];

    public function register(): void
    {
        $this->app->singleton(
            CacheAgentStateView::class,
            static fn ($app): CacheAgentStateView => new CacheAgentStateView(
                $app->make(CacheManager::class)->build(CacheAgentStateView::storeConfiguration(Config::string('orbit.home'))),
            ),
        );
        $this->app->bind(
            AgentViewSubscriber::class,
            static fn ($app): AgentViewSubscriber => new AgentViewSubscriber(
                socket: new StreamWebSocketClient,
                credentials: $app->make(WebSocketCredentialManager::class),
                view: $app->make(CacheAgentStateView::class),
                signer: $app->make(PresenceChannelSigner::class),
                log: $app->make(LoggerInterface::class),
                caPath: rtrim(string: Config::string('orbit.home'), characters: '/').'/ca/root.pem',
                commit: static function () use ($app): ?string {
                    $result = $app->make(ProcessRunner::class)->run(
                        // The stable path follows a release switch; base_path() stays on the release this process runs.
                        new ProcessInvocation(['git', '-C', GatewayApplicationPath::resolve(), 'rev-parse', 'HEAD'], timeout: 10.0),
                    );

                    return $result->succeeded() ? trim($result->stdout) : null;
                },
                clock: CacheAgentStateView::now(...),
                sleep: static function (float $seconds): void {
                    usleep((int) ($seconds * 1_000_000));
                },
                publisher: new ProcessAgentViewPublisher(
                    command: [PHP_BINARY, base_path('artisan'), 'orbit:agent-view-publish'],
                    log: $app->make(LoggerInterface::class),
                    clock: CacheAgentStateView::now(...),
                    workingDirectory: base_path(),
                ),
            ),
        );
    }
}
