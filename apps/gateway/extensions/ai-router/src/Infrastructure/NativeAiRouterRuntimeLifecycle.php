<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Infrastructure;

use App\Actions\Processes\CreateProcessAction;
use App\Actions\Processes\DestroyProcessAction;
use App\Actions\Processes\RestartProcessAction;
use App\Actions\Processes\UpdateProcessAction;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use App\Models\Process;
use Orbit\Extensions\AiRouter\Domain\AiRouterProcess;
use Orbit\Extensions\AiRouter\Domain\AiRouterRuntimeLifecycle;
use Orbit\Extensions\AiRouter\Models\AiRouterProcessSource;
use RuntimeException;
use SensitiveParameter;

final readonly class NativeAiRouterRuntimeLifecycle implements AiRouterRuntimeLifecycle
{
    public function __construct(
        private CreateProcessAction $add,
        private DestroyProcessAction $remove,
        private AiRouterSourcePublisher $source,
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
        private UpdateProcessAction $update,
    ) {}

    public function converge(
        Node $node,
        #[SensitiveParameter]
        array $environment,
    ): void {
        $script = $this->script();
        $revision = hash('sha256', $script);
        $existing = Process::query()->where('owner_type', Node::class)
            ->where('owner_id', $node->id)->where('name', AiRouterProcess::NAME)->first();
        $source = $existing === null ? null : AiRouterProcessSource::query()->firstOrNew(['process_id' => $existing->id]);
        if ($source !== null && (($source->source_revision !== null && $source->source_revision !== $revision)
            || ! $this->sourceMatches($node, $revision))) {
            // Inspect before publishing: a legacy null revision is not pending intent.
            $source->activation_pending = true;
            $source->source_revision = null;
            $source->save();
        }
        $sourceChanged = $this->publishSource($node, $script);
        if ($sourceChanged && $source !== null) {
            $source->activation_pending = true;
            $source->source_revision = null;
            $source->save();
        }
        $data = AiRouterProcess::data($node, $environment);

        try {
            $result = $this->add->execute($data);
            $process = $result['process'];
        } catch (ResourceOperationException $exception) {
            if ($exception->errorCode !== 'process.name_taken') {
                throw $exception;
            }

            $process = Process::query()->where('owner_type', Node::class)
                ->where('owner_id', $node->id)->where('name', AiRouterProcess::NAME)->sole();
            $process = $this->update->execute($process, $data);
        }

        $source ??= AiRouterProcessSource::query()->firstOrNew(['process_id' => $process->id]);

        // The source file can be current even when its Python process is still old.
        if ($existing !== null && ($source->activation_pending
            || ($source->source_revision !== null && $source->source_revision !== $revision))
            && $process->desired_state === DesiredProcessState::Running) {
            app(RestartProcessAction::class)->execute($process);
        }
        $source->source_revision = $revision;
        $source->activation_pending = false;
        $source->save();
    }

    public function remove(Node $node): void
    {
        $processes = Process::query()
            ->where('owner_type', Node::class)
            ->where('owner_id', $node->id)
            ->where('name', AiRouterProcess::NAME)
            ->get();

        foreach ($processes as $process) {
            $this->remove->execute($process, removedByOwningRole: true);
        }

        $address = $node->wireguard_ip;

        if (is_string($address) && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $this->ssh->execute($this->connection($node, $address), $this->source->removeCommand());
        }
    }

    private function sourceMatches(Node $node, string $revision): bool
    {
        $result = $this->ssh->execute($this->sourceConnection($node), $this->source->inspectCommand());

        return $result->succeeded() && str_starts_with($result->stdout, $revision.' ');
    }

    private function publishSource(Node $node, string $script): bool
    {
        $result = $this->ssh->execute($this->sourceConnection($node), $this->source->command($script));

        if (! $result->succeeded()) {
            throw new ResourceOperationException(
                'ai_router.source_publication_failed',
                "Could not install the ai-router collector on node [{$node->name}].",
                422,
            );
        }

        return trim($result->stdout) === AiRouterSourcePublisher::Published;
    }

    private function sourceConnection(Node $node): SshConnection
    {
        $address = $node->wireguard_ip;

        if (! is_string($address) || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new ResourceOperationException(
                'ai_router.node_invalid',
                "Node [{$node->name}] has no valid WireGuard IPv4 address.",
                422,
            );
        }

        return $this->connection($node, $address);
    }

    private function script(): string
    {
        $script = file_get_contents(base_path('extensions/ai-router/resources/collector.py'));

        if (! is_string($script) || $script === '') {
            throw new RuntimeException('Could not load the ai-router collector.');
        }

        return $script;
    }

    private function connection(Node $node, string $address): SshConnection
    {
        return new SshConnection(
            $address,
            $node->user,
            22,
            $this->keys->privateKeyPath(),
            $this->knownHosts->path(),
        );
    }
}
